<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_ as NamespaceStmt;
use PhpParser\Node\Stmt\Use_ as UseStmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * ACDE Leap 6 (design-judgement ceiling) — the DECORRELATED machine extractor of a file's REAL interface.
 *
 * The boundary-oracle (Leap 2) anchors which FILES must be separate nodes; it says nothing about the
 * ABSTRACTION inside a node. This extractor reads the actual PHP source via nikic/php-parser (an AST tool,
 * NOT the provider LLM — so its verdict is decorrelated from whatever engine wrote the code) and reports the
 * file's exported surface (declared types, their public methods, what they extend/implement) and its declared
 * dependencies (use-imports). {@see AtlasLoopNodeInterfaceVerifier} compares this against a HUMAN-frozen
 * interface contract — the model cannot author the bar, and the check is a deterministic AST census, so it
 * cannot be Goodharted by a plausible-but-wrong abstraction.
 *
 * Pure: parses one source string, no provider, no DB, no mutation. A parse failure => empty surface (the
 * verifier treats "could not read the interface" as a contract miss, fail-closed only under its own flag).
 */
final class AtlasLoopNodeInterfaceExtractor
{
    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * Extract the declared interface of a PHP source file.
     *
     * @return array{
     *     namespace: ?string,
     *     types: list<array{fqn:string, name:string, kind:string, public_methods:list<string>, implements:list<string>, extends:list<string>}>,
     *     imports: list<string>,
     *     parsed: bool
     * }
     */
    public function extract(string $phpSource): array
    {
        $empty = ['namespace' => null, 'types' => [], 'imports' => [], 'parsed' => false];
        if (trim($phpSource) === '') {
            return $empty;
        }

        try {
            $stmts = $this->parser->parse($phpSource);
        } catch (Throwable) {
            return $empty;
        }
        if ($stmts === null) {
            return $empty;
        }

        $namespace = $this->firstNamespace($stmts);
        $imports = $this->imports($stmts);

        $types = [];
        /** @var list<ClassLike> $classLikes */
        $classLikes = $this->finder->findInstanceOf($stmts, ClassLike::class);
        foreach ($classLikes as $cl) {
            $name = $cl->name?->toString();
            if ($name === null || $name === '') {
                continue; // anonymous class — no stable exported identity
            }
            $fqn = $namespace !== null ? $namespace.'\\'.$name : $name;
            $types[] = [
                'fqn' => $fqn,
                'name' => $name,
                'kind' => $this->kindOf($cl),
                'public_methods' => $this->publicMethods($cl),
                'implements' => $this->namesOf($cl, 'implements'),
                'extends' => $this->extendsOf($cl),
            ];
        }

        return ['namespace' => $namespace, 'types' => $types, 'imports' => $imports, 'parsed' => true];
    }

    /** @param  list<Node>  $stmts */
    private function firstNamespace(array $stmts): ?string
    {
        foreach ($stmts as $s) {
            if ($s instanceof NamespaceStmt) {
                return $s->name?->toString();
            }
        }

        return null;
    }

    /**
     * @param  list<Node>  $stmts
     * @return list<string>
     */
    private function imports(array $stmts): array
    {
        $out = [];
        /** @var list<UseStmt> $uses */
        $uses = $this->finder->findInstanceOf($stmts, UseStmt::class);
        foreach ($uses as $use) {
            foreach ($use->uses as $u) {
                $name = $u->name->toString();
                if ($name !== '') {
                    $out[$name] = true;
                }
            }
        }

        return array_keys($out);
    }

    private function kindOf(ClassLike $cl): string
    {
        return match (true) {
            $cl instanceof Node\Stmt\Interface_ => 'interface',
            $cl instanceof Node\Stmt\Trait_ => 'trait',
            $cl instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };
    }

    /** @return list<string> */
    private function publicMethods(ClassLike $cl): array
    {
        $out = [];
        foreach ($cl->getMethods() as $m) {
            if ($m instanceof ClassMethod && $m->isPublic()) {
                $out[$m->name->toString()] = true;
            }
        }
        $names = array_keys($out);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function namesOf(ClassLike $cl, string $prop): array
    {
        $out = [];
        $list = $cl->{$prop} ?? [];
        foreach ((array) $list as $n) {
            if ($n instanceof Node\Name) {
                $out[$n->toString()] = true;
            }
        }
        $names = array_keys($out);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function extendsOf(ClassLike $cl): array
    {
        $extends = $cl->extends ?? null;
        if ($extends instanceof Node\Name) {
            return [$extends->toString()]; // class/interface single (interfaces may extend many → array)
        }
        $out = [];
        foreach ((array) $extends as $n) {
            if ($n instanceof Node\Name) {
                $out[$n->toString()] = true;
            }
        }
        $names = array_keys($out);
        sort($names);

        return $names;
    }
}
