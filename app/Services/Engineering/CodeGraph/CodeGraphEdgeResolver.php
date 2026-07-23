<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Resolves the per-file relations that {@see \App\Services\Engineering\EngineeringCodeIntelligenceService}
 * already extracts into real, deduplicated, confidence-graded code-graph edges.
 *
 * This is the P0 keystone of AP-811. Today the world-model edges
 * ({@see \App\Models\AiCodebaseWorldModelEdge}) are fixture-seeded in
 * AtlasAutonomousEngineeringService::buildWorldModel(); this resolver produces
 * the real edges from actual `use`/reference/test relations so the existing
 * {@see \App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker}
 * and traversal operate on truth instead of fixtures.
 *
 * Pure transform: no DB, no IO, no provider. Storage/runtime live in adapters.
 *
 * Techniques captured (as technique, not authority) from the graphify dissection:
 *  - import-evidence confidence promotion (a reference becomes EXTRACTED when the
 *    same file also imports the symbol; otherwise it stays INFERRED);
 *  - single-candidate / existence gating (only emit an edge to a module that
 *    actually exists in the index — never invent a god-node target);
 *  - explicit EXTRACTED / INFERRED / AMBIGUOUS confidence labels with scores.
 */
class CodeGraphEdgeResolver
{
    public const SCHEMA = 'atlas.code_graph.resolved_edges.v1';

    public const CONFIDENCE_EXTRACTED = 'EXTRACTED';
    public const CONFIDENCE_INFERRED = 'INFERRED';
    public const CONFIDENCE_AMBIGUOUS = 'AMBIGUOUS';

    /**
     * Edge types intentionally restricted to the set WorldModelGraphRanker
     * already weights, so resolved edges score immediately without changing the
     * ranker. The precise original relation kind is preserved in metadata.
     */
    private const EDGE_DEPENDS_ON = 'depends_on';
    private const EDGE_TESTS = 'tests';

    private const SCORE_EXTRACTED = 1.0;
    private const SCORE_REFERENCE_INFERRED = 0.85;
    private const SCORE_TEST_INFERRED = 0.8;

    /**
     * @param  array<int,array<string,mixed>>  $fileRecords  per-file relation records:
     *   each = {file_path:string, module_slug:string, relations:{dependencies, symbol_references, test_targets}}
     * @param  callable(string):?string  $nodeIdForModule  maps a module slug to a world-model node_id
     *   (e.g. "node:app/Services/Ai/Router"); return null when the module is unknown.
     * @return array{schema_version:string, edges:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    public function resolve(array $fileRecords, callable $nodeIdForModule): array
    {
        /** @var array<string,array<string,mixed>> $edges keyed by from|to|type */
        $edges = [];
        $stats = [
            'files' => 0,
            'extracted' => 0,
            'inferred' => 0,
            'skipped_unresolved' => 0,
            'skipped_self' => 0,
            'deduped' => 0,
        ];

        foreach ($fileRecords as $record) {
            if (! is_array($record)) {
                continue;
            }
            $stats['files']++;

            $fromModule = $this->stringOrNull($record['module_slug'] ?? null);
            $relations = is_array($record['relations'] ?? null) ? $record['relations'] : [];
            $filePath = $this->stringOrNull($record['file_path'] ?? null) ?? '';

            $dependencies = $this->rows($relations['dependencies'] ?? null);
            $references = $this->rows($relations['symbol_references'] ?? null);
            $testTargets = $this->rows($relations['test_targets'] ?? null);

            // Per-file import evidence: which symbols this file actually `use`s.
            $imported = [];
            foreach ($dependencies as $dep) {
                $symbol = $this->normalizeSymbol($dep['symbol'] ?? null);
                if ($symbol !== null) {
                    $imported[$symbol] = true;
                }
            }

            // dependencies -> depends_on, EXTRACTED (a real import statement).
            foreach ($dependencies as $dep) {
                $from = $fromModule ?? $this->stringOrNull($dep['from_module'] ?? null);
                $this->addEdge(
                    $edges, $stats, $nodeIdForModule,
                    fromModule: $from,
                    toModule: $this->stringOrNull($dep['to_module'] ?? null),
                    edgeType: self::EDGE_DEPENDS_ON,
                    confidence: self::CONFIDENCE_EXTRACTED,
                    score: self::SCORE_EXTRACTED,
                    relationKind: $this->stringOrNull($dep['kind'] ?? null) ?? 'dependency',
                    sourceRef: $this->sourceRef($dep['file_path'] ?? $filePath, $dep['line'] ?? null),
                );
            }

            // symbol_references -> depends_on. Import-evidence promotion: EXTRACTED
            // when the file also imports the symbol, otherwise INFERRED.
            foreach ($references as $ref) {
                $symbol = $this->normalizeSymbol($ref['symbol'] ?? null);
                $promoted = $symbol !== null && isset($imported[$symbol]);
                $this->addEdge(
                    $edges, $stats, $nodeIdForModule,
                    fromModule: $fromModule,
                    toModule: $this->stringOrNull($ref['target_module'] ?? null),
                    edgeType: self::EDGE_DEPENDS_ON,
                    confidence: $promoted ? self::CONFIDENCE_EXTRACTED : self::CONFIDENCE_INFERRED,
                    score: $promoted ? self::SCORE_EXTRACTED : self::SCORE_REFERENCE_INFERRED,
                    relationKind: $this->stringOrNull($ref['kind'] ?? null) ?? 'reference',
                    sourceRef: $this->sourceRef($ref['file_path'] ?? $filePath, $ref['line'] ?? null),
                );
            }

            // test_targets -> tests, INFERRED (heuristic name match), promoted to
            // EXTRACTED when the test file actually imports the referenced symbol.
            foreach ($testTargets as $test) {
                $symbol = $this->normalizeSymbol($test['symbol'] ?? null);
                $promoted = $symbol !== null && isset($imported[$symbol]);
                $this->addEdge(
                    $edges, $stats, $nodeIdForModule,
                    fromModule: $fromModule,
                    toModule: $this->stringOrNull($test['target_module'] ?? null),
                    edgeType: self::EDGE_TESTS,
                    confidence: $promoted ? self::CONFIDENCE_EXTRACTED : self::CONFIDENCE_INFERRED,
                    score: $promoted ? self::SCORE_EXTRACTED : self::SCORE_TEST_INFERRED,
                    relationKind: $this->stringOrNull($test['kind'] ?? null) ?? 'test_symbol_reference',
                    sourceRef: $this->sourceRef($test['test_path'] ?? $filePath, $test['line'] ?? null),
                );
            }
        }

