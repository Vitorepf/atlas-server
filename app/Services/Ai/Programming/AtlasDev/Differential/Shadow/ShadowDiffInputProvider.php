<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;


/**
 * E4 -- Generates probe inputs for a pure-function signature.
 *
 * Shadow-diffing old vs new requires a set of probe inputs to exercise both
 * versions. The provider parses the function's parameter list (a fragment of
 * PHP source like `int $x, string $y = 'a'`) and emits a small diverse set
 * of argument tuples that exercise common paths without exploding
 * combinatorially.
 *
 * The generation is deterministic, type-aware, and CAPPED so the harness
 * does not run an unbounded number of probes (which would be slow and could
 * mask a divergence in noise). Each parameter is assigned a small list of
 * representative values based on its declared type; the provider then emits
 * the Cartesian product but caps the total at {@see self::MAX_PROBES}.
 *
 * Conservative defaults:
 *   - int / ?int       => [0, 1, -1, 42]
 *   - float            => [0.0, 1.5, -1.0]
 *   - string           => ['', 'a', 'test']
 *   - bool             => [true, false]
 *   - array / iterable => [[], [1, 2, 3]]
 *   - nullable types   => null is added to the representative set
 *   - untyped / mixed  => [null, 0, '', []]
 *   - class types      => [null] (we cannot construct arbitrary objects
 *                          safely; null is the only safe probe for typed
 *                          object params, and nullable-typed object params
 *                          already include null above)
 *
 * The resulting tuples are the harness's positional argument lists. The
 * harness MUST tolerate a probe that does not match a declared type (it
 * catches TypeError internally and reports the symbol as a skip).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffInputProvider
{
    /**
     * Maximum number of probe tuples emitted per function. Keeps the
     * subprocess harness fast and the diff readable. The cap is applied
     * AFTER the Cartesian product so the first (most diverse) tuples win.
     */
    public const MAX_PROBES = 12;

    /**
     * @return list<list<mixed>>
     */
    public function inputsForSignature(string $parameterList): array
    {
        $parameters = $this->parseParameters($parameterList);
        if ($parameters === []) {
            // No parameters: a single empty probe tuple.
            return [[]];
        }

        $valueSets = [];
        foreach ($parameters as $param) {
            $valueSets[] = $this->representativeValuesFor($param);
        }

        $tuples = $this->cartesianProduct($valueSets);

        // Cap to MAX_PROBES tuples (preserve the first, most diverse ones).
        return array_slice($tuples, 0, self::MAX_PROBES);
    }

    /**
     * Parse a PHP parameter list fragment into an array of typed parameter
     * descriptors. Each descriptor is ['type' => string|null, 'nullable' => bool,
     * 'name' => string, 'has_default' => bool].
     *
     * @return list<array{type: ?string, nullable: bool, name: string, has_default: bool}>
     */
    private function parseParameters(string $parameterList): array
    {
        $parameterList = trim($parameterList);
        if ($parameterList === '') {
            return [];
        }

        // Split on top-level commas (parameters can contain default values
        // with commas inside arrays; a depth counter handles that).
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($parameterList) as $ch) {
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        $parameters = [];
        foreach ($parts as $part) {
            $parameters[] = $this->parseSingleParameter($part);
        }

        return $parameters;
    }

    /**
     * @return array{type: ?string, nullable: bool, name: string, has_default: bool}
     */
    private function parseSingleParameter(string $part): array
    {
        $hasDefault = str_contains($part, '=');
        $nullable = false;
        $type = null;
        $name = '';

        // Strip default value: everything after the first top-level '='.
        if ($hasDefault) {
            $eqDepth = 0;
            $cut = strlen($part);
            for ($i = 0; $i < strlen($part); $i++) {
                if ($part[$i] === '(' || $part[$i] === '[') {
                    $eqDepth++;
                } elseif ($part[$i] === ')' || $part[$i] === ']') {
                    $eqDepth--;
                } elseif ($part[$i] === '=' && $eqDepth === 0) {
                    $cut = $i;
                    break;
                }
            }
            $part = trim(substr($part, 0, $cut));
        }

        // Detect nullable prefix '?'.
        if (preg_match('/^\?\s*(.+)$/', $part, $m)) {
            $nullable = true;
            $part = trim($m[1]);
        }

        // Union/intersection types contain '|' or '&'. Take the first only
        // (conservative: probe the first declared type).
        if (preg_match('/^([^(]+?)\s*&\s*\$(\w+)/', $part, $m)) {
            // intersection type before $var: take first
            $firstType = trim(explode('&', $m[1])[0]);
            $type = $firstType !== '' ? $firstType : null;
            $name = $m[2];
        } elseif (preg_match('/^([^(|]+?)(?:\s*\|.*)?\s+\$(\w+)/', $part, $m)) {
            // typed param with optional union tail: 'int|string $x'
            $type = trim($m[1]);
            $name = $m[2];
        } elseif (preg_match('/^\$(\w+)/', $part, $m)) {
            // untyped param: '$x'
            $type = null;
            $name = $m[1];
        } else {
            // Fallback: the whole fragment is the name.
            $name = $part;
        }

        // 'null' in a union type also means nullable.
        if ($type !== null && preg_match('/\bnull\b/i', $type)) {
            $nullable = true;
        }

        return [
            'type' => $type,
            'nullable' => $nullable,
            'name' => $name,
            'has_default' => $hasDefault,
        ];
    }

    /**
     * @param  array{type: ?string, nullable: bool, name: string, has_default: bool}  $param
     * @return list<mixed>
     */
    private function representativeValuesFor(array $param): array
    {
        $type = strtolower((string) $param['type']);
        $values = match ($type) {
            'int', 'integer' => [0, 1, -1, 42],
            'float', 'double' => [0.0, 1.5, -1.0],
            'string' => ['', 'a', 'test'],
            'bool', 'boolean' => [true, false],
            'array', 'iterable' => [[], [1, 2, 3]],
            default => [null, 0, '', []], // untyped / class-type / mixed
        };

        // For class-typed params we cannot safely construct an instance;
        // probe null only (and only when nullable, otherwise the harness
        // will TypeError and the symbol is skipped — honest behavior).
        if ($type !== '' && ! in_array($type, ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'iterable', 'mixed', 'null', 'void', 'object'], true)) {
            $values = $param['nullable'] ? [null] : [null];
            // A non-nullable class-typed param probed with null will cause a
            // TypeError at call time; the harness catches it and the service
            // marks the symbol as skipped-with-reason (honest: we cannot
            // safely probe a function requiring a real object instance).
        }

        if ($param['nullable'] && ! in_array(null, $values, true)) {
            $values[] = null;
        }

        return $values;
    }

    /**
     * Cartesian product of the value sets.
     *
     * @param  list<list<mixed>>  $sets
     * @return list<list<mixed>>
     */
    private function cartesianProduct(array $sets): array
    {
        $result = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($set as $value) {
                    $next[] = array_merge($partial, [$value]);
                }
            }
            $result = $next;
        }

        return $result;
    }
}
