<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * CORTEX COUNCIL — LENS #1 of 5: CALL-GRAPH. Observes a CortexSubject (PHP source) and emits FACTS about its
 * call-graph topology: outbound static/instance/self call edges, fan-out, self-recursive edges, plus aggregate
 * counters. Dynamic-dispatch sites (e.g. `$var->$method()`) appear in `disagreement_signals` so the council
 * sees the lens dissent from a "complete graph" reading — never silently dropped.
 *
 * Pure + deterministic + provider-safe. NO scores, NO weighting (LensObservation enforces it).
 *
 * The subject's facts may carry either of:
 *   - `source_code` : raw PHP source string
 *   - `file_path`   : absolute path the lens will read once
 */
final class AtlasCortexCallGraphLens implements LensContract
{
    public function id(): string
    {
        return 'callgraph';
    }

    public function name(): string
    {
        return 'Call-Graph';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        $source = $this->resolveSource($subject);
        if ($source === null) {
            return new LensObservation($this->id(), $subject->id, [
                'edges' => [],
                'fan_in' => 0,
                'fan_out' => 0,
                'self_recursive_edges' => 0,
            ], ['source_unresolved']);
        }

        $parsed = $this->parse($source);
        $edges = $parsed['edges'];
        $disagreement = $parsed['disagreement'];

        $factEntries = array_map(static fn (array $e): array => $e + ['kind' => 'call_edge'], $edges);
        // Self-recursive = an edge whose `from` method name equals the called method's short name.
        $selfRecursive = count(array_filter($edges, static function (array $e): bool {
            $to = (string) $e['to'];
            $colon = strrpos($to, ':');
            $callee = $colon === false ? $to : substr($to, $colon + 1);

            return $callee === $e['from'];
        }));

        return new LensObservation(
            $this->id(),
            $subject->id,
            [
                'edges' => $factEntries,
                // Fan-in is the count of EDGES whose `to` references a method DEFINED in this same subject —
                // the within-subject inbound count. Cross-subject fan-in requires the council index, not a lens.
                'fan_in' => $this->computeFanIn($edges, $parsed['method_names']),
                'fan_out' => count($edges),
                'self_recursive_edges' => $selfRecursive,
            ],
            $disagreement,
        );
    }

