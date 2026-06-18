<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Extracted helper cluster for {@see AtlasDeadCodeAnalyzer}.
 *
 * Holds the AST-walking logic that collects member references and
 * dynamic-dispatch signals from a single class subtree. Relocating it here
 * keeps the host analyzer's worst method (deadMembers) strictly simpler while
 * preserving identical behavior — the same nodes are walked and the same
 * signals are produced, just from a re-homed method.
 */
final class AtlasDeadCodeAnalyzerSupport
{
    /**
     * Walk a class subtree collecting member references and dynamic-dispatch signals.
     *
     * @param  list<Node\Stmt>  $subtree
     * @return array{
     *     usedMethods:array<string,bool>,
     *     usedConsts:array<string,bool>,
     *     usedProps:array<string,bool>,
     *     usedStrings:array<string,bool>,
     *     dynMethod:bool,
     *     dynProp:bool,
     *     dynConst:bool,
     * }
     */
    public function collectUsage(array $subtree, NodeFinder $finder): array
    {
        $usedMethods = [];   // lower-cased (PHP method names are case-insensitive)
        $usedConsts = [];    // case-sensitive
        $usedProps = [];     // case-sensitive
        $usedStrings = [];   // every string literal value, lower-cased (callable / dynamic hints)
        $dynMethod = false;
        $dynProp = false;
        $dynConst = false;

        foreach ($finder->find($subtree, static fn (Node $n): bool => true) as $n) {
            if ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\NullsafeMethodCall) {
                if ($n->name instanceof Node\Identifier) {
                    $usedMethods[strtolower($n->name->toString())] = true;
                } else {
                    $dynMethod = true; // $obj->$name()
                }
            } elseif ($n instanceof Node\Expr\PropertyFetch || $n instanceof Node\Expr\StaticPropertyFetch || $n instanceof Node\Expr\NullsafePropertyFetch) {
                if ($n->name instanceof Node\Expr) {
                    $dynProp = true; // $obj->$name
                } else {
                    $usedProps[$n->name->toString()] = true;
                }
            } elseif ($n instanceof Node\Expr\ClassConstFetch) {
                if ($n->name instanceof Node\Identifier) {
                    $usedConsts[$n->name->toString()] = true;
                } else {
                    $dynConst = true; // Foo::{$name}
                }
            } elseif ($n instanceof Node\Scalar\String_) {
                $usedStrings[strtolower($n->value)] = true;
            } elseif ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name) {
                $fn = strtolower($n->name->toString());
                if (in_array($fn, ['call_user_func', 'call_user_func_array', 'method_exists', 'is_callable', 'func_get_args'], true)) {
                    $dynMethod = true;
                }
                if (in_array($fn, ['property_exists', 'get_object_vars', 'compact', 'extract'], true)) {
                    $dynProp = true;
                }
                if ($fn === 'constant') {
                    $dynConst = true;
                }
            } elseif ($n instanceof Node\Stmt\ClassMethod && $this->isMagicCallProxy($n->name->toString())) {
                $dynMethod = true; // __call/__callStatic route arbitrary names
                if (in_array(strtolower($n->name->toString()), ['__get', '__set', '__isset', '__unset'], true)) {
                    $dynProp = true;
                }
            }
        }

        return [
            'usedMethods' => $usedMethods,
            'usedConsts' => $usedConsts,
            'usedProps' => $usedProps,
            'usedStrings' => $usedStrings,
            'dynMethod' => $dynMethod,
            'dynProp' => $dynProp,
            'dynConst' => $dynConst,
        ];
    }

    public function isMagicCallProxy(string $name): bool
    {
        return in_array(strtolower($name), ['__call', '__callstatic', '__get', '__set', '__isset', '__unset'], true);
    }
}
