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

    public const FLAG_LATE_CHUNK_INDEX = 'atlas.aobg.ragx_late_chunk_index';

    public const FLAG_LATE_CHUNK_MAXA04_PROMOTED = 'atlas.aobg.ragx_late_chunk_maxa04_promoted';

    public const FLAG_ADAPTIVE_K = 'atlas.aobg.ragx_adaptive_k';

    public const FLAG_SPARSE_FALLBACK = 'atlas.aobg.ragx_sparse_fallback';

    public const FLAG_AB_REGISTRAR = 'atlas.aobg.ragx_ab_registrar';

    public const FLAG_LOUVAIN_CHUNKS = 'atlas.aobg.ragx_louvain_chunks';

    public const FLAG_MAXA06_FASE2_BACKFILLED = 'atlas.aobg.ragx_maxa06_fase2_backfilled';

    public const FLAG_RAPTOR_LITE = 'atlas.aobg.ragx_raptor_lite';

    public const FLAG_FACET_RETRIEVAL = 'atlas.aobg.facet_retrieval';

    public const FLAG_FUSION_ENABLED = 'atlas.aobg.fusion_enabled';

    public const FLAG_CROSS_ENCODER_RERANK = 'atlas.aobg.cross_encoder_rerank';

    public const STAGE_RAGX_01 = 'RAGX-01';

    public const STAGE_RAGX_02 = 'RAGX-02';

    public const STAGE_RAGX_03 = 'RAGX-03';

    public const STAGE_RAGX_05 = 'RAGX-05';

    public const STAGE_RAGX_06 = 'RAGX-06';

    public const STAGE_RAGX_07 = 'RAGX-07';

    public const STAGE_RAGX_10 = 'RAGX-10';

    public const STAGE_RAGX_11 = 'RAGX-11';

    public const STAGE_MAXD_05 = 'MAXD-05';

    public const MECHANISM_LATE_CHUNK = 'late_chunk_asef_chunks_shadow';

    public const MECHANISM_FACET_RETRIEVAL = 'query_time_facets_existing_packfor_seam';

    public const MECHANISM_FUSION = 'reciprocal_rank_fusion_existing_aobg_seam';

    public const MECHANISM_CROSS_ENCODER = 'cross_encoder_rerank_existing_semantic_rag_seam';

    public const MECHANISM_ADAPTIVE_K = 'score_distribution_adaptive_k_shadow';

    public const MECHANISM_SPARSE_FALLBACK = 'deterministic_sparse_shadow_fallback';

    public const MECHANISM_AB_REGISTRAR = 'records_only_ab_registrar';

    public const MECHANISM_LOUVAIN = 'louvain_over_asef_chunks_shadow';

    public const MECHANISM_RAPTOR_LITE = 'raptor_lite_from_louvain_and_verified_l2_summaries';

    public const PENDING_JINA_V3_DUAL_READ = 'jina_v3_dual_read_benchmark_window';

    public const PENDING_FACET_RETRIEVAL_AB = 'facet_retrieval_ab_soak';

    public const PENDING_FUSION_SHADOW_AB = 'fusion_shadow_ab_window';

    public const PENDING_RERANK_PRECISION3 = 'rerank_precision3_latency_window';

    public const PENDING_LATE_CHUNK_SCORE_DIST = 'late_chunk_score_distribution_window';

    public const PENDING_DENSE_VS_SPARSE = 'dense_vs_sparse_shadow_window';

    public const PENDING_GOLDEN_V2_OR_LIVE = 'golden_v2_or_live_window_not_run';

    public const PENDING_MAXA06_FASE2_BACKFILL = 'maxa06_fase2_code_symbol_embedding_backfill';

    public const PENDING_RAPTOR_LITE_SUMMARY = 'raptor_lite_verified_summary_window';

    public const BLOCKER_MAXA04 = 'MAXA-04';

    public const BLOCKER_MAXA06_FASE2 = 'MAXA-06(fase 2)';

    public const BLOCKER_MAXF09 = 'MAXF-09';

    public const ADAPTIVE_K_SCORE_GAP_FLOOR = 0.25;

    public const MODE_SHADOW = 'shadow';

    public const MODE_DEFAULT_OFF = 'default_off';

    public const STATUS_SHADOW = 'shadow';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_OK = 'ok';

    public const STATUS_UNKNOWN = 'unknown';

    public const REASON_LATE_CHUNK_INDEX_ERROR = 'late_chunk_index_error';

    public const REASON_NO_VERIFIED_MAXF09_L2_SUMMARIES = 'no_verified_maxf09_l2_summaries';

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
            self::STAGE_RAGX_01 => $this->stage(
                self::FLAG_LATE_CHUNK_INDEX,
                self::MECHANISM_LATE_CHUNK,
                blockedBy: $this->flag(self::FLAG_LATE_CHUNK_INDEX) && ! $this->flag(self::FLAG_LATE_CHUNK_MAXA04_PROMOTED) ? [self::BLOCKER_MAXA04] : [],
                pendingWindow: [self::PENDING_JINA_V3_DUAL_READ],
            ),
            self::STAGE_RAGX_02 => $this->stage(self::FLAG_FACET_RETRIEVAL, self::MECHANISM_FACET_RETRIEVAL, pendingWindow: [self::PENDING_FACET_RETRIEVAL_AB]),
            self::STAGE_RAGX_06 => $this->stage(self::FLAG_FUSION_ENABLED, self::MECHANISM_FUSION, pendingWindow: [self::PENDING_FUSION_SHADOW_AB]),
            self::STAGE_RAGX_03 => $this->stage(self::FLAG_CROSS_ENCODER_RERANK, self::MECHANISM_CROSS_ENCODER, pendingWindow: [self::PENDING_RERANK_PRECISION3]),
            self::STAGE_RAGX_11 => $this->stage(self::FLAG_ADAPTIVE_K, self::MECHANISM_ADAPTIVE_K, pendingWindow: [self::PENDING_LATE_CHUNK_SCORE_DIST]),
            self::STAGE_RAGX_05 => $this->stage(self::FLAG_SPARSE_FALLBACK, self::MECHANISM_SPARSE_FALLBACK, pendingWindow: [self::PENDING_DENSE_VS_SPARSE]),
            self::STAGE_RAGX_07 => $this->stage(self::FLAG_AB_REGISTRAR, self::MECHANISM_AB_REGISTRAR, pendingWindow: [self::PENDING_GOLDEN_V2_OR_LIVE]),
            self::STAGE_MAXD_05 => $this->stage(
                self::FLAG_LOUVAIN_CHUNKS,
                self::MECHANISM_LOUVAIN,
                blockedBy: $this->flag(self::FLAG_LOUVAIN_CHUNKS) && ! $this->flag(self::FLAG_MAXA06_FASE2_BACKFILLED) ? [self::BLOCKER_MAXA06_FASE2] : [],
                pendingWindow: [self::PENDING_MAXA06_FASE2_BACKFILL],
            ),
            self::STAGE_RAGX_10 => $this->stage(
                self::FLAG_RAPTOR_LITE,
                self::MECHANISM_RAPTOR_LITE,
                blockedBy: $this->raptorBlockers($deps),
                pendingWindow: [self::PENDING_RAPTOR_LITE_SUMMARY],
            ),
        ];

        $anyEnabled = collect($stages)->contains(static fn (array $stage): bool => (AiValueNormalizer::boolOrNull($stage['enabled'] ?? null) ?? false));

        return [
            'schema_version' => self::SCHEMA,
            'mode' => $anyEnabled ? self::MODE_SHADOW : self::MODE_DEFAULT_OFF,
            'ab_green_claimed' => false,
            'stages' => $stages,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function lateChunkIndexShadow(string $query, int $limit = 5, bool $allowExternalProvider = false): array
    {
        if (! $this->flag(self::FLAG_LATE_CHUNK_INDEX)) {
            return $this->disabled(self::STAGE_RAGX_01, self::FLAG_LATE_CHUNK_INDEX) + ['documents' => []];
        }

        if (! $this->flag(self::FLAG_LATE_CHUNK_MAXA04_PROMOTED)) {
            return [
                'schema_version' => self::SCHEMA,
                'slice' => self::STAGE_RAGX_01,
                'status' => self::STATUS_BLOCKED,
                'mode' => self::MODE_SHADOW,
                'blocked_by' => [self::BLOCKER_MAXA04],
                'pending_window' => [self::PENDING_JINA_V3_DUAL_READ],
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
                'slice' => self::STAGE_RAGX_01,
                'status' => self::STATUS_DEGRADED,
                'reason' => self::REASON_LATE_CHUNK_INDEX_ERROR,
                'error_class' => $e::class,
                'documents' => [],
                'ab_green_claimed' => false,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'slice' => self::STAGE_RAGX_01,
            'status' => AiValueNormalizer::trimmedStringOrNull($result['status'] ?? null) ?? self::STATUS_UNKNOWN,
            'mode' => self::MODE_SHADOW,
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
            'status' => self::STATUS_REGISTERED,
            'recorded_at' => Carbon::now()->toISOString(),
            'experiment_id' => AiValueNormalizer::trimmedStringOrNull($experiment['experiment_id'] ?? null) ?? hash('sha256', json_encode($experiment, JSON_THROW_ON_ERROR)),
            'slice' => AiValueNormalizer::trimmedStringOrNull($experiment['slice']  ?? null) ?? 'RAGX',
            'baseline' => AiValueNormalizer::trimmedStringOrNull($experiment['baseline']  ?? null) ?? self::STATUS_UNKNOWN,
            'candidate' => AiValueNormalizer::trimmedStringOrNull($experiment['candidate']  ?? null) ?? self::STATUS_UNKNOWN,
            'result' => null,
            'ab_green_claimed' => false,
            'pending_window' => [self::PENDING_GOLDEN_V2_OR_LIVE],
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
        if (! $this->flag(self::FLAG_LOUVAIN_CHUNKS)) {
            return $this->disabled(self::STAGE_MAXD_05, self::FLAG_LOUVAIN_CHUNKS) + ['communities' => []];
        }

        if (! $this->flag(self::FLAG_MAXA06_FASE2_BACKFILLED)) {
            return [
                'schema_version' => self::LOUVAIN_SCHEMA,
                'slice' => self::STAGE_MAXD_05,
                'status' => self::STATUS_BLOCKED,
                'blocked_by' => [self::BLOCKER_MAXA06_FASE2],
                'pending_window' => [self::PENDING_MAXA06_FASE2_BACKFILL],
                'communities' => [],
            ];
        }

        $nodes = $this->chunkIds($chunks);
        if ($nodes === []) {
            return [
                'schema_version' => self::LOUVAIN_SCHEMA,
                'slice' => self::STAGE_MAXD_05,
                'status' => self::STATUS_EMPTY,
                'algorithm' => 'louvain_deterministic_local',
                'communities' => [],
            ];
        }

        $adjacency = $this->adjacency($nodes, $edges);
        $communities = $this->greedyLouvainCommunities($nodes, $adjacency);

        return [
            'schema_version' => self::LOUVAIN_SCHEMA,
            'slice' => self::STAGE_MAXD_05,
            'status' => self::STATUS_OK,
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
        if (! $this->flag(self::FLAG_RAPTOR_LITE)) {
            return $this->disabled(self::STAGE_RAGX_10, self::FLAG_RAPTOR_LITE) + ['nodes' => []];
        }

        $blockers = $this->raptorBlockers($deps);
        if ($blockers !== []) {
            return [
                'schema_version' => self::RAPTOR_SCHEMA,
                'slice' => self::STAGE_RAGX_10,
                'status' => self::STATUS_BLOCKED,
                'blocked_by' => $blockers,
                'pending_window' => [self::PENDING_RAPTOR_LITE_SUMMARY],
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
                'slice' => self::STAGE_RAGX_10,
                'status' => self::STATUS_INSUFFICIENT_SIGNAL,
                'reason' => self::REASON_NO_VERIFIED_MAXF09_L2_SUMMARIES,
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
            'slice' => self::STAGE_RAGX_10,
            'status' => self::STATUS_OK,
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
        if (! $this->flag(self::FLAG_SPARSE_FALLBACK)) {
            return $this->disabled(self::STAGE_RAGX_05, self::FLAG_SPARSE_FALLBACK) + ['matches' => []];
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
            'slice' => self::STAGE_RAGX_05,
            'status' => self::STATUS_SHADOW,
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
        if (! $this->flag(self::FLAG_ADAPTIVE_K)) {
            return $this->disabled(self::STAGE_RAGX_11, self::FLAG_ADAPTIVE_K) + ['k' => max(1, $requestedK)];
        }

        $scores = array_values(array_map('floatval', $scores));
        rsort($scores);
        $k = max(1, $requestedK);
        for ($i = 1; $i < count($scores); $i++) {
            if (($scores[$i - 1] - $scores[$i]) >= self::ADAPTIVE_K_SCORE_GAP_FLOOR) {
                $k = max(1, min($requestedK, $i));
                break;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'slice' => self::STAGE_RAGX_11,
            'status' => self::STATUS_SHADOW,
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
            'status' => ! $enabled ? self::STATUS_DISABLED : ($blockedBy === [] ? self::STATUS_SHADOW : self::STATUS_BLOCKED),
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
            'status' => self::STATUS_DISABLED,
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
        if (! $this->flag(self::FLAG_RAPTOR_LITE)) {
            return [];
        }

        $blockers = [];
        if (($deps['maxd05_louvain'] ?? false) !== true) {
            $blockers[] = self::STAGE_MAXD_05;
        }
        if (($deps['maxf09_l2_summaries'] ?? true) !== true) {
            $blockers[] = self::BLOCKER_MAXF09;
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
