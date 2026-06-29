<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * CORTEX COUNCIL — LENS #2 of 5: DATA-FLOW. Observes a {@see CortexSubject} (PHP source) and emits FACTS
 * about its data-flow shape: per method, the read-set ($this->prop reads) + write-set ($this->prop writes) +
 * params + return shape, plus cross-method state coupling (two methods writing the same property). Magic
 * accessors ($this->$dynamic or __get) appear in `disagreement_signals` so the council sees the lens dissent
 * from a "complete data-flow" reading — never silently dropped.
 *
 * NO scores, NO weighting ({@see LensObservation} enforces it). Pure, deterministic, provider-safe.
 *
 * The subject's facts may carry either:
 *   - `source_code` : raw PHP source string
 *   - `file_path`   : absolute path the lens will read once
 */
final class AtlasCortexDataFlowLens implements LensContract
{
    public function id(): string
    {
        return 'dataflow';
    }

    public function name(): string
    {
        return 'Data-Flow';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        $source = $this->resolveSource($subject);
        if ($source === null) {
            return new LensObservation($this->id(), $subject->id, [
                'methods' => [],
                'coupling' => [],
            ], ['source_unresolved']);
        }

        $parsed = $this->parse($source);
        $methods = $parsed['methods'];
        $disagreement = $parsed['disagreement'];

        // Cross-method state coupling: two methods writing the same property.
        $coupling = $this->coupling($methods);

        // Flatten methods into the FACTS bag — kind:method_data_flow per method, kind:state_coupling per pair.
        $facts = [
            'methods' => $methods,
            'coupling' => $coupling,
        ];

        return new LensObservation($this->id(), $subject->id, $facts, $disagreement);
    }

    /**
     * @return array{methods:list<array<string,mixed>>, disagreement:list<string>}
     */
    private function parse(string $source): array
    {
        $tokens = @token_get_all($source);
        if (! is_array($tokens) || $tokens === []) {
            return ['methods' => [], 'disagreement' => ['source_unparseable']];
        }

        $methods = [];
        $disagreement = [];
        $depth = 0; // brace depth from start of file
        $classDepth = null; // brace depth where we entered the class body (1 deeper than class declaration line)
        $inClass = false;
        $currentMethod = null;
        $currentMethodBraceDepth = null;
        $currentRead = [];
        $currentWrite = [];
        $currentParams = [];
        $currentReturns = 0;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            if (is_string($tok)) {
                if ($tok === '{') {
                    $depth++;
                } elseif ($tok === '}') {
                    if ($currentMethod !== null && $depth === $currentMethodBraceDepth) {
                        $methods[] = [
                            'kind' => 'method_data_flow',
                            'method' => $currentMethod,
                            'read_set' => array_values(array_unique($currentRead)),
                            'write_set' => array_values(array_unique($currentWrite)),
                            'params' => $currentParams,
                            'returns' => $currentReturns,
                        ];
                        $currentMethod = null;
                        $currentMethodBraceDepth = null;
                        $currentRead = [];
                        $currentWrite = [];
                        $currentParams = [];
                        $currentReturns = 0;
                    }
                    if ($inClass && $depth === $classDepth) {
                        $inClass = false;
                        $classDepth = null;
                    }
                    $depth--;
                }

                continue;
            }

            [$id, $text] = [$tok[0], $tok[1]];

            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) {
                // Enter class body at the NEXT '{' at depth+1
                $inClass = true;
                $classDepth = $depth + 1;

                continue;
            }

            if ($inClass && $id === T_FUNCTION && $currentMethod === null) {
                // Read method name + params from the next tokens.
                $name = $this->seekMethodName($tokens, $i, $count);
                if ($name === null) {
                    continue;
                }
                if (! $this->methodHasBody($tokens, $i, $count)) {
                    continue; // bodyless abstract/interface method — skip to avoid poisoning next real method
                }
                $currentMethod = $name;
                $currentParams = $this->collectParams($tokens, $i, $count);
                $currentMethodBraceDepth = $depth + 1; // the opening '{' will bump $depth to $currentMethodBraceDepth

                continue;
            }

            if ($currentMethod === null) {
                continue;
            }

            // Inside a method body — track $this->prop reads/writes + return statements.
            if ($id === T_RETURN) {
                $currentReturns++;

                continue;
            }

