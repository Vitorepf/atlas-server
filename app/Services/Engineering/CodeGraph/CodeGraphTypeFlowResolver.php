<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Throwable;

/**
 * TYPE-FLOW resolution of dynamic method calls for the code graph ([native] P-1).
 *
 * The extractor that {@see CodeGraphTypedCallResolver} only CONSUMES. Where that resolver
 * takes pre-derived typed call records ({receiver: type-intent, ...}) and trusts them, THIS
 * service does the actual type-flow step on raw PHP: it parses source with nikic/php-parser
 * (the same parser the rest of Code Intelligence uses — {@see EngineeringCodeIntelligenceService})
 * and resolves a DYNAMIC receiver `$var->method()` to a concrete target TYPE, but ONLY when
 * that type is statically certain from one of three local, non-speculative sources:
 *
 *   (a) basis=property — a typed property / constructor-promoted typed property, called via
 *       `$this->foo->bar()` where the enclosing class declares `private Foo $foo;`
 *   (b) basis=param    — a typed parameter type-hint, called via `$foo->bar()` where the
 *       enclosing method signs `function m(Foo $foo)`
 *   (c) basis=docblock — an `@var` docblock binding a local, e.g. an `@var Foo $foo` annotation
 *       on `$foo = make();`
 *
 * Because the receiver type is KNOWN, the resolved callee class is CERTAIN, so every emitted
 * edge is confidence=extracted (never inferred). This is the anti-over-claim spine of the
 * service: a call whose receiver type CANNOT be established this way is NEVER emitted as an
 * edge — it is only counted in stats.unresolved. We do not guess, chain (`a()->b()`), or
 * follow control flow; ambiguity is reported, not invented.
 *
 * Edge shape:
 *   {from: <EnclosingClass>::<method>, to: <ResolvedType>::<calledMethod>, confidence: extracted, basis}
 * where the class names are FQNs resolved against the file's namespace + use-imports.
 *
 * Pure-ish: parses an in-memory string (or reads one file), no DB, no IO beyond an optional
 * file_get_contents, no provider, no Python runtime, no policy. Fail-safe: a parse error or
 * unreadable path never throws — it yields an empty edge set plus a human note.
 */
class CodeGraphTypeFlowResolver
{
    public const SCHEMA = 'atlas.code_graph.type_flow_edges.v1';

    /** Receiver type statically known -> callee class certain -> EXTRACTED. */
    public const CONFIDENCE_EXTRACTED = 'extracted';

    private const BASIS_PROPERTY = 'property';

    private const BASIS_PARAM = 'param';

    private const BASIS_DOCBLOCK = 'docblock';

    /**
     * Resolve dynamic method calls in the given PHP source (string) or file path.
     *
     * @param  string  $codeOrPath  PHP source code, OR an absolute/relative path to a .php file.
     * @return array{schema_version:string, edges:array<int,array{from:string,to:string,confidence:string,basis:string}>, stats:array{calls:int,resolved:int,unresolved:int}, note?:string}
     */
    public function resolve(string $codeOrPath): array
    {
        $source = $this->loadSource($codeOrPath);

        if ($source === null) {
            return $this->emptyResult('source is empty or file could not be read');
        }

        if (! class_exists(ParserFactory::class)) {
            return $this->emptyResult('nikic/php-parser is not available');
        }

        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $statements = $parser->parse($source);
        } catch (Throwable $e) {
            return $this->emptyResult('parse error: '.$e->getMessage());
        }

        if (! is_array($statements)) {
            return $this->emptyResult('parser returned no statements');
        }

