<?php

namespace App\Services\Engineering\CodeIntelligence;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;
use Throwable;

/**
 * GOD-DEBULK FASE C — the `parse*` family extracted VERBATIM from
 * EngineeringCodeIntelligenceService: raw file content -> scanned symbol arrays
 * (PHP/routes/JS/Markdown) and dependency/relation graphs (regex + php-parser AST).
 * Stateless: builds symbols through SymbolExtractor, resolves modules through
 * ModuleExtractor, and carries the parse-exclusive line/route/import helpers that
 * had no other caller in the façade.
 */
class ParseExtractor
{
    public function __construct(
        private readonly SymbolExtractor $symbolExtractor,
        private readonly ModuleExtractor $moduleExtractor,
    ) {}

    public function parseFileSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => $this->parsePhpSymbols($relativePath, $content, $moduleSlug),
            'ts', 'tsx', 'js', 'jsx' => $this->parseJavascriptSymbols($relativePath, $content, $moduleSlug),
            'md' => $this->parseMarkdownSymbols($relativePath, $content, $moduleSlug),
            default => [],
        };
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    public function parseFileRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => $this->parsePhpRelations($relativePath, $content, $moduleSlug),
            'ts', 'tsx', 'js', 'jsx' => $this->parseJavascriptRelations($relativePath, $content, $moduleSlug),
            default => ['dependencies' => [], 'symbol_references' => [], 'test_targets' => []],
        };
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    public function parsePhpRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        $astRelations = $this->parsePhpAstRelations($relativePath, $content, $moduleSlug);
        if ($astRelations !== null) {
            return $astRelations;
        }

        $dependencies = [];
        $references = [];
        $testTargets = [];

        foreach ($this->lineMatches($content, '/^use\s+([^;]+);/') as $match) {
            $class = trim($match['matches'][1]);
            $targetModule = $this->moduleExtractor->moduleSlugForClass($class);
            $dependencies[] = [
                'kind' => 'php_use',
                'from_module' => $moduleSlug,
                'to_module' => $targetModule,
                'symbol' => $class,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
            $references[] = [
                'kind' => 'php_use',
                'symbol' => $class,
                'target_module' => $targetModule,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
        }

        foreach ($this->lineMatches($content, '/([A-Za-z_][A-Za-z0-9_\\\\]+)::class/') as $match) {
            $class = trim($match['matches'][1]);
            $targetModule = $this->moduleExtractor->moduleSlugForClass($class);
            $references[] = [
                'kind' => 'class_constant',
                'symbol' => $class,
                'target_module' => $targetModule,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
        }

        if (str_starts_with($relativePath, 'tests/')) {
            foreach ($this->lineMatches($content, '/\b(App\\\\[A-Za-z0-9_\\\\]+|[A-Z][A-Za-z0-9_]+(?:Service|Controller|Command|Model))\b/') as $match) {
                $symbol = $match['matches'][1];
                $testTargets[] = [
                    'kind' => 'test_symbol_reference',
                    'symbol' => $symbol,
                    'target_module' => str_contains($symbol, '\\') ? $this->moduleExtractor->moduleSlugForClass($symbol) : $this->moduleExtractor->moduleSlugForShortName($symbol),
                    'test_path' => $relativePath,
                    'line' => $match['line'],
                ];
            }
        }

        return [
            'dependencies' => $dependencies,
            'symbol_references' => $references,
            'test_targets' => $testTargets,
        ];
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}|null
     */
    private function parsePhpAstRelations(string $relativePath, string $content, string $moduleSlug): ?array
    {
        if (! class_exists(ParserFactory::class)) {
            return null;
        }

        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $statements = $parser->parse($content);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($statements)) {
            return null;
        }

        return $this->parsePhpAstStatementRelations($relativePath, $statements, $moduleSlug);
    }

    /**
     * @param  array<int,Node>  $statements
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function parsePhpAstStatementRelations(string $relativePath, array $statements, string $moduleSlug): array
    {
        $dependencies = [];
        $references = [];
        $testTargets = [];

        foreach ($statements as $statement) {
            $namespace = '';
            $body = [$statement];
            if ($statement instanceof Namespace_) {
                $namespace = $statement->name instanceof Name ? $this->phpAstName($statement->name) : '';
                $body = $statement->stmts;
            }

            $imports = [];
            foreach ($body as $node) {
                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $class = ltrim($this->phpAstName($use->name), '\\');
                        if ($class === '') {
                            continue;
                        }

                        $shortName = $use->alias instanceof Node\Identifier
                            ? $use->alias->toString()
                            : Str::afterLast($class, '\\');
                        $imports[$shortName] = $class;
                        $targetModule = $this->moduleExtractor->moduleSlugForClass($class);
                        $dependencies[] = [
                            'kind' => 'php_use_ast',
                            'from_module' => $moduleSlug,
                            'to_module' => $targetModule,
                            'symbol' => $class,
                            'file_path' => $relativePath,
                            'line' => $use->getStartLine(),
                        ];
                        $references[] = [
                            'kind' => 'php_use_ast',
                            'symbol' => $class,
                            'target_module' => $targetModule,
                            'file_path' => $relativePath,
                            'line' => $use->getStartLine(),
                        ];
                    }

                    continue;
                }

                foreach ($this->phpAstConstructorInjectionClasses($node, $namespace, $imports) as $injection) {
                    $references[] = [
                        'kind' => 'php_constructor_injection',
                        'symbol' => $injection['class'],
                        'target_module' => $this->moduleExtractor->moduleSlugForClass($injection['class']),
                        'file_path' => $relativePath,
                        'line' => $injection['line'],
                    ];
                }

                foreach ($this->phpAstClassConstFetches($node) as $fetch) {
                    if (! $fetch->class instanceof Name) {
                        continue;
                    }

                    $class = $this->resolvePhpAstClassName($this->phpAstName($fetch->class), $namespace, $imports);
                    if ($class === '') {
                        continue;
                    }

                    $targetModule = $this->moduleExtractor->moduleSlugForClass($class);
                    $references[] = [
                        'kind' => 'class_constant_ast',
                        'symbol' => $class,
                        'target_module' => $targetModule,
                        'file_path' => $relativePath,
                        'line' => $fetch->getStartLine(),
                    ];
                    if (str_starts_with($relativePath, 'tests/')) {
                        $testTargets[] = [
                            'kind' => 'test_symbol_reference_ast',
                            'symbol' => $class,
                            'target_module' => $targetModule,
                            'test_path' => $relativePath,
                            'line' => $fetch->getStartLine(),
                        ];
                    }
                }
            }
        }

        return [
            'dependencies' => $dependencies,
            'symbol_references' => $references,
            'test_targets' => $testTargets,
        ];
    }

    /**
     * @return array<int,ClassConstFetch>
     */
    private function phpAstClassConstFetches(Node $node): array
    {
        $matches = [];
        if ($node instanceof ClassConstFetch) {
            $matches[] = $node;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                array_push($matches, ...$this->phpAstClassConstFetches($value));
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        array_push($matches, ...$this->phpAstClassConstFetches($child));
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Constructor-injection type-hints (Obra #12 lesson: same-namespace DI has no
     * `use` and no `::class`, so it was invisible to the relation graph).
     *
     * @param  array<string,string>  $imports
     * @return array<int,array{class:string,line:int}>
     */
    private function phpAstConstructorInjectionClasses(Node $node, string $namespace, array $imports): array
    {
        if (! $node instanceof ClassLike) {
            return [];
        }

        $constructor = $node->getMethod('__construct');
        if ($constructor === null) {
            return [];
        }

        $injections = [];
        foreach ($constructor->params as $param) {
            $type = $param->type;
            if ($type instanceof Node\NullableType) {
                $type = $type->type;
            }
            if (! $type instanceof Name) {
                // ponytail: scalars (Identifier) and union/intersection hints skipped; add if DI unions ever appear
                continue;
            }

            $class = $this->resolvePhpAstClassName($this->phpAstName($type), $namespace, $imports);
            if ($class === '') {
                continue;
            }

            $injections[] = ['class' => $class, 'line' => $param->getStartLine()];
        }

        return $injections;
    }

    /**
     * @param  array<string,string>  $imports
     */
    private function resolvePhpAstClassName(string $class, string $namespace, array $imports): string
    {
        $class = ltrim($class, '\\');
        if ($class === '' || in_array(strtolower($class), ['self', 'static', 'parent'], true)) {
            return '';
        }

        $head = Str::before($class, '\\');
        if (isset($imports[$head])) {
            $tail = Str::after($class, $head);

            return $imports[$head].$tail;
        }

        if (str_contains($class, '\\')) {
            return $class;
        }

        return $namespace !== '' ? $namespace.'\\'.$class : $class;
    }

    private function phpAstName(Name $name): string
    {
        if (method_exists($name, 'toCodeString')) {
            return $name->toCodeString();
        }

        return $name->toString();
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function parseJavascriptRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        $dependencies = [];
        foreach ($this->lineMatches($content, '/\bimport\s+(?:.+?\s+from\s+)?[\'"]([^\'"]+)[\'"]/') as $match) {
            $import = trim($match['matches'][1]);
            $dependencies[] = [
                'kind' => 'js_import',
                'from_module' => $moduleSlug,
                'to_module' => str_starts_with($import, '.') ? $this->moduleExtractor->moduleForPath($this->normalizeRelativeImport($relativePath, $import))['slug'] : 'external_package',
                'symbol' => $import,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
        }

        return [
            'dependencies' => $dependencies,
            'symbol_references' => $dependencies,
            'test_targets' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parsePhpSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        $namespace = preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)
            ? trim($namespaceMatch[1])
            : null;
        $className = null;
        // Anchor to a real declaration at line-start (optionally preceded by final/abstract/
        // readonly modifiers). A bare /\b(class|interface|trait|enum)\s+\w+/ also matched the
        // same keywords appearing as prose inside docblocks ("This class turns them...",
        // "mixing interface with..."), extracting a phantom symbol ("turns", "with") and
        // dropping the true class — which made evidence symbol refs silently unresolvable.
        // ALL declarations are extracted (preg_match_all): a multi-class file (test + stub
        // helper above it) used to index only the FIRST class, leaving the real test class
        // invisible to evidence/test resolvers (ex.: AtlasSwarmConductorServiceTest atrás do
        // StubAdmlForSwarm) — pipeline ficava building com o teste existindo.
        $classSpans = [];
        if (preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($classMatches as $classMatch) {
                $fqn = $namespace ? $namespace.'\\'.$classMatch[2][0] : $classMatch[2][0];
                $line = $this->lineForOffset($content, $classMatch[0][1]);
                $className ??= $fqn;
                $classSpans[] = ['line' => $line, 'fqn' => $fqn];
                $symbols[] = $this->symbolExtractor->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => $classMatch[1][0],
                    'symbol_name' => $fqn,
                    'file_path' => $relativePath,
                    'line_start' => $line,
                    'language' => 'php',
                    'signature' => trim($classMatch[0][0]),
                    'namespace' => $namespace,
                    'metadata' => [
                        'short_name' => $classMatch[2][0],
                        'classification' => $this->classClassification($relativePath, $classMatch[2][0]),
                    ],
                ]);
            }
        }

        if (preg_match('/protected\s+\$signature\s*=\s*([\'"])(.*?)\1/s', $content, $signatureMatch, PREG_OFFSET_CAPTURE)) {
            $signature = trim(preg_replace('/\s+/', ' ', $signatureMatch[2][0]) ?? $signatureMatch[2][0]);
            $command = strtok($signature, ' ') ?: $signature;
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'cli_command',
                'symbol_name' => $command,
                'file_path' => $relativePath,
                'line_start' => $this->lineForOffset($content, $signatureMatch[0][1]),
                'language' => 'php',
                'signature' => $signature,
                'parent_symbol' => $className,
                'metadata' => [
                    'command_signature' => $signature,
                ],
            ]);
        }

        foreach ($this->lineMatches($content, '/\b(public|protected|private)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)$/') as $match) {
            // Attribute the method to the nearest class declared ABOVE it (multi-class
            // files: a method after the second declaration belongs to that class).
            $owner = $className;
            foreach ($classSpans as $span) {
                if ($span['line'] <= $match['line']) {
                    $owner = $span['fqn'];
                }
            }
            $methodName = $owner ? $owner.'::'.$match['matches'][2] : $match['matches'][2];
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => str_starts_with($relativePath, 'tests/') && str_starts_with($match['matches'][2], 'test_') ? 'test_method' : 'method',
                'symbol_name' => $methodName,
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'php',
                'signature' => trim($match['text']),
                'namespace' => $namespace,
                'parent_symbol' => $className,
                'visibility' => $match['matches'][1],
                'metadata' => [
                    'method' => $match['matches'][2],
                ],
            ]);
        }

        foreach ($this->parseRouteSymbols($relativePath, $content, $moduleSlug) as $routeSymbol) {
            $symbols[] = $routeSymbol;
        }

        foreach ($this->lineMatches($content, '/Schema::(create|table)\(\s*[\'"]([^\'"]+)[\'"]/') as $match) {
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'migration_table',
                'symbol_name' => $match['matches'][1].':'.$match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'php',
                'signature' => trim($match['text']),
                'metadata' => [
                    'operation' => $match['matches'][1],
                    'table' => $match['matches'][2],
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseRouteSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        if (! str_starts_with($relativePath, 'routes/')) {
            return [];
        }

        $symbols = [];
        $prefixStack = [];
        $depth = 0;
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $prefixStack = array_values(array_filter(
                $prefixStack,
                fn (array $prefix): bool => $depth >= (int) $prefix['depth'],
            ));
            if (preg_match('/Route::prefix\(\s*[\'"]([^\'"]+)[\'"]\s*\)->group/', $line, $prefixMatch)) {
                $prefixStack[] = [
                    'prefix' => trim($prefixMatch[1], '/'),
                    'depth' => $depth + max(1, substr_count($line, '{')),
                ];
            }

            $prefix = trim(implode('/', array_column($prefixStack, 'prefix')), '/');
            if (preg_match('/Route::(get|post|put|patch|delete|options|any)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+)\);?/', $line, $routeMatch)) {
                $uri = $this->joinRoute($prefix, $routeMatch[2]);
                $verb = strtoupper($routeMatch[1]);
                $target = trim($routeMatch[3]);
                $symbols[] = $this->symbolExtractor->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => 'route',
                    'symbol_name' => $verb.' '.$uri,
                    'file_path' => $relativePath,
                    'line_start' => $lineNumber,
                    'language' => 'php',
                    'signature' => trim($line),
                    'metadata' => array_merge([
                        'http_method' => $verb,
                        'uri' => $uri,
                        'target' => $target,
                    ], $this->routeTarget($target)),
                ]);
            }
            if (preg_match('/Route::apiResource\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^,\)]+)/', $line, $resourceMatch)) {
                $uri = $this->joinRoute($prefix, $resourceMatch[1]);
                $symbols[] = $this->symbolExtractor->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => 'api_resource',
                    'symbol_name' => 'RESOURCE '.$uri,
                    'file_path' => $relativePath,
                    'line_start' => $lineNumber,
                    'language' => 'php',
                    'signature' => trim($line),
                    'metadata' => [
                        'uri' => $uri,
                        'controller' => trim($resourceMatch[2]),
                    ],
                ]);
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseJavascriptSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        foreach ($this->lineMatches($content, '/\b(export\s+)?(default\s+)?(async\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/') as $match) {
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'function',
                'symbol_name' => $match['matches'][4],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => $this->languageForPath($relativePath),
                'signature' => trim($match['text']),
                'metadata' => [
                    'exported' => trim((string) $match['matches'][1]) !== '',
                    'default' => trim((string) $match['matches'][2]) !== '',
                ],
            ]);
        }
        foreach ($this->lineMatches($content, '/\bexport\s+(interface|type|class|const)\s+([A-Za-z_][A-Za-z0-9_]*)/') as $match) {
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => $match['matches'][1],
                'symbol_name' => $match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => $this->languageForPath($relativePath),
                'signature' => trim($match['text']),
                'metadata' => [
                    'exported' => true,
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseMarkdownSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        foreach ($this->lineMatches($content, '/^(#{1,3})\s+(.+)$/') as $match) {
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'doc_heading',
                'symbol_name' => $match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'markdown',
                'signature' => trim($match['text']),
                'metadata' => [
                    'level' => strlen($match['matches'][1]),
                ],
            ]);
        }

        return $symbols;
    }

    private function normalizeRelativeImport(string $relativePath, string $import): string
    {
        $base = trim(dirname($relativePath), '.');
        $parts = explode('/', trim($base.'/'.$import, '/'));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);

                continue;
            }
            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }

    /**
     * @return array<int,array{line:int,text:string,matches:array<int,string>}>
     */
    private function lineMatches(string $content, string $pattern): array
    {
        $matches = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $index => $line) {
            if (preg_match($pattern, $line, $match)) {
                $matches[] = [
                    'line' => $index + 1,
                    'text' => $line,
                    'matches' => $match,
                ];
            }
        }

        return $matches;
    }

    private function lineForOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private function routeTarget(string $target): array
    {
        if (preg_match('/\[([A-Za-z0-9_\\\\]+)::class,\s*[\'"]([^\'"]+)[\'"]\]/', $target, $match)) {
            return [
                'controller' => $match[1],
                'action' => $match[2],
            ];
        }

        return [];
    }

    private function joinRoute(string $prefix, string $uri): string
    {
        $uri = trim($uri, '/');
        $path = trim($prefix.'/'.$uri, '/');

        return '/'.$path;
    }

    private function classClassification(string $path, string $shortName): string
    {
        return match (true) {
            str_ends_with($shortName, 'Command') => 'command',
            str_ends_with($shortName, 'Controller') => 'controller',
            str_ends_with($shortName, 'Service') => 'service',
            str_starts_with($path, 'app/Models/') => 'model',
            str_starts_with($path, 'tests/') => 'test',
            default => 'class',
        };
    }

    // ponytail: local copy of the façade's languageForPath (the façade keeps its own
    // for scan/tree-sitter paths); a shared Support class isn't worth one pure match.
    private function languageForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'ts' => 'typescript',
            'tsx' => 'typescript_react',
            'js' => 'javascript',
            'jsx' => 'javascript_react',
            'md' => 'markdown',
            default => 'text',
        };
    }
}
