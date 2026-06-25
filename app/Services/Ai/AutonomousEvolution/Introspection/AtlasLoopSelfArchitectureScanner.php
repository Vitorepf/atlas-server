<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Introspection;

use InvalidArgumentException;

/**
 * Read-only architecture scanner over `app/Services/Ai/AutonomousEvolution/**`.
 *
 * Emits a deterministic, byte-identical, sorted JSON FACT structure (file_count, class_count,
 * namespace_tree, primitives_by_category, methods_by_class, lines_by_class). NEVER returns a
 * score / grade / health / rating — facts only, per anti-Goodhart law.
 *
 * Pure: only file_get_contents on the bounded AutonomousEvolution root. No network, no provider,
 * no process spawn, no DB.
 */
final class AtlasLoopSelfArchitectureScanner
{
    public const SCHEMA = 'atlas.loop.self_architecture_facts.v1';

    /** Primitive categories detected by class-name suffix or namespace token. */
    private const PRIMITIVE_CATEGORIES = [
        'Brain' => ['Brain'],
        'Origination' => ['Originat'],
        'Certification' => ['Certif'],
        'Wiring' => ['Wir'],
        'Frontier' => ['Frontier'],
        'Refiller' => ['Refill'],
        'Receipt' => ['Receipt', 'Ledger'],
        'Audit' => ['Audit', 'AuditTrail'],
        'Retention' => ['Retention'],
        'ReentrySafety' => ['Reentry', 'Checkpoint', 'Idempotency'],
        'WireFormat' => ['Wire', 'Schema'],
        'Introspection' => ['Introspection', 'Scanner'],
    ];

    public function __construct(private readonly ?string $rootPathOverride = null) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(?string $absoluteRoot = null): array
    {
        $root = $absoluteRoot ?? $this->defaultRoot();
        $this->assertWithinAutonomousEvolution($root);
        if (! is_dir($root)) {
            throw new InvalidArgumentException('AtlasLoopSelfArchitectureScanner: root not a directory: '.$root);
        }

        $files = $this->collectPhpFiles($root);
        sort($files, SORT_STRING);

        $classCount = 0;
        $namespaces = [];
        $methodsByClass = [];
        $linesByClass = [];
        $primitivesByCategory = array_fill_keys(array_keys(self::PRIMITIVE_CATEGORIES), []);

        foreach ($files as $file) {
            $contents = (string) @file_get_contents($file);
            if ($contents === '') {
                continue;
            }
            $namespace = $this->extractNamespace($contents);
            $classes = $this->extractClasses($contents);
            foreach ($classes as $class) {
                $classCount++;
                $fqcn = ($namespace !== '' ? $namespace.'\\' : '').$class;
                $methodsByClass[$fqcn] = $this->extractPublicMethods($contents, $class);
                $linesByClass[$fqcn] = substr_count($contents, "\n") + 1;

                $namespaces[$namespace] = true;
                foreach (self::PRIMITIVE_CATEGORIES as $category => $tokens) {
                    foreach ($tokens as $token) {
                        if (str_contains($class, $token) || str_contains($namespace, $token)) {
                            $primitivesByCategory[$category][] = $fqcn;
                            break;
                        }
                    }
                }
            }
        }

        $namespaceTree = array_keys($namespaces);
        sort($namespaceTree, SORT_STRING);
        ksort($methodsByClass, SORT_STRING);
        ksort($linesByClass, SORT_STRING);
        foreach ($primitivesByCategory as $cat => $rows) {
            $primitivesByCategory[$cat] = array_values(array_unique($rows));
            sort($primitivesByCategory[$cat], SORT_STRING);
        }
        ksort($primitivesByCategory, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'root' => $root,
            'file_count' => count($files),
            'class_count' => $classCount,
            'namespace_tree' => $namespaceTree,
            'primitives_by_category' => $primitivesByCategory,
            'methods_by_class' => $methodsByClass,
            'lines_by_class' => $linesByClass,
        ];
    }

    private function defaultRoot(): string
    {
        if ($this->rootPathOverride !== null) {
            return $this->rootPathOverride;
        }
        if (function_exists('base_path')) {
            return base_path('app/Services/Ai/AutonomousEvolution');
        }

        return __DIR__.'/../';
    }

    private function assertWithinAutonomousEvolution(string $root): void
    {
        $normalized = rtrim(str_replace('\\', '/', $root), '/');
        if (! str_contains($normalized, '/app/Services/Ai/AutonomousEvolution')) {
            throw new InvalidArgumentException('AtlasLoopSelfArchitectureScanner: root must be inside app/Services/Ai/AutonomousEvolution — got: '.$root);
        }
    }

    /**
     * @return list<string>
     */
    private function collectPhpFiles(string $root): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function extractNamespace(string $contents): string
    {
        if (preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $contents, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function extractClasses(string $contents): array
    {
        $names = [];
        if (preg_match_all('/^(?:final\s+|abstract\s+)?(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function extractPublicMethods(string $contents, string $className): array
    {
        // Extract the class body roughly and pull `public function name(` matches.
        $methods = [];
        if (preg_match_all('/\bpublic\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $contents, $m) > 0) {
            foreach ($m[1] as $name) {
                $methods[] = (string) $name;
            }
        }
        $methods = array_values(array_unique($methods));
        sort($methods, SORT_STRING);

        return $methods;
    }
}