        try {
            return $this->resolveStatements($statements);
        } catch (Throwable $e) {
            // Defensive: any unexpected shape during traversal degrades to empty, never throws.
            return $this->emptyResult('resolution error: '.$e->getMessage());
        }
    }

    /**
     * Decide whether the input is source code or a file path, and return the source.
     * A path is only honoured when it has no newline, ends in .php, and points at a readable file.
     */
    private function loadSource(string $codeOrPath): ?string
    {
        $trimmed = trim($codeOrPath);

        if ($trimmed === '') {
            return null;
        }

        $looksLikePath = ! str_contains($codeOrPath, "\n")
            && ! str_contains($codeOrPath, '<?php')
            && str_ends_with(strtolower($trimmed), '.php');

        if ($looksLikePath) {
            if (! is_file($trimmed) || ! is_readable($trimmed)) {
                return null;
            }

            $contents = @file_get_contents($trimmed);

            return ($contents === false || $contents === '') ? null : $contents;
        }

        return $codeOrPath;
    }

    /**
     * @param  array<int,Node>  $statements
     * @return array{schema_version:string, edges:array<int,array{from:string,to:string,confidence:string,basis:string}>, stats:array{calls:int,resolved:int,unresolved:int}}
     */
    private function resolveStatements(array $statements): array
    {
        $edges = [];
        $calls = 0;
        $resolved = 0;
        $unresolved = 0;

        foreach ($this->namespaceScopes($statements) as $scope) {
            /** @var string $namespace */
            $namespace = $scope['namespace'];
            /** @var array<string,string> $imports */
            $imports = $scope['imports'];
            /** @var array<int,ClassLike> $classes */
            $classes = $scope['classes'];

            foreach ($classes as $class) {
                $className = $this->classFqn($class, $namespace);

                if ($className === '') {
                    continue;
                }

                $propertyTypes = $this->collectPropertyTypes($class, $namespace, $imports);

                foreach ($this->classMethods($class) as $method) {
                    $methodName = $method->name->toString();
                    $paramTypes = $this->collectParamTypes($method, $namespace, $imports);
                    $docblockTypes = $this->collectDocblockVarTypes($method, $namespace, $imports);

                    foreach ($this->methodCalls($method) as $call) {
                        $callee = $this->calleeName($call);

                        if ($callee === null) {
                            continue; // dynamic method name ($x->$m()) — not a named call
                        }

                        $calls++;

                        $receiverType = $this->resolveReceiverType(
                            $call->var,
                            $propertyTypes,
                            $paramTypes,
                            $docblockTypes
                        );

                        if ($receiverType === null) {
                            $unresolved++;

                            continue;
                        }

                        $resolved++;
                        $edges[] = [
                            'from' => $className.'::'.$methodName,
                            'to' => $receiverType['type'].'::'.$callee,
                            'confidence' => self::CONFIDENCE_EXTRACTED,
                            'basis' => $receiverType['basis'],
                        ];
                    }
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'edges' => $this->dedupeEdges($edges),
            'stats' => [
                'calls' => $calls,
                'resolved' => $resolved,
                'unresolved' => $unresolved,
            ],
        ];
    }

    /**
     * Group top-level statements into namespace scopes, each carrying its use-imports and classes.
     * A file with no namespace declaration is treated as a single global scope.
     *
     * @param  array<int,Node>  $statements
     * @return array<int,array{namespace:string,imports:array<string,string>,classes:array<int,ClassLike>}>
     */
    private function namespaceScopes(array $statements): array
    {
        $hasNamespace = false;
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $hasNamespace = true;

                break;
            }
        }

        if (! $hasNamespace) {
            return [$this->scopeFromBody('', $statements)];
        }

        $scopes = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $namespace = $statement->name instanceof Name ? $statement->name->toString() : '';
                $scopes[] = $this->scopeFromBody($namespace, $statement->stmts);
            }
        }

        return $scopes;
    }

    /**
     * @param  array<int,Node>  $body
     * @return array{namespace:string,imports:array<string,string>,classes:array<int,ClassLike>}
     */
    private function scopeFromBody(string $namespace, array $body): array
    {
        $imports = [];
        $classes = [];

        foreach ($body as $node) {
            if ($node instanceof Use_) {
                foreach ($node->uses as $use) {
                    $fqn = ltrim($use->name->toString(), '\\');
                    if ($fqn === '') {
                        continue;
                    }

                    $alias = $use->alias instanceof Identifier
                        ? $use->alias->toString()
                        : $this->shortName($fqn);
                    $imports[$alias] = $fqn;
                }

                continue;
            }

            if ($node instanceof ClassLike) {
                $classes[] = $node;
            }
        }

        return [
            'namespace' => $namespace,
            'imports' => $imports,
            'classes' => $classes,
        ];
    }

    /**
     * Map of `$this->prop` name => {type FQN, basis:property} for every typed property,
     * including constructor-promoted typed properties.
     *
     * @param  array<string,string>  $imports
     * @return array<string,array{type:string,basis:string}>
     */
    private function collectPropertyTypes(ClassLike $class, string $namespace, array $imports): array
    {
        $types = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property && $stmt->type !== null) {
                $fqn = $this->typeToFqn($stmt->type, $namespace, $imports);
                if ($fqn === null) {
                    continue;
                }

                foreach ($stmt->props as $prop) {
                    $types[$prop->name->toString()] = ['type' => $fqn, 'basis' => self::BASIS_PROPERTY];
                }
            }
        }

        // Constructor-promoted typed properties (public/protected/private + type on the param).
        foreach ($this->classMethods($class) as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }

            foreach ($method->params as $param) {
                if (! $param->isPromoted() || $param->type === null) {
                    continue;
                }

                if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                    continue;
                }

                $fqn = $this->typeToFqn($param->type, $namespace, $imports);
                if ($fqn === null) {
                    continue;
                }

                $types[$param->var->name] = ['type' => $fqn, 'basis' => self::BASIS_PROPERTY];
            }
        }

        return $types;
    }

    /**
     * Map of parameter `$name` => {type FQN, basis:param} for every typed parameter of the method.
     *
     * @param  array<string,string>  $imports
     * @return array<string,array{type:string,basis:string}>
     */
    private function collectParamTypes(ClassMethod $method, string $namespace, array $imports): array
    {
        $types = [];

        foreach ($method->params as $param) {
            if ($param->type === null) {
                continue;
            }

            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            $fqn = $this->typeToFqn($param->type, $namespace, $imports);
            if ($fqn === null) {
                continue;
            }

            $types[$param->var->name] = ['type' => $fqn, 'basis' => self::BASIS_PARAM];
        }

        return $types;
    }

    /**
     * Map of local `$name` => {type FQN, basis:docblock} from `@var` docblocks attached to
     * inline assignments inside the method body (an `@var Foo $foo` docblock on `$foo = make();`).
     *
     * @param  array<string,string>  $imports
     * @return array<string,array{type:string,basis:string}>
     */
    private function collectDocblockVarTypes(ClassMethod $method, string $namespace, array $imports): array
    {
        $types = [];

        if ($method->stmts === null) {
            return $types;
        }

        $finder = new NodeFinder;
        /** @var array<int,Node> $nodes */
        $nodes = $finder->find($method->stmts, static fn (Node $node): bool => $node instanceof Expression || $node instanceof Assign);

        foreach ($nodes as $node) {
            $doc = $node->getDocComment();
            $assign = $node instanceof Expression ? $node->expr : $node;

            if (! $doc instanceof Doc || ! $assign instanceof Assign) {
                continue;
            }

            // The @var may sit on the wrapping Expression statement rather than the Assign.
            $parsed = $this->parseVarDocblock($doc->getText());
            if ($parsed === null) {
                continue;
            }

            [$docType, $docVar] = $parsed;

            $target = $assign->var;
            if (! $target instanceof Variable || ! is_string($target->name)) {
                continue;
            }

            // If the docblock names a variable, it must match the assignment target.
            if ($docVar !== null && $docVar !== $target->name) {
                continue;
            }

            $fqn = $this->resolveClassName($docType, $namespace, $imports);
            if ($fqn === '') {
                continue;
            }

            $types[$target->name] = ['type' => $fqn, 'basis' => self::BASIS_DOCBLOCK];
        }

        return $types;
    }

    /**
     * Resolve the receiver expression of a method call to a concrete type, or null if unknown.
     *
     * Handles exactly two certain shapes:
     *   - `$this->prop->method()` -> the declared type of `prop` (basis property)
     *   - `$var->method()`        -> the declared/inferred type of `$var` (basis param|docblock)
     *
     * @param  array<string,array{type:string,basis:string}>  $propertyTypes
     * @param  array<string,array{type:string,basis:string}>  $paramTypes
     * @param  array<string,array{type:string,basis:string}>  $docblockTypes
     * @return array{type:string,basis:string}|null
     */
    private function resolveReceiverType(
        Node $receiver,
        array $propertyTypes,
        array $paramTypes,
        array $docblockTypes
    ): ?array {
        // $this->prop->method()
        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier) {
            return $propertyTypes[$receiver->name->toString()] ?? null;
        }

        // $var->method()  (param or docblock-typed local; never $this itself)
        if ($receiver instanceof Variable && is_string($receiver->name) && $receiver->name !== 'this') {
            return $paramTypes[$receiver->name]
                ?? $docblockTypes[$receiver->name]
                ?? null;
        }

        // Anything else (chained calls, static fetches, array access, $this->prop with dynamic
        // name, etc.) is intentionally NOT resolved — reported as unresolved upstream.
        return null;
    }

    /**
     * The static method name of a call, or null when the method name is itself dynamic.
     */
    private function calleeName(MethodCall $call): ?string
    {
        return $call->name instanceof Identifier ? $call->name->toString() : null;
    }

    /**
     * Reduce a type node to a single class FQN, or null when it is not a single named class type
     * (e.g. scalar `int`/`string`, `array`, union/intersection types — too ambiguous to key on).
     * A nullable single type `?Foo` is accepted as `Foo`.
     *
     * @param  Identifier|Name|ComplexType|Node  $type
     * @param  array<string,string>  $imports
     */
    private function typeToFqn(Node $type, string $namespace, array $imports): ?string
    {
        if ($type instanceof NullableType) {
            return $this->typeToFqn($type->type, $namespace, $imports);
        }

        // Union / intersection / other complex types: ambiguous receiver — refuse.
        if ($type instanceof ComplexType) {
            return null;
        }

        if ($type instanceof Identifier) {
            // Built-in scalar / pseudo types are not class references.
            return null;
        }

        if ($type instanceof Name) {
            $fqn = $this->resolveClassName($type->toString(), $namespace, $imports);

            return $fqn === '' ? null : $fqn;
        }

        return null;
    }

    /**
     * Resolve a class name token (possibly aliased / relative / leading-slash) to a FQN using
     * the file's use-imports and current namespace.
     *
     * @param  array<string,string>  $imports
     */
    private function resolveClassName(string $name, string $namespace, array $imports): string
    {
        $name = trim($name);

        if ($name === '' || in_array(strtolower($name), ['self', 'static', 'parent', 'mixed', 'object', 'callable', 'iterable', 'void', 'null', 'true', 'false', 'int', 'float', 'string', 'bool', 'array'], true)) {
            return '';
        }

        // Fully-qualified already.
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = $this->firstSegment($name);

        // Aliased / imported short name -> expand to the imported FQN (preserving any sub-path).
        if (isset($imports[$first])) {
            $rest = substr($name, strlen($first));

            return $imports[$first].$rest;
        }

        // Relative to current namespace.
        if ($namespace !== '') {
            return $namespace.'\\'.$name;
        }

        return $name;
    }

    /**
     * Parse a docblock string for a leading `@var Type [$name]`. Returns [type, name|null] or null.
     *
     * @return array{0:string,1:string|null}|null
     */
    private function parseVarDocblock(string $docText): ?array
    {
        if (! preg_match('/@var\s+([^\s]+)(?:\s+\$([A-Za-z_][A-Za-z0-9_]*))?/', $docText, $m)) {
            return null;
        }

        $type = trim($m[1]);
        // Drop a leading nullable marker and refuse compound (union/intersection/generic) types.
        $type = ltrim($type, '?');
        if ($type === '' || preg_match('/[|&<>\[\]]/', $type)) {
            return null;
        }

        $name = ($m[2] ?? '') !== '' ? $m[2] : null;

        return [$type, $name];
    }

    /**
     * Direct (non-anonymous) class-like declarations in the scope already collected;
     * here we expose the methods of one class-like, skipping abstract/interface bodies that have none.
     *
     * @return array<int,ClassMethod>
     */
    private function classMethods(ClassLike $class): array
    {
        $methods = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $methods[] = $stmt;
            }
        }

        return $methods;
    }

    /**
     * All MethodCall nodes anywhere inside a method body (handles nesting via NodeFinder).
     *
     * @return array<int,MethodCall>
     */
    private function methodCalls(ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $finder = new NodeFinder;

        /** @var array<int,MethodCall> $calls */
        $calls = $finder->findInstanceOf($method->stmts, MethodCall::class);

        return $calls;
    }

    /**
     * The FQN of a class-like declaration, or '' for anonymous classes (which have no name).
     */
    private function classFqn(ClassLike $class, string $namespace): string
    {
        if (! $class->name instanceof Identifier) {
            return ''; // anonymous class — no stable node id
        }

        // Only resolve concrete declarations we recognise; all ClassLike subclasses qualify.
        if (! ($class instanceof Class_ || $class instanceof Interface_ || $class instanceof Trait_ || $class instanceof Enum_)) {
            return '';
        }

        $short = $class->name->toString();

        return $namespace === '' ? $short : $namespace.'\\'.$short;
    }

    private function firstSegment(string $name): string
    {
        $name = ltrim($name, '\\');
        $pos = strpos($name, '\\');

        return $pos === false ? $name : substr($name, 0, $pos);
    }

    private function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    /**
     * Stable dedupe of identical edges, preserving first-seen order.
     *
     * @param  array<int,array{from:string,to:string,confidence:string,basis:string}>  $edges
     * @return array<int,array{from:string,to:string,confidence:string,basis:string}>
     */
    private function dedupeEdges(array $edges): array
    {
        $seen = [];
        $unique = [];

        foreach ($edges as $edge) {
            $key = $edge['from'].'=>'.$edge['to'].'|'.$edge['basis'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $edge;
        }

        return $unique;
    }

    /**
     * @return array{schema_version:string, edges:array<int,never>, stats:array{calls:int,resolved:int,unresolved:int}, note:string}
     */
    private function emptyResult(string $note): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'edges' => [],
            'stats' => ['calls' => 0, 'resolved' => 0, 'unresolved' => 0],
            'note' => $note,
        ];
    }
}
