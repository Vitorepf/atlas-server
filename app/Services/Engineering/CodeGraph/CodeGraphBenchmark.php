<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Token-economy benchmark for the code graph (AP-811/812, FRONT 3 — harden/prove).
 *
 * Quantifies the one claim the whole code-graph stack rests on: that answering a
 * structural question by traversing the resolved graph costs far fewer tokens than
 * feeding a model the naive context (the raw files / blobs) it would otherwise need
 * to read. Each sample is a single answered question measured both ways:
 *  - `graph_tokens`: tokens spent when the answer rides on the graph read-model
 *    (the compact {from,to,edge_type} edge set + the node neighbourhood);
 *  - `naive_tokens`: tokens the same answer would have cost reading the source blobs.
 *
 * The transform is deliberately small and total: sum both columns, derive the
 * reduction ratio (naive/graph — "graph is N× cheaper") and the reduction percent
 * (how much of the naive budget the graph saves), and echo a normalised per-sample
 * breakdown. It MEASURES; it never collects samples, never touches a tokenizer,
 * never decides anything about providers, models, routing or policy. The caller
 * (the orchestrator / artisan layer) supplies real measured counts; this service
 * only does the arithmetic, deterministically.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime, no clock, no
 * randomness — identical input always yields byte-identical output. A read-model
 * that FEEDS reporting; it is not a parallel decision brain.
 *
 * @phpstan-type Sample array{graph_tokens?:int|float|string, naive_tokens?:int|float|string, label?:string}
 */
class CodeGraphBenchmark
{
    public const SCHEMA = 'atlas.code_graph.benchmark.v1';

    /**
     * Measure the token economy across a set of samples.
     *
     * Each sample contributes its `graph_tokens` and `naive_tokens` to the totals.
     * Per-sample, both the ratio (naive/graph) and the percent saved are derived with
     * the same divide-by-zero discipline as the aggregate: when the denominator is
     * zero the derived figure is `0.0` rather than INF/NaN, so the output is always a
     * finite, serialisable number. Non-numeric / negative counts are clamped to a
     * non-negative integer so a malformed sample can never poison the totals.
     *
     * Aggregate semantics:
     *  - `reduction_ratio` = total_naive / total_graph (graph is N× cheaper);
     *    `0.0` when `total_graph_tokens` is 0.
     *  - `reduction_pct`   = (1 - total_graph / total_naive) * 100 (percent of the
     *    naive budget the graph saves); `0.0` when `total_naive_tokens` is 0.
     *
     * @param  array<int,array<string,mixed>>  $samples
     * @return array{
     *   schema_version:string,
     *   sample_count:int,
     *   total_graph_tokens:int,
     *   total_naive_tokens:int,
     *   reduction_ratio:float,
     *   reduction_pct:float,
     *   per_sample:array<int,array{
     *     index:int,
     *     label:string,
     *     graph_tokens:int,
     *     naive_tokens:int,
     *     saved_tokens:int,
     *     reduction_ratio:float,
     *     reduction_pct:float
     *   }>
     * }
     */
    public function measure(array $samples): array
    {
        $totalGraph = 0;
        $totalNaive = 0;
        $perSample = [];
        $index = 0;

        foreach ($samples as $sample) {
            if (! is_array($sample)) {
                continue;
            }

            $graph = $this->count($sample['graph_tokens'] ?? null);
            $naive = $this->count($sample['naive_tokens'] ?? null);

            $totalGraph += $graph;
            $totalNaive += $naive;

            $perSample[] = [
                'index' => $index,
                'label' => $this->label($sample['label'] ?? null, $index),
                'graph_tokens' => $graph,
                'naive_tokens' => $naive,
                'saved_tokens' => max(0, $naive - $graph),
                'reduction_ratio' => $this->ratio($naive, $graph),
                'reduction_pct' => $this->pct($graph, $naive),
            ];

            $index++;
        }

        return [
            'schema_version' => self::SCHEMA,
            'sample_count' => count($perSample),
            'total_graph_tokens' => $totalGraph,
            'total_naive_tokens' => $totalNaive,
            'reduction_ratio' => $this->ratio($totalNaive, $totalGraph),
            'reduction_pct' => $this->pct($totalGraph, $totalNaive),
            'per_sample' => $perSample,
        ];
    }

    /**
     * naive / graph — "the graph answer is N× cheaper than naive". Returns 0.0 when
     * the graph cost is 0 (no division by zero; a finite, serialisable result).
     */
    private function ratio(int $naive, int $graph): float
    {
        if ($graph === 0) {
            return 0.0;
        }

        return $naive / $graph;
    }

    /**
     * (1 - graph / naive) * 100 — percent of the naive token budget the graph saves.
     * Returns 0.0 when the naive cost is 0 (no division by zero).
     */
    private function pct(int $graph, int $naive): float
    {
        if ($naive === 0) {
            return 0.0;
        }

        return (1 - $graph / $naive) * 100;
    }

    /**
     * Coerce a measured count to a non-negative integer. Accepts int, numeric float,
     * or numeric string; anything else (or a negative) becomes 0 so a malformed
     * sample can never inject INF/NaN/negative tokens into the totals.
     */
    private function count(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_float($value) && is_finite($value)) {
            return max(0, (int) $value);
        }
        if (is_string($value) && is_numeric($value)) {
            $f = (float) $value;

            return is_finite($f) ? max(0, (int) $f) : 0;
        }

        return 0;
    }

    private function label(mixed $value, int $index): string
    {
        if (is_string($value)) {
            $t = trim($value);
            if ($t !== '') {
                return $t;
            }
        }

        return 'sample_'.$index;
    }
}
