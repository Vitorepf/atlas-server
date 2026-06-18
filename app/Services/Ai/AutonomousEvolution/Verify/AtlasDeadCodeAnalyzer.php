<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * DEAD-CODE ANALYZER — the AST oracle behind the P3 "dead code" verifier.
 *
 * It answers ONE question per file, honestly and completely: which `private`
 * members have zero references inside their own class? PHP `private` scope makes
 * this analysis SOUND from a single file — a private method/const/property can
 * only be reached from within the same class, so a same-file AST scan can never
 * miss a real caller (no cross-file false-negatives). That is the whole reason we
 * restrict the claim to `private`: it is the largest subset where "no reference
 * here" provably means "dead", with no whole-program call graph required.
 *
 * False-positives are driven toward zero by construction:
 *   - magic methods (__construct, __call, …) are never flagged;
 *   - promoted constructor properties are skipped (they are the public ctor contract);
 *   - members carrying attributes are skipped (framework hooks: listeners, casts, …);
 *   - any string literal equal to a member name counts as a use ([$this,'m'], callables);
 *   - and if the class shows ANY dynamic-dispatch signal for a member KIND
 *     (__call/__get, variable method/property access, call_user_func, method_exists,
 *     property_exists, compact/extract, constant(), …) that whole kind is disabled —
 *     we refuse to assert deadness we cannot prove.
 *
 * The analyzer never edits anything. It is pure read + parse, reusable both as the
 * discovery oracle (scan the repo → findings) and as the per-file frozen verifier
 * (count dead members → ATLAS_DEADCODE). The loop only ever removes what this flags,
 * and the frozen judge's revert-recheck + a full-suite holdout backstop the removal.
 */
final class AtlasDeadCodeAnalyzer
{
    public const SCHEMA = 'atlas.loop.deadcode.v1';

    /**
     * Files larger than this are skipped as INCONCLUSIVE. A multi-MB file is invariably a
     * generated/data artifact, never a hand-maintained dead-private-member removal target —
     * and parsing one (the repo has an 8 MB service) builds an AST that exhausts the CLI
     * memory limit and would crash a 24h scan. Fail-closed: a too-large file is never "clean".
     */
    private const MAX_FILE_BYTES = 512 * 1024;

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    private readonly AtlasDeadCodeAnalyzerSupport $support;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
        $this->support = new AtlasDeadCodeAnalyzerSupport;
    }

    /**
     * @return array{
     *     schema_version:string,
     *     path:string,
     *     parseable:bool,
     *     dead:list<array{kind:string,name:string,line:int,class:string}>,
     *     note?:string
     * }
     */
    public function analyzeFile(string $absPath): array
    {
        $size = @filesize($absPath);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            // Too large to parse safely — inconclusive, NOT clean (fail-closed).
            return $this->result($absPath, false, [], 'too_large_to_parse');
        }

        $code = @file_get_contents($absPath);
        if ($code === false || trim($code) === '') {
            return $this->result($absPath, false, [], 'unreadable_or_empty');
        }

        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable $e) {
            return $this->result($absPath, false, [], 'parse_error');
        }
        if ($stmts === null) {
            return $this->result($absPath, false, [], 'parse_null');
        }

        $dead = [];
        /** @var list<Node\Stmt\ClassLike> $classes */
        $classes = $this->finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class);
        foreach ($classes as $class) {
            // Interfaces cannot hold private members; nothing to analyze.
            if ($class instanceof Node\Stmt\Interface_) {
                continue;
            }
            foreach ($this->deadMembers($class) as $member) {
                $dead[] = $member;
            }
        }

        return $this->result($absPath, true, $dead);
    }

    /**
     * @return list<array{kind:string,name:string,line:int,class:string}>
     */
    private function deadMembers(Node\Stmt\ClassLike $class): array
    {
        $className = $class->name?->toString() ?? '(anonymous)';

        // --- collect references + dynamic-dispatch signals within THIS class subtree ---
        $usage = $this->support->collectUsage($class->stmts, $this->finder);
        $usedMethods = $usage['usedMethods'];   // lower-cased (PHP method names are case-insensitive)
        $usedConsts = $usage['usedConsts'];    // case-sensitive
        $usedProps = $usage['usedProps'];     // case-sensitive
        $usedStrings = $usage['usedStrings'];   // every string literal value, lower-cased (callable / dynamic hints)
        $dynMethod = $usage['dynMethod'];
        $dynProp = $usage['dynProp'];
        $dynConst = $usage['dynConst'];
        // --- declared private members ---
        $dead = [];

        foreach ($class->getMethods() as $method) {
            if (! $method->isPrivate() || $method->attrGroups !== [] || $this->isMagic($method->name->toString())) {
                continue;
            }
            if ($dynMethod) {
                continue;
            }
            $name = $method->name->toString();
            if (! isset($usedMethods[strtolower($name)]) && ! isset($usedStrings[strtolower($name)])) {
                $dead[] = ['kind' => 'method', 'name' => $name, 'line' => $method->getStartLine(), 'class' => $className];
            }
        }

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst && $stmt->isPrivate() && $stmt->attrGroups === [] && ! $dynConst) {
                foreach ($stmt->consts as $const) {
                    $name = $const->name->toString();
                    if (! isset($usedConsts[$name]) && ! isset($usedStrings[strtolower($name)])) {
                        $dead[] = ['kind' => 'const', 'name' => $name, 'line' => $const->getStartLine(), 'class' => $className];
                    }
                }
            }
            if ($stmt instanceof Node\Stmt\Property && $stmt->isPrivate() && $stmt->attrGroups === [] && ! $dynProp) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    if (! isset($usedProps[$name]) && ! isset($usedStrings[strtolower($name)])) {
                        $dead[] = ['kind' => 'property', 'name' => $name, 'line' => $prop->getStartLine(), 'class' => $className];
                    }
                }
            }
        }

        return $dead;
    }

    private function isMagic(string $name): bool
    {
        return str_starts_with(strtolower($name), '__');
    }

    /**
     * @param  list<array{kind:string,name:string,line:int,class:string}>  $dead
     * @return array{schema_version:string,path:string,parseable:bool,dead:list<array{kind:string,name:string,line:int,class:string}>,note?:string}
     */
    private function result(string $path, bool $parseable, array $dead, ?string $note = null): array
    {
        $out = [
            'schema_version' => self::SCHEMA,
            'path' => $path,
            'parseable' => $parseable,
            'dead' => array_values($dead),
        ];
        if ($note !== null) {
            $out['note'] = $note;
        }

        return $out;
    }
}