        $edgeList = array_values($edges);
        usort(
            $edgeList,
            static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']]
                <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']],
        );

        return [
            'schema_version' => self::SCHEMA,
            'edges' => $edgeList,
            'stats' => $stats,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,int>  $stats
     * @param  callable(string):?string  $nodeIdForModule
     */
    private function addEdge(
        array &$edges,
        array &$stats,
        callable $nodeIdForModule,
        ?string $fromModule,
        ?string $toModule,
        string $edgeType,
        string $confidence,
        float $score,
        string $relationKind,
        ?string $sourceRef,
    ): void {
        if ($fromModule === null || $toModule === null) {
            $stats['skipped_unresolved']++;

            return;
        }
        if ($fromModule === $toModule) {
            $stats['skipped_self']++;

            return;
        }

        // Single-candidate / existence gate: only link to a module that resolves
        // to a real node. Unknown targets are dropped, never invented.
        $fromNode = $nodeIdForModule($fromModule);
        $toNode = $nodeIdForModule($toModule);
        if ($fromNode === null || $toNode === null) {
            $stats['skipped_unresolved']++;

            return;
        }

        $key = $fromNode.'|'.$toNode.'|'.$edgeType;
        if (isset($edges[$key])) {
            $stats['deduped']++;
            $existing = &$edges[$key];
            $existing['metadata']['occurrences']++;
            // Keep the strongest evidence for a deduped edge.
            if ($score > $existing['confidence_score']) {
                $existing['confidence'] = $confidence;
                $existing['confidence_score'] = $score;
                $existing['metadata']['relation'] = $relationKind;
                if ($sourceRef !== null) {
                    $existing['metadata']['source_ref'] = $sourceRef;
                }
            }

            return;
        }

        $confidence === self::CONFIDENCE_EXTRACTED ? $stats['extracted']++ : $stats['inferred']++;

        $edges[$key] = [
            'from_node_id' => $fromNode,
            'to_node_id' => $toNode,
            'edge_type' => $edgeType,
            'confidence' => $confidence,
            'confidence_score' => $score,
            'metadata' => array_filter([
                'resolver' => self::SCHEMA,
                'relation' => $relationKind,
                'confidence' => $confidence,
                'confidence_score' => $score,
                'source_ref' => $sourceRef,
                'occurrences' => 1,
                'inferred' => $confidence !== self::CONFIDENCE_EXTRACTED,
            ], static fn (mixed $v): bool => $v !== null),
        ];
    }

    /**
     * @param  mixed  $rows
     * @return array<int,array<string,mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeSymbol(mixed $symbol): ?string
    {
        $value = $this->stringOrNull($symbol);
        if ($value === null) {
            return null;
        }

        return ltrim(strtolower($value), '\\');
    }

    private function sourceRef(mixed $path, mixed $line): ?string
    {
        $pathStr = $this->stringOrNull($path);
        if ($pathStr === null) {
            return null;
        }
        $lineInt = is_int($line) ? $line : (is_string($line) && ctype_digit($line) ? (int) $line : null);

        return $lineInt !== null ? $pathStr.':'.$lineInt : $pathStr;
    }
}