    private function resolveSource(CortexSubject $subject): ?string
    {
        $direct = (string) ($subject->facts['source_code'] ?? '');
        if ($direct !== '') {
            return $direct;
        }
        $path = (string) ($subject->facts['file_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            return (string) @file_get_contents($path);
        }

        return null;
    }

    /**
     * @return array{edges:list<array{from:string,to:string,type:string}>, disagreement:list<string>, method_names:list<string>}
     */
    private function parse(string $source): array
    {
        $edges = [];
        $disagreement = [];

        /** @var list<array{0:int|null,1:string,2:int}> $tokens */
        $tokens = [];
        foreach (token_get_all($source) as $t) {
            $tokens[] = is_array($t) ? [$t[0], $t[1], $t[2]] : [null, $t, 0];
        }
        $n = count($tokens);

        $methodNames = [];
        $currentMethod = null;
        $methodDepth = 0;
        $braceDepth = 0;
        $isPublic = false;

        for ($i = 0; $i < $n; $i++) {
            [$id, $text] = $tokens[$i];

            // Track method boundaries by counting braces from the opening `{` after a method signature.
            if ($text === '{') {
                $braceDepth++;
                if ($currentMethod !== null && $methodDepth === 0) {
                    $methodDepth = $braceDepth;
                }

                continue;
            }
            if ($text === '}') {
                if ($currentMethod !== null && $braceDepth === $methodDepth) {
                    $currentMethod = null;
                    $methodDepth = 0;
                    $isPublic = false;
                }
                $braceDepth = max(0, $braceDepth - 1);

                continue;
            }

            if ($id === T_PUBLIC) {
                $isPublic = true;

                continue;
            }
            if ($id === T_PRIVATE || $id === T_PROTECTED) {
                $isPublic = false;

                continue;
            }

            if ($id === T_FUNCTION && $currentMethod === null) {
                $name = $this->nextStringAfter($tokens, $i);
                if ($name !== null && $isPublic && $this->methodHasBody($tokens, $i)) {
                    $currentMethod = $name;
                    $methodNames[] = $name;
                }
                $isPublic = false; // method modifier is consumed by this declaration

                continue;
            }

            if ($currentMethod === null) {
                continue;
            }

            // From here: we are INSIDE a public method body. Detect calls.
            if ($id === T_DOUBLE_COLON) {
                $left = $this->prevStringOrSelf($tokens, $i);
                $right = $this->nextStringAfter($tokens, $i);
                if ($left !== null && $right !== null && $this->isCallSite($tokens, $i, $right)) {
                    // ANY `X::method()` is a STATIC call (including self::/static::/parent::). The `self` type
                    // is reserved for `$this->method()` — a same-instance dispatch, not a static call.
                    $edges[] = ['from' => $currentMethod, 'to' => $left.'::'.$right, 'type' => 'static'];
                }

                continue;
            }

            if ($id === T_OBJECT_OPERATOR) {
                // `$obj->name(` instance, `$this->name(` self, `$obj->$dyn(` dynamic dispatch.
                $right = $this->nextNonWsToken($tokens, $i);
                if ($right === null) {
                    continue;
                }
                [$rid, $rtext] = $right['token'];
                if ($rid === T_VARIABLE) {
                    // dynamic dispatch — emit disagreement signal, no edge
                    if ($this->isCallSiteFromOffset($tokens, $right['offset'] + 1)) {
                        $disagreement[] = 'dynamic_dispatch';
                    }

                    continue;
                }
                if ($rid === T_STRING && $this->isCallSiteFromOffset($tokens, $right['offset'] + 1)) {
                    $receiver = $this->prevVariableOrThis($tokens, $i);
                    $type = $receiver === '$this' ? 'self' : 'instance';
                    $edges[] = ['from' => $currentMethod, 'to' => 'instance::'.$rtext, 'type' => $type];
                }
            }
        }

        $disagreement = array_values(array_unique($disagreement));

        return ['edges' => $edges, 'disagreement' => $disagreement, 'method_names' => $methodNames];
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private function methodHasBody(array $tokens, int $from): bool
    {
        $n = count($tokens);
        $parenDepth = 0;
        for ($j = $from + 1; $j < $n; $j++) {
            $text = $tokens[$j][1];
            if ($text === '(') {
                $parenDepth++;
            } elseif ($text === ')') {
                $parenDepth--;
            } elseif ($parenDepth === 0 && $text === '{') {
                return true;
            } elseif ($parenDepth === 0 && $text === ';') {
                return false;
            }
        }

        return false;
    }

    private function nextStringAfter(array $tokens, int $from): ?string
    {
        $n = count($tokens);
        for ($j = $from + 1; $j < $n; $j++) {
            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if ($tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private function prevStringOrSelf(array $tokens, int $from): ?string
    {
        for ($j = $from - 1; $j >= 0; $j--) {
            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_STATIC) {
                return $tokens[$j][1];
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return null|array{token:array{0:int|null,1:string,2:int}, offset:int}
     */
    private function nextNonWsToken(array $tokens, int $from): ?array
    {
        $n = count($tokens);
        for ($j = $from + 1; $j < $n; $j++) {
            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return ['token' => $tokens[$j], 'offset' => $j];
        }

        return null;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private function isCallSite(array $tokens, int $colonOffset, string $methodName): bool
    {
        $n = count($tokens);
        // Find the methodName token after $colonOffset, then peek for `(`.
        for ($j = $colonOffset + 1; $j < $n; $j++) {
            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if ($tokens[$j][0] === T_STRING && $tokens[$j][1] === $methodName) {
                return $this->isCallSiteFromOffset($tokens, $j + 1);
            }

            return false;
        }

        return false;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private function isCallSiteFromOffset(array $tokens, int $offset): bool
    {
        $n = count($tokens);
        for ($j = $offset; $j < $n; $j++) {
            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return $tokens[$j][1] === '(';
        }

        return false;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private function prevVariableOrThis(array $tokens, int $arrowOffset): string
    {
        for ($j = $arrowOffset - 1; $j >= 0; $j--) {
            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if ($tokens[$j][0] === T_VARIABLE) {
                return $tokens[$j][1];
            }

            break;
        }

        return '';
    }

    /**
     * @param  list<array{from:string,to:string,type:string}>  $edges
     * @param  list<string>  $methodNames
     */
    private function computeFanIn(array $edges, array $methodNames): int
    {
        $hits = 0;
        foreach ($edges as $edge) {
            $toBase = substr((string) $edge['to'], strrpos((string) $edge['to'], ':') !== false ? strrpos((string) $edge['to'], ':') + 1 : 0);
            if (in_array($toBase, $methodNames, true)) {
                $hits++;
            }
        }

        return $hits;
    }
}
