<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * MAXC-02 — Honest sufficiency block for the assembled pack.
 *
 * Consumes the facet list from `TaskFacetExtractor` and the delivered pack
 * items (code_graph symbols, memory ids, reality-graph paths) and emits a
 * per-facet coverage report + `not_enough_context` boolean when any ESSENTIAL
 * facet has zero delivered refs.
 *
 * ANTI-GOODHART invariants (COM-04 / COM-11):
 *   - NEVER writes `context_sufficiency` on the ARFL event.
 *   - NEVER emits a fabricated sufficiency scalar (no "92").
 *   - Only raw coverage + named gaps + expand handles.
 */
final class PackSufficiencyBlockBuilder
{
    /**
     * @param  array<int,array{type:string,value:string,essential:bool}>  $facets
     * @param  array<int,array<string,mixed>>  $codeItems
     * @param  array<int,array<string,mixed>>  $memoryItems
     * @param  array<int,array<string,mixed>>  $realityPaths
     * @return array{
     *   present:bool,
     *   not_enough_context:bool,
     *   facets:array<int,array{type:string,value:string,essential:bool,refs_delivered:int,sources:array<int,string>}>,
     *   missing_essential:array<int,array{type:string,value:string,handle:string}>,
     *   handles:array<int,string>
     * }
     */
    public function build(array $facets, array $codeItems, array $memoryItems, array $realityPaths): array
    {
        if ($facets === []) {
            return [
                'present' => false,
                'not_enough_context' => false,
                'facets' => [],
                'missing_essential' => [],
                'handles' => [],
            ];
        }

        $codeText = $this->flattenText($codeItems, ['symbol', 'file_path', 'id', 'signature']);
        $memoryText = $this->flattenText($memoryItems, ['title', 'id', 'summary']);
        $realityText = $this->flattenPaths($realityPaths);

        $facetReport = [];
        $missing = [];
        foreach ($facets as $facet) {
            $value = (string) ($facet['value'] ?? '');
            $type = (string) ($facet['type'] ?? '');
            $essential = (bool) ($facet['essential'] ?? false);
            if ($value === '') {
                continue;
            }

            $sources = [];
            $refs = 0;
            $lower = mb_strtolower($value);

            if ($this->hitInText($codeText, $lower)) {
                $sources[] = 'code_graph';
                $refs++;
            }
            if ($this->hitInText($memoryText, $lower)) {
                $sources[] = 'memory';
                $refs++;
            }
            if ($this->hitInText($realityText, $lower)) {
                $sources[] = 'reality_graph';
                $refs++;
            }

            $facetReport[] = [
                'type' => $type,
                'value' => $value,
                'essential' => $essential,
                'refs_delivered' => $refs,
                'sources' => $sources,
            ];

            if ($essential && $refs === 0) {
                $missing[] = [
                    'type' => $type,
                    'value' => $value,
                    'handle' => 'expand:'.$type.':'.$value,
                ];
            }
        }

        $handles = array_map(static fn (array $m): string => (string) $m['handle'], $missing);

        return [
            'present' => true,
            'not_enough_context' => $missing !== [],
            'facets' => $facetReport,
            'missing_essential' => $missing,
            'handles' => $handles,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,string>  $keys
     */
    private function flattenText(array $items, array $keys): string
    {
        $parts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = $item[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @param  array<int,array<string,mixed>>  $paths
     */
    private function flattenPaths(array $paths): string
    {
        $parts = [];
        foreach ($paths as $path) {
            if (! is_array($path)) {
                continue;
            }
            foreach (['from', 'to', 'nodes', 'kind', 'label'] as $key) {
                $value = $path[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                } elseif (is_array($value)) {
                    foreach ($value as $sub) {
                        if (is_string($sub) && $sub !== '') {
                            $parts[] = $sub;
                        } elseif (is_array($sub)) {
                            foreach ($sub as $leaf) {
                                if (is_string($leaf) && $leaf !== '') {
                                    $parts[] = $leaf;
                                }
                            }
                        }
                    }
                }
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    private function hitInText(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return str_contains($haystack, $needle);
    }
}
