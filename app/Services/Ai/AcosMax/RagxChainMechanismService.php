<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Fase 4 ACOS Max RAGX chain mechanisms.
 *
 * Every stage is default-OFF/shadow and reports blockers instead of promoting
 * live retrieval or fabricating A/B outcomes.
 */
final class RagxChainMechanismService
{
    public const SCHEMA = 'atlas.acos_max.ragx_chain_mechanisms.v1';

    public const AB_SCHEMA = 'atlas.acos_max.ragx_ab_registration.v1';

    public const RAPTOR_SCHEMA = 'atlas.acos_max.ragx10_raptor_lite.v1';

    public const LOUVAIN_SCHEMA = 'atlas.acos_max.maxd05_louvain_chunks.v1';

    public function __construct(
        private readonly ?AsefChunkIndexService $asefChunks = null,
        private readonly ?string $abLedgerPath = null,
    ) {}

    /**
     * @param  array<string,bool>  $deps
     * @return array<string,mixed>
     */
    public function stageReport(array $deps = []): array
    {
        $stages = [
            'RAGX-01' => $this->stage(
                'atlas.aobg.ragx_late_chunk_index',
                'late_chunk_asef_chunks_shadow',
                blockedBy: $this->flag('atlas.aobg.ragx_late_chunk_index') && ! $this->flag('atlas.aobg.ragx_late_chunk_maxa04_promoted') ? ['MAXA-04'] : [],
                pendingWindow: ['jina_v3_dual_read_benchmark_window'],
            ),
            'RAGX-02' => $this->stage('atlas.aobg.facet_retrieval', 'query_time_facets_existing_packfor_seam', pendingWindow: ['facet_retrieval_ab_soak']),
            'RAGX-06' => $this->stage('atlas.aobg.fusion_enabled', 'reciprocal_rank_fusion_existing_aobg_seam', pendingWindow: ['fusion_shadow_ab_window']),
            'RAGX-03' => $this->stage('atlas.aobg.cross_encoder_rerank', 'cross_encoder_rerank_existing_semantic_rag_seam', pendingWindow: ['rerank_precision3_latency_window']),
            'RAGX-11' => $this->stage('atlas.aobg.ragx_adaptive_k', 'score_distribution_adaptive_k_shadow', pendingWindow: ['late_chunk_score_distribution_window']),
            'RAGX-05' => $this->stage('atlas.aobg.ragx_sparse_fallback', 'deterministic_sparse_shadow_fallback', pendingWindow: ['dense_vs_sparse_shadow_window']),
            'RAGX-07' => $this->stage('atlas.aobg.ragx_ab_registrar', 'records_only_ab_registrar', pendingWindow: ['golden_v2_or_live_window_not_run']),
            'MAXD-05' => $this->stage(
                'atlas.aobg.ragx_louvain_chunks',
                'louvain_over_asef_chunks_shadow',
                blockedBy: $this->flag('atlas.aobg.ragx_louvain_chunks') && ! $this->flag('atlas.aobg.ragx_maxa06_fase2_backfilled') ? ['MAXA-06(fase 2)'] : [],
                pendingWindow: ['maxa06_fase2_code_symbol_embedding_backfill'],
            ),
            'RAGX-10' => $this->stage(
                'atlas.aobg.ragx_raptor_lite',
                'raptor_lite_from_louvain_and_verified_l2_summaries',
                blockedBy: $this->raptorBlockers($deps),
                pendingWindow: ['raptor_lite_verified_summary_window'],
            ),
        ];

        $anyEnabled = collect($stages)->contains(static fn (array $stage): bool => (AiValueNormalizer::boolOrNull($stage['enabled'] ?? null) ?? false));

        return [
            'schema_version' => self::SCHEMA,
            'mode' => $anyEnabled ? 'shadow' : 'default_off',
            'ab_green_claimed' => false,
            'stages' => $stages,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function lateChunkIndexShadow(string $query, int $limit = 5, bool $allowExternalProvider = false): array
    {
        if (! $this->flag('atlas.aobg.ragx_late_chunk_index')) {
            return $this->disabled('RAGX-01', 'atlas.aobg.ragx_late_chunk_index') + ['documents' => []];
        }

        if (! $this->flag('atlas.aobg.ragx_late_chunk_maxa04_promoted')) {
            return [
                'schema_version' => self::SCHEMA,
                'slice' => 'RAGX-01',
                'status' => 'blocked',
                'mode' => 'shadow',
                'blocked_by' => ['MAXA-04'],
                'pending_window' => ['jina_v3_dual_read_benchmark_window'],
                'documents' => [],
                'ab_green_claimed' => false,
            ];
        }

        try {
            $index = $this->asefChunks ?? app(AsefChunkIndexService::class);
            $result = $index->search($query, $limit, $allowExternalProvider);
        } catch (Throwable $e) {
            return [
                'schema_version' => self::SCHEMA,
                'slice' => 'RAGX-01',
                'status' => 'degraded',
                'reason' => 'late_chunk_index_error',
                'error_class' => $e::class,
                'documents' => [],
                'ab_green_claimed' => false,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'slice' => 'RAGX-01',
            'status' => AiValueNormalizer::trimmedStringOrNull($result['status'] ?? null) ?? 'unknown',
            'mode' => 'shadow',
            'result' => $result,
            'documents' => array_values(AiValueNormalizer::arrayOrEmpty($result['documents'] ?? null)),
            'ab_green_claimed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $experiment
     * @return array<string,mixed>
     */
    public function registerAb(array $experiment): array
    {
        $record = [
            'schema_version' => self::AB_SCHEMA,
            'status' => 'registered',
            'recorded_at' => Carbon::now()->toISOString(),
            'experiment_id' => AiValueNormalizer::trimmedStringOrNull($experiment['experiment_id'] ?? null) ?? hash('sha256', json_encode($experiment, JSON_THROW_ON_ERROR)),
            'slice' => AiValueNormalizer::trimmedStringOrNull($experiment['slice']  ?? null) ?? 'RAGX',
            'baseline' => AiValueNormalizer::trimmedStringOrNull($experiment['baseline']  ?? null) ?? 'unknown',
            'candidate' => AiValueNormalizer::trimmedStringOrNull($experiment['candidate']  ?? null) ?? 'unknown',
            'result' => null,
            'ab_green_claimed' => false,
            'pending_window' => ['golden_v2_or_live_window_not_run'],
        ];

        if ($this->abLedgerPath !== null && $this->abLedgerPath !== '') {
            File::ensureDirectoryExists(dirname($this->abLedgerPath));
            File::append($this->abLedgerPath, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'
');
        }

        return $record;
    }

    /**
     * @param  list<array{id?:string,chunk_id?:string}>  $chunks
     * @param  list<array{source?:string,target?:string,weight?:float|int}>  $edges
     * @return array<string,mixed>
     */
    public function louvainOverChunks(array $chunks, array $edges): array
    {
        if (! $this->flag('atlas.aobg.ragx_louvain_chunks')) {
            return $this->disabled('MAXD-05', 'atlas.aobg.ragx_louvain_chunks') + ['communities' => []];
        }

        if (! $this->flag('atlas.aobg.ragx_maxa06_fase2_backfilled')) {
            return [
                'schema_version' => self::LOUVAIN_SCHEMA,
                'slice' => 'MAXD-05',
                'status' => 'blocked',
                'blocked_by' => ['MAXA-06(fase 2)'],
                'pending_window' => ['maxa06_fase2_code_symbol_embedding_backfill'],
                'communities' => [],
            ];
        }

        $nodes = $this->chunkIds($chunks);
        if ($nodes === []) {
            return [
                'schema_version' => self::LOUVAIN_SCHEMA,
                'slice' => 'MAXD-05',
                'status' => 'empty',
                'algorithm' => 'louvain_deterministic_local',
                'communities' => [],
            ];
        }

        $adjacency = $this->adjacency($nodes, $edges);
        $communities = $this->greedyLouvainCommunities($nodes, $adjacency);

        return [
            'schema_version' => self::LOUVAIN_SCHEMA,
            'slice' => 'MAXD-05',
            'status' => 'ok',
            'algorithm' => 'louvain_deterministic_local',
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'communities' => $communities,
            'ab_green_claimed' => false,
        ];
    }

    /**
     * @param  list<list<string>>  $communities
     * @param  list<array<string,mixed>>  $verifiedSummaries
     * @param  array<string,bool>  $deps
     * @return array<string,mixed>
     */
    public function raptorLite(array $communities, array $verifiedSummaries, array $deps = []): array
    {
        if (! $this->flag('atlas.aobg.ragx_raptor_lite')) {
            return $this->disabled('RAGX-10', 'atlas.aobg.ragx_raptor_lite') + ['nodes' => []];
        }

        $blockers = $this->raptorBlockers($deps);
        if ($blockers !== []) {
            return [
                'schema_version' => self::RAPTOR_SCHEMA,
                'slice' => 'RAGX-10',
                'status' => 'blocked',
                'blocked_by' => $blockers,
                'pending_window' => ['raptor_lite_verified_summary_window'],
                'nodes' => [],
                'generated_summary' => false,
            ];
        }

        $verified = array_values(array_filter($verifiedSummaries, static function (array $summary): bool {
            return (AiValueNormalizer::trimmedStringOrNull($summary['summary'] ?? null) ?? '') !== ''
                && in_array(AiValueNormalizer::trimmedStringOrNull($summary['status'] ?? null) ?? 'verified', ['verified', 'ok'], true);
        }));

        if ($verified === []) {
            return [
                'schema_version' => self::RAPTOR_SCHEMA,
                'slice' => 'RAGX-10',
                'status' => 'insufficient_signal',
                'reason' => 'no_verified_maxf09_l2_summaries',
                'communities_seen' => count($communities),
                'nodes' => [],
                'generated_summary' => false,
                'ab_green_claimed' => false,
            ];
        }

        $nodes = [];
        foreach ($verified as $index => $summary) {
            $nodes[] = [
                'id' => AiValueNormalizer::trimmedStringOrNull($summary['id'] ?? null) ?? ('raptor_lite_'.($index + 1)),
                'community' => array_values(AiValueNormalizer::arrayOrEmpty($summary['community'] ?? ($communities[$index] ?? null))),
                'summary_ref' => AiValueNormalizer::trimmedStringOrNull($summary['l2_summary_id'] ?? $summary['id'] ?? null) ?? '',
                'source' => 'maxf09_verified_l2_summary',
            ];
        }

        return [
            'schema_version' => self::RAPTOR_SCHEMA,
            'slice' => 'RAGX-10',
            'status' => 'ok',
            'nodes' => $nodes,
            'generated_summary' => false,
            'ab_green_claimed' => false,
        ];
    }

    /**
     * @param  list<array{id?:string,text?:string}>  $documents
     * @return array<string,mixed>
     */
    public function sparseShadow(string $query, array $documents, int $limit = 5): array
    {
        if (! $this->flag('atlas.aobg.ragx_sparse_fallback')) {
            return $this->disabled('RAGX-05', 'atlas.aobg.ragx_sparse_fallback') + ['matches' => []];
        }

        $tokens = $this->tokens($query);
        $matches = [];
        foreach ($documents as $index => $document) {
            $text = AiValueNormalizer::lowerTrimmedString($document['text'] ?? '');
            $hits = 0;
            foreach ($tokens as $token) {
                if (str_contains($text, $token)) {
                    $hits++;
                }
            }
            $matches[] = [
                'id' => AiValueNormalizer::trimmedStringOrNull($document['id'] ?? null) ?? ('doc_'.$index),
                'score' => $tokens === [] ? 0.0 : round($hits / count($tokens), 4),
                'score_origin' => 'lexical_sparse_shadow',
            ];
        }
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));

        return [
            'schema_version' => self::SCHEMA,
            'slice' => 'RAGX-05',
            'status' => 'shadow',
            'matches' => array_slice($matches, 0, max(1, $limit)),
            'ab_green_claimed' => false,
        ];
    }

    /**
     * @param  list<float|int>  $scores
     * @return array<string,mixed>
     */
    public function adaptiveK(array $scores, int $requestedK): array
    {
        if (! $this->flag('atlas.aobg.ragx_adaptive_k')) {
            return $this->disabled('RAGX-11', 'atlas.aobg.ragx_adaptive_k') + ['k' => max(1, $requestedK)];
        }

        $scores = array_values(array_map('floatval', $scores));
        rsort($scores);
        $k = max(1, $requestedK);
        for ($i = 1; $i < count($scores); $i++) {
            if (($scores[$i - 1] - $scores[$i]) >= 0.25) {
                $k = max(1, min($requestedK, $i));
                break;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'slice' => 'RAGX-11',
            'status' => 'shadow',
            'k' => $k,
            'score_count' => count($scores),
            'ab_green_claimed' => false,
        ];
    }

    /**
     * @param  list<string>  $blockedBy
     * @param  list<string>  $pendingWindow
     * @return array<string,mixed>
     */
    private function stage(string $flag, string $mechanism, array $blockedBy = [], array $pendingWindow = []): array
    {
        $enabled = $this->flag($flag);

        return [
            'mechanism' => $mechanism,
            'flag' => $flag,
            'enabled' => $enabled,
            'status' => ! $enabled ? 'disabled' : ($blockedBy === [] ? 'shadow' : 'blocked'),
            'blocked_by' => $blockedBy,
            'pending_window' => $pendingWindow,
            'ab_green_claimed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function disabled(string $slice, string $flag): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'slice' => $slice,
            'status' => 'disabled',
            'flag' => $flag,
            'ab_green_claimed' => false,
        ];
    }

    private function flag(string $key): bool
    {
        return (AiValueNormalizer::boolOrNull(config($key, false)) ?? false);
    }

    /** @param  array<string,bool>  $deps */
    private function raptorBlockers(array $deps): array
    {
        if (! $this->flag('atlas.aobg.ragx_raptor_lite')) {
            return [];
        }

        $blockers = [];
        if (($deps['maxd05_louvain'] ?? false) !== true) {
            $blockers[] = 'MAXD-05';
        }
        if (($deps['maxf09_l2_summaries'] ?? true) !== true) {
            $blockers[] = 'MAXF-09';
        }

        return $blockers;
    }

    /**
     * @param  list<array{id?:string,chunk_id?:string}>  $chunks
     * @return list<string>
     */
    private function chunkIds(array $chunks): array
    {
        $ids = [];
        foreach ($chunks as $chunk) {
            $id = AiValueNormalizer::trimmedStringOrNull($chunk['id'] ?? $chunk['chunk_id'] ?? null) ?? '';
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<string>  $nodes
     * @param  list<array{source?:string,target?:string,weight?:float|int}>  $edges
     * @return array<string,array<string,float>>
     */
    private function adjacency(array $nodes, array $edges): array
    {
        $known = array_fill_keys($nodes, true);
        $adjacency = [];
        foreach ($nodes as $node) {
            $adjacency[$node] = [];
        }
        foreach ($edges as $edge) {
            $source = AiValueNormalizer::trimmedStringOrNull($edge['source'] ?? null) ?? '';
            $target = AiValueNormalizer::trimmedStringOrNull($edge['target'] ?? null) ?? '';
            $weight = max(0.0, AiValueNormalizer::finiteFloatOrNull($edge['weight'] ?? null) ?? 1.0);
            if ($source === '' || $target === '' || $source === $target || $weight <= 0.0 || ! isset($known[$source], $known[$target])) {
                continue;
            }
            $adjacency[$source][$target] = ($adjacency[$source][$target] ?? 0.0) + $weight;
            $adjacency[$target][$source] = ($adjacency[$target][$source] ?? 0.0) + $weight;
        }

        return $adjacency;
    }

    /**
     * @param  list<string>  $nodes
     * @param  array<string,array<string,float>>  $adjacency
     * @return list<list<string>>
     */
    private function greedyLouvainCommunities(array $nodes, array $adjacency): array
    {
        $communities = [];
        foreach ($nodes as $node) {
            $communities[$node] = $node;
        }

        $currentQ = $this->modularity($communities, $adjacency);
        for ($pass = 0; $pass < 10; $pass++) {
            $moved = false;
            foreach ($nodes as $node) {
                $bestCommunity = $communities[$node];
                $bestQ = $currentQ;
                $candidates = array_unique(array_merge([$bestCommunity], array_map(static fn (string $neighbor): string => $communities[$neighbor], array_keys($adjacency[$node] ?? []))));
                sort($candidates);
                foreach ($candidates as $candidate) {
                    $trial = $communities;
                    $trial[$node] = $candidate;
                    $q = $this->modularity($trial, $adjacency);
                    if ($q > $bestQ + 0.0000001) {
                        $bestQ = $q;
                        $bestCommunity = $candidate;
                    }
                }
                if ($bestCommunity !== $communities[$node]) {
                    $communities[$node] = $bestCommunity;
                    $currentQ = $bestQ;
                    $moved = true;
                }
            }
            if (! $moved) {
                break;
            }
        }

        $grouped = [];
        foreach ($communities as $node => $community) {
            $grouped[$community][] = $node;
        }
        foreach ($grouped as &$members) {
            sort($members);
        }
        unset($members);
        $out = array_values($grouped);
        usort($out, static fn (array $a, array $b): int => strcmp(implode('|', $a), implode('|', $b)));

        return $out;
    }

    /** @param  array<string,string>  $communities @param  array<string,array<string,float>>  $adjacency */
    private function modularity(array $communities, array $adjacency): float
    {
        $degrees = [];
        $m2 = 0.0;
        foreach ($adjacency as $node => $neighbors) {
            $degrees[$node] = array_sum($neighbors);
            $m2 += $degrees[$node];
        }
        if ($m2 <= 0.0) {
            return 0.0;
        }

        $q = 0.0;
        foreach ($adjacency as $i => $neighbors) {
            foreach ($adjacency as $j => $_) {
                if (($communities[$i] ?? null) !== ($communities[$j] ?? null)) {
                    continue;
                }
                $aij = AiValueNormalizer::finiteFloatOrNull($neighbors[$j] ?? null) ?? 0.0;
                $q += $aij - (($degrees[$i] ?? 0.0) * ($degrees[$j] ?? 0.0) / $m2);
            }
        }

        return $q / $m2;
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        preg_match_all('/[\pL\pN]{3,}/u', AiValueNormalizer::lowerTrimmedString($query), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