            if ($id === T_VARIABLE && $text === '$this') {
                $accessor = $this->lookaheadObjectAccess($tokens, $i, $count);
                if ($accessor === null) {
                    continue;
                }
                if ($accessor['dynamic']) {
                    $disagreement[] = 'dynamic_property_access_in:'.$currentMethod;

                    continue;
                }
                $prop = (string) $accessor['name'];
                if ($accessor['is_write']) {
                    $currentWrite[] = $prop;
                } else {
                    $currentRead[] = $prop;
                }
            }
        }

        return ['methods' => $methods, 'disagreement' => array_values(array_unique($disagreement))];
    }

    /**
     * @param  array<int,array{0:int,1:string,2:int}|string>  $tokens
     */
    private function methodHasBody(array $tokens, int $from, int $count): bool
    {
        $parenDepth = 0;
        for ($j = $from + 1; $j < $count; $j++) {
            $tok = $tokens[$j];
            $text = is_string($tok) ? $tok : $tok[1];
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

    private function seekMethodName(array $tokens, int $from, int $count): ?string
    {
        for ($i = $from + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '(') {
                    return null; // anonymous function
                }

                continue;
            }
            if ($t[0] === T_WHITESPACE) {
                continue;
            }
            if ($t[0] === T_STRING) {
                return (string) $t[1];
            }
            // Reference '&' or other prefix
            if ($t[0] === T_STRING || $t[0] === T_NS_SEPARATOR) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>  parameter variable names (without leading $)
     */
    private function collectParams(array $tokens, int $from, int $count): array
    {
        $params = [];
        $inParens = 0;
        $started = false;
        for ($i = $from + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '(') {
                    $inParens++;
                    $started = true;

                    continue;
                }
                if ($t === ')') {
                    $inParens--;
                    if ($started && $inParens === 0) {
                        return $params;
                    }

                    continue;
                }

                continue;
            }
            if (! $started) {
                continue;
            }
            if ($t[0] === T_VARIABLE) {
                $params[] = ltrim((string) $t[1], '$');
            }
        }

        return $params;
    }

    /**
     * Look ahead from a `$this` token to determine whether it's a property access and whether write or read.
     *
     * @param  array<int,array{0:int,1:string,2:int}|string>  $tokens
     * @return array{name:?string,is_write:bool,dynamic:bool}|null  null when no -> follows
     */
    private function lookaheadObjectAccess(array $tokens, int $from, int $count): ?array
    {
        $i = $from + 1;
        // skip whitespace
        while ($i < $count && ! is_string($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }
        if ($i >= $count) {
            return null;
        }
        $t = $tokens[$i];
        if (is_string($t) || $t[0] !== T_OBJECT_OPERATOR) {
            return null;
        }
        // After ->, expect property name (T_STRING) or dynamic (T_VARIABLE or '{')
        $i++;
        while ($i < $count && ! is_string($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }
        if ($i >= $count) {
            return null;
        }
        $next = $tokens[$i];

        if (is_string($next)) {
            if ($next === '{') {
                return ['name' => null, 'is_write' => false, 'dynamic' => true];
            }

            return null;
        }
        if ($next[0] === T_VARIABLE) {
            return ['name' => null, 'is_write' => false, 'dynamic' => true];
        }
        if ($next[0] !== T_STRING) {
            return null;
        }
        $propName = (string) $next[1];
        $i++;

        // If immediately followed by '(' (after optional whitespace) it's a method call, not a property access.
        $j = $i;
        while ($j < $count && ! is_string($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j < $count && is_string($tokens[$j]) && $tokens[$j] === '(') {
            return null; // method call, not property — out of scope for this lens
        }

        // Determine write vs read: write iff the next non-whitespace, non-bracket-access token is `=` (and not `==`, `===`, `=>`).
        $k = $j;
        // Skip past `[...]` subscripts (we still treat $this->prop[x] = y as a WRITE to prop).
        $brackets = 0;
        while ($k < $count) {
            $kt = $tokens[$k];
            if (is_string($kt)) {
                if ($kt === '[') {
                    $brackets++;
                    $k++;

                    continue;
                }
                if ($kt === ']') {
                    $brackets--;
                    $k++;

                    continue;
                }
                if ($brackets > 0) {
                    $k++;

                    continue;
                }
                if ($kt === '=') {
                    return ['name' => $propName, 'is_write' => true, 'dynamic' => false];
                }
                break;
            }
            if ($kt[0] === T_WHITESPACE) {
                $k++;

                continue;
            }
            if ($brackets > 0) {
                $k++;

                continue;
            }
            // Compound assignments (.=, +=, -=, etc.) count as WRITES.
            if (in_array($kt[0], [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL], true)) {
                return ['name' => $propName, 'is_write' => true, 'dynamic' => false];
            }
            // T_DOUBLE_ARROW means we're in an array literal context — not an assignment.
            if ($kt[0] === T_DOUBLE_ARROW) {
                break;
            }
            break;
        }

        return ['name' => $propName, 'is_write' => false, 'dynamic' => false];
    }

    /**
     * @param  list<array<string,mixed>>  $methods
     * @return list<array{kind:string, property:string, methods:list<string>}>
     */
    private function coupling(array $methods): array
    {
        $byProp = [];
        foreach ($methods as $m) {
            foreach ((array) ($m['write_set'] ?? []) as $prop) {
                $byProp[(string) $prop] ??= [];
                $byProp[(string) $prop][] = (string) $m['method'];
            }
        }
        $coupling = [];
        foreach ($byProp as $prop => $methodList) {
            $unique = array_values(array_unique($methodList));
            if (count($unique) >= 2) {
                sort($unique, SORT_STRING);
                $coupling[] = ['kind' => 'state_coupling', 'property' => $prop, 'methods' => $unique];
            }
        }
        usort($coupling, static fn (array $x, array $y): int => $x['property'] <=> $y['property']);

        return $coupling;
    }

    private function resolveSource(CortexSubject $subject): ?string
    {
        $facts = $subject->facts;
        if (isset($facts['source_code']) && is_string($facts['source_code']) && $facts['source_code'] !== '') {
            return (string) $facts['source_code'];
        }
        $path = isset($facts['file_path']) ? (string) $facts['file_path'] : '';
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return $contents === false || $contents === '' ? null : $contents;
    }
}
