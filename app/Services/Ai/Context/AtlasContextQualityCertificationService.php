<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

/**
 * Atlas Context/Memory Quality Certification.
 *
 * HONESTY CONTRACT (R2 — anti-over-claim):
 *   - The numeric `quality_score` is derived EXCLUSIVELY from the one real
 *     measurement harness in this codebase: {@see LocalRagBenchmarkService}
 *     (real router governance precision + real pgvector memory-recall
 *     precision@k). There are NO hardcoded score literals, NO artificial
 *     score-ceiling cap, and NO max(floor, real) protective floors. A real failure
 *     (precision drop, provider-safe violation, stale-context use, blocked
 *     readiness, router governance miss) LOWERS the score and can flip the
 *     status to `blocked` — proven by tests.
 *   - When the real harness has NOT measured retrieval answer quality yet
 *     (no promoted/provider-safe memory recall corpus → no pgvector recall),
 *     this service emits NO numeric score (`quality_score = null`) and labels
 *     `status = 'synthetic_readiness_only'`. The synthetic corpus / AUCRI /
 *     stress-lab scaffolding below is DECLARED READINESS structure only; it is
 *     never converted into a fabricated number.
 *   - This certification invokes no provider, runs no rivals, runs no external
 *     benchmark, and writes nothing.
 */
final class AtlasContextQualityCertificationService
{
    public const SCHEMA_VERSION = 'atlas.context.quality_certification.v1';

    private const TARGET_SCORE = 9.8;

    public function __construct(
        private readonly AtlasAucriRuntimeEnforcementService $aucriRuntime,
        private readonly AtlasRetrievalEvaluationBenchmarkArenaService $retrievalArena,
        private readonly AtlasContextParetoFrontierRuntimeService $paretoFrontier,
        private readonly LocalRagBenchmarkService $localRagBenchmark,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $caseCount = max(1000, min(5000, (int) ($input['cases'] ?? 1200)));
        $targetScore = max(0.0, min(10.0, (float) ($input['target_score'] ?? self::TARGET_SCORE)));
        $corpus = $this->syntheticCorpus($caseCount);
        $stressLab = $this->stressLab($corpus);
        $adversarial = $this->adversarialEvaluation($corpus);
        $replay = $this->replayHarness($corpus);
        $aemor = $this->aemorSyntheticFeed($corpus);
        $embeddingGraph = $this->embeddingGraphReadiness();
        $aucri = $this->aucriRuntime->enforce([
            'flow_id' => 'atlas.context.quality_certification',
            'domain' => 'programming',
            'task_type' => 'context_quality_gate',
            'risk_level' => 'medium',
            'provider' => 'gpt',
            'objective' => 'certificar contexto memoria retrieval graph replay adversarial e token economy',
            'raw_context' => 'synthetic-context-quality-certification',
            'strict_retrieval_gate' => false,
            'segments' => $this->aucriSegments(),
            'required_sources' => ['memory_signals', 'code_intelligence', 'evidence_replay'],
            'context_refs' => ['memory_signals', 'code_intelligence', 'evidence_replay'],
            'memory_available_bytes' => 12 * 1073741824,
            'repeated_tokens' => 2400,
        ]);
        $golden = $this->goldenBenchmark();
        $pareto = $this->paretoFrontier->report(24);

        // The ONE real measurement harness. Everything numeric flows from here.
        $realBenchmark = $this->localRagBenchmark->report();
        $realMeasurement = $this->realMeasurement($realBenchmark);

        $metrics = $this->metrics($realMeasurement);
        $score = $this->qualityScore($realMeasurement);
        $components = $this->components($stressLab, $corpus, $replay, $golden, $adversarial, $embeddingGraph, $aemor, $realMeasurement, $score, $targetScore);
        $blockers = $this->blockers($components, $realMeasurement, $score, $targetScore, $aucri);

        $status = $this->status($realMeasurement, $blockers);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'target_score' => $targetScore,
            'quality_score' => $score,
            'score_basis' => $realMeasurement['available']
                ? 'real_local_rag_benchmark_measurement'
                : 'synthetic_readiness_only_no_real_measurement',
            'summary' => [
                'case_count' => $caseCount,
                'component_count' => count($components),
                'components_ready' => count(array_filter($components, static fn (array $component): bool => $component['status'] === 'ready')),
                'blockers_count' => count($blockers),
                'aucri_blocks_executed' => (int) data_get($aucri, 'block_ref_summary.executed', 0),
                'real_measurement_available' => $realMeasurement['available'],
                'external_claim_status' => 'not_claimed',
            ],
            'components' => $components,
            'real_measurement' => $realMeasurement,
            'synthetic_long_horizon_corpus' => $corpus,
            'context_stress_lab' => $stressLab,
            'replay_harness' => $replay,
            'golden_context_benchmark' => $golden,
            'adversarial_context_evaluation' => $adversarial,
            'embedding_graph_readiness' => $embeddingGraph,
            'aemor_synthetic_feed' => $aemor,
            'aucri_runtime_enforcement' => [
                'schema_version' => AtlasAucriRuntimeEnforcementService::SCHEMA_VERSION,
                'status' => (string) ($aucri['status'] ?? 'unknown'),
                'block_ref_summary' => (array) ($aucri['block_ref_summary'] ?? []),
                'block_refs' => array_map(static fn (array $ref): array => [
                    'block' => $ref['block'] ?? null,
                    'acronym' => $ref['acronym'] ?? null,
                    'schema_version' => $ref['schema_version'] ?? null,
                    'execution_status' => $ref['execution_status'] ?? null,
                    'hash' => $ref['hash'] ?? null,
                ], (array) ($aucri['block_refs'] ?? [])),
            ],
            'pareto_frontier' => [
                'schema_version' => AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION,
                'status' => (string) ($pareto['status'] ?? 'unknown'),
                'frontier_hash' => (string) ($pareto['frontier_hash'] ?? ''),
                'selected_candidates' => (int) data_get($pareto, 'summary.selected_candidates', 0),
            ],
            'metrics' => $metrics,
            'blockers' => $blockers,
            'claim_policy' => [
                'score_from_real_measurement_only' => true,
                'synthetic_readiness_only' => ! $realMeasurement['available'],
                'no_hardcoded_score_literals' => true,
                'no_score_cap' => true,
                'no_real_metric_floor' => true,
                'real_failure_can_lower_score' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'external_benchmark_run' => false,
                'external_superiority_claim' => false,
                'writes' => false,
                'raw_text_exposed' => false,
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * Extract the real, falsifiable signal from {@see LocalRagBenchmarkService}.
     *
     * A real numeric score is only emitted when retrieval answer quality has
     * actually been measured against a provider-safe memory recall corpus
     * (real pgvector precision@k). Otherwise the score is unmeasured (null).
     *
     * @param  array<string,mixed>  $benchmark
     * @return array<string,mixed>
     */
    private function realMeasurement(array $benchmark): array
    {
        $benchmarkStatus = (string) ($benchmark['status'] ?? 'attention');
        $readinessStatus = (string) ($benchmark['readiness_status'] ?? 'blocked');
        $routerPrecision = (float) ($benchmark['average_score'] ?? 0.0);

        $qualityCorpusStatus = (string) data_get($benchmark, 'quality_corpus.status', 'attention');
        $qualityCorpusMinScore = (float) data_get($benchmark, 'quality_corpus.metrics.min_score', 0.0);

        $recall = (array) ($benchmark['memory_recall_corpus'] ?? []);
        $recallStatus = (string) ($recall['status'] ?? 'attention');
        $recallCaseCount = (int) ($recall['case_count'] ?? 0);
        $precisionAt3 = (float) data_get($recall, 'metrics.precision_at_3', 0.0);
        $precisionAt5 = (float) data_get($recall, 'metrics.precision_at_5', 0.0);
        $missedCritical = (int) data_get($recall, 'metrics.missed_critical_context_count', 0);
        $contamination = (int) data_get($recall, 'metrics.context_contamination_count', 0);
        $providerSafeViolations = (int) data_get($recall, 'metrics.provider_safe_violation_count', 0);
        $staleUse = (int) data_get($recall, 'metrics.stale_context_use_count', 0);

        // Real retrieval answer quality is only measured when the provider-safe
        // memory recall corpus actually ran and passed its own gates.
        $available = $recallStatus === 'passed' && $recallCaseCount > 0;

        return [
            'schema_version' => 'atlas.context.real_measurement.v1',
            'source' => LocalRagBenchmarkService::SCHEMA_VERSION,
            'available' => $available,
            'unavailable_reason' => $available
                ? null
                : (string) ($recall['missing_reason'] ?? 'no_provider_safe_memory_recall_corpus_measured'),
            'benchmark_status' => $benchmarkStatus,
            'readiness_status' => $readinessStatus,
            'router_governance_precision' => round($routerPrecision, 4),
            'quality_corpus_status' => $qualityCorpusStatus,
            'quality_corpus_min_score' => round($qualityCorpusMinScore, 4),
            'memory_recall_status' => $recallStatus,
            'memory_recall_case_count' => $recallCaseCount,
            'precision_at_3' => round($precisionAt3, 4),
            'precision_at_5' => round($precisionAt5, 4),
            'missed_critical_context_count' => $missedCritical,
            'context_contamination_count' => $contamination,
            'provider_safe_violation_count' => $providerSafeViolations,
            'stale_context_use_count' => $staleUse,
        ];
    }

    /**
     * Derive a 0..10 quality score ONLY from real measurements.
     *
     * Returns null when no real retrieval answer-quality measurement exists —
     * the service then reports `synthetic_readiness_only` with no number, rather
     * than inventing one. No artificial score-ceiling cap is applied; a genuine
     * 10.0 is reachable only when every real signal is perfect.
     *
     * @param  array<string,mixed>  $real
     */
    private function qualityScore(array $real): ?float
    {
        if (! (bool) $real['available']) {
            return null;
        }

        // Real, falsifiable signals (all 0..1). Weights sum to 1.0.
        $routerPrecision = $this->clampUnit((float) $real['router_governance_precision']);
        $qualityCorpusMinScore = $this->clampUnit((float) $real['quality_corpus_min_score']);
        $precisionAt3 = $this->clampUnit((float) $real['precision_at_3']);
        $precisionAt5 = $this->clampUnit((float) $real['precision_at_5']);

        $base = ($routerPrecision * 0.25)
            + ($qualityCorpusMinScore * 0.15)
            + ($precisionAt3 * 0.35)
            + ($precisionAt5 * 0.25);

        // Real safety failures subtract directly from the measured base so a
        // genuine regression provably lowers the score (no floor protects it).
        $safetyPenalty = 0.0;
        $safetyPenalty += min(1, (int) $real['provider_safe_violation_count']) * 0.50;
        $safetyPenalty += min(1, (int) $real['context_contamination_count']) * 0.25;
        $safetyPenalty += min(1, (int) $real['stale_context_use_count']) * 0.15;
        $safetyPenalty += min(1, (int) $real['missed_critical_context_count']) * 0.10;

        $score = 10.0 * max(0.0, $base - $safetyPenalty);

        return round(min(10.0, $score), 2);
    }

    /**
     * Quality metrics — REAL signals only, no hardcoded literals.
     *
     * @param  array<string,mixed>  $real
     * @return array<string,float|null>
     */
    private function metrics(array $real): array
    {
        if (! (bool) $real['available']) {
            return [
                'real_measurement_available' => 0.0,
            ];
        }

        return [
            'real_measurement_available' => 1.0,
            'router_governance_precision' => (float) $real['router_governance_precision'],
            'quality_corpus_min_score' => (float) $real['quality_corpus_min_score'],
            'retrieval_precision_at_3' => (float) $real['precision_at_3'],
            'retrieval_precision_at_5' => (float) $real['precision_at_5'],
            'provider_safe_violation_count' => (float) $real['provider_safe_violation_count'],
            'context_contamination_count' => (float) $real['context_contamination_count'],
            'stale_context_use_count' => (float) $real['stale_context_use_count'],
            'missed_critical_context_count' => (float) $real['missed_critical_context_count'],
        ];
    }

    private function status(array $real, array $blockers): string
    {
        if (! (bool) $real['available']) {
            return 'synthetic_readiness_only';
        }

        return $blockers === [] ? 'ready' : 'blocked';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function aucriSegments(): array
    {
        return [
            ['kind' => 'decision', 'ref' => 'synthetic://decision/must_keep', 'tokens' => 900, 'must_keep' => true, 'priority' => 1.0],
            ['kind' => 'blocker', 'ref' => 'synthetic://blocker/stale_context', 'tokens' => 700, 'must_keep' => true, 'priority' => 1.0],
            ['kind' => 'test', 'ref' => 'synthetic://test/replay_gate', 'tokens' => 1100, 'must_keep' => true, 'priority' => 0.95],
            ['kind' => 'doc', 'ref' => 'synthetic://doc/noise_pool', 'tokens' => 4200, 'must_keep' => false, 'priority' => 0.25],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function syntheticCorpus(int $caseCount): array
    {
        $dimensions = [
            'horizon' => ['one_day', 'one_week', 'one_month', 'multi_month_obra'],
            'task_shape' => ['simple_bug', 'deep_bug', 'long_refactor', 'forge_milestone', 'architecture_review'],
            'hazard' => ['stale_docs', 'superseded_decision', 'irrelevant_files', 'contradictory_context', 'dead_code_path'],
            'artifact' => ['commits', 'adrs', 'recurring_bugs', 'broken_tests', 'milestones', 'handoffs', 'architecture_changes', 'stale_docs'],
            'adversarial' => ['wrong_similar_name', 'false_memory', 'old_comment', 'dead_file', 'irrelevant_test', 'incomplete_prompt', 'ambiguous_instruction', 'dangerous_instruction'],
            'flow' => ['atlas_dev', 'atlas_forge', 'research', 'finance_review', 'strategy'],
        ];
        $matrixHash = MissionCanonicalHash::sha256([
            'case_count' => $caseCount,
            'dimensions' => $dimensions,
            'generator' => 'deterministic_cartesian_stride_v1',
        ]);

        return [
            'schema_version' => 'atlas.context.synthetic_long_horizon_corpus.v1',
            'status' => 'declared_readiness_only',
            'is_real_measurement' => false,
            'case_count' => $caseCount,
            'generator' => 'deterministic_cartesian_stride_v1',
            'dimensions' => $dimensions,
            'coverage' => [
                'long_horizon_artifacts' => count($dimensions['artifact']),
                'adversarial_categories' => count($dimensions['adversarial']),
                'flows' => count($dimensions['flow']),
                'case_matrix_hash' => $matrixHash,
            ],
            'samples' => $this->corpusSamples($dimensions),
        ];
    }

    /**
     * @param  array<string,array<int,string>>  $dimensions
     * @return array<int,array<string,mixed>>
     */
    private function corpusSamples(array $dimensions): array
    {
        $samples = [];
        for ($i = 0; $i < 8; $i++) {
            $samples[] = [
                'case_id' => 'ctx_stress_'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'horizon' => $dimensions['horizon'][$i % count($dimensions['horizon'])],
                'task_shape' => $dimensions['task_shape'][$i % count($dimensions['task_shape'])],
                'hazard' => $dimensions['hazard'][$i % count($dimensions['hazard'])],
                'artifact' => $dimensions['artifact'][$i % count($dimensions['artifact'])],
                'adversarial' => $dimensions['adversarial'][$i % count($dimensions['adversarial'])],
                'flow' => $dimensions['flow'][$i % count($dimensions['flow'])],
                'fixture_hash' => MissionCanonicalHash::sha256(['ctx_stress_sample', $i]),
            ];
        }

        return $samples;
    }

    /**
     * @param  array<string,mixed>  $corpus
     * @return array<string,mixed>
     */
    private function stressLab(array $corpus): array
    {
        return [
            'schema_version' => 'atlas.context.stress_lab.v1',
            'status' => 'declared_readiness_only',
            'is_real_measurement' => false,
            'mode' => 'synthetic_shadow_no_provider',
            'case_count' => (int) $corpus['case_count'],
            'stressors' => [
                'context_overload',
                'stale_context',
                'contradictory_memory',
                'long_horizon_resume',
                'noise_injection',
                'must_keep_loss_attempt',
                'dev_forge_boundary_confusion',
                'token_budget_pressure',
            ],
            'thresholds' => [
                'minimum_cases' => 1000,
                'must_keep_coverage' => 1.0,
                'maximum_irrelevant_context_ratio' => 0.05,
                'minimum_adversarial_detection_rate' => 0.98,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function goldenBenchmark(): array
    {
        $golden = $this->retrievalArena->evaluate(['risk_level' => 'low']);

        return [
            'schema_version' => 'atlas.context.golden_context_benchmark.v1',
            'status' => (string) ($golden['status'] ?? 'unknown'),
            'source' => 'AREBA',
            'arena_hash' => (string) ($golden['arena_hash'] ?? ''),
            'case_count' => (int) data_get($golden, 'summary.case_count', 0),
            'required_source_recall' => (float) data_get($golden, 'summary.metrics.required_source_recall', 0.0),
            'groundedness' => (float) data_get($golden, 'summary.metrics.groundedness', 0.0),
            'context_roi' => (float) data_get($golden, 'summary.metrics.context_roi', 0.0),
            'promotion_gate' => (string) data_get($golden, 'promotion_gate.status', 'unknown'),
        ];
    }

    /**
     * Declared adversarial coverage surface. NOT a measured detection rate —
     * the real adversarial/regression signal lives in {@see LocalRagBenchmarkService}
     * (provider-safe violations, contamination, stale-context use).
     *
     * @param  array<string,mixed>  $corpus
     * @return array<string,mixed>
     */
    private function adversarialEvaluation(array $corpus): array
    {
        $categories = (array) data_get($corpus, 'dimensions.adversarial', []);

        return [
            'schema_version' => 'atlas.context.adversarial_evaluation.v1',
            'status' => 'declared_readiness_only',
            'is_real_measurement' => false,
            'categories' => $categories,
            'category_count' => count($categories),
            'case_count' => (int) $corpus['case_count'],
            'blocked_failure_modes' => [
                'false_memory_promotion',
                'stale_authority_override',
                'irrelevant_test_anchor',
                'dead_file_context',
                'dangerous_instruction_in_context',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $corpus
     * @return array<string,mixed>
     */
    private function replayHarness(array $corpus): array
    {
        return [
            'schema_version' => 'atlas.context.massive_replay_harness.v1',
            'status' => 'declared_readiness_only',
            'is_real_measurement' => false,
            'mode' => 'deterministic_shadow_replay',
            'case_count' => (int) $corpus['case_count'],
            'replay_manifest_policy' => [
                'provider_independent' => true,
                'raw_prompt_replay' => false,
                'hash_refs_required' => true,
                'must_keep_receipts_required' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function embeddingGraphReadiness(): array
    {
        return [
            'schema_version' => 'atlas.context.embedding_graph_readiness.v1',
            'status' => 'declared_readiness_only',
            'is_real_measurement' => false,
            'readiness_mode' => 'internal_runtime_surface_ready_external_blocked',
            'checks' => [
                'embeddings_are_candidates_not_authority' => true,
                'graph_edges_require_evidence_refs' => true,
                'freshness_required_for_time_sensitive_refs' => true,
                'privacy_gate_before_external_vectorization' => true,
                'global_external_graph_rag_still_governed' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $corpus
     * @return array<string,mixed>
     */
    private function aemorSyntheticFeed(array $corpus): array
    {
        return [
            'schema_version' => 'atlas.context.aemor_synthetic_feed.v1',
            'status' => 'declared_readiness_only',
            'is_real_measurement' => false,
            'case_count' => (int) $corpus['case_count'],
            'outcome_event_types' => [
                'patch_passed',
                'patch_failed',
                'test_broke',
                'context_used',
                'context_ignored',
                'human_correction',
                'provider_hit',
                'provider_miss',
                'file_regressed',
                'retrieval_noise',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $stressLab
     * @param  array<string,mixed>  $corpus
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $golden
     * @param  array<string,mixed>  $adversarial
     * @param  array<string,mixed>  $embeddingGraph
     * @param  array<string,mixed>  $aemor
     * @param  array<string,mixed>  $real
     * @return array<int,array<string,mixed>>
     */
    private function components(
        array $stressLab,
        array $corpus,
        array $replay,
        array $golden,
        array $adversarial,
        array $embeddingGraph,
        array $aemor,
        array $real,
        ?float $score,
        float $targetScore,
    ): array {
        // The certification gate can only be `ready` when a REAL measurement
        // exists AND the real score clears the target. With no measurement the
        // gate is honestly `blocked` (synthetic readiness can never certify).
        $gateReady = (bool) $real['available'] && $score !== null && $score >= $targetScore;

        return [
            $this->component('context_stress_lab', 'Atlas Context Stress Lab', 'ACSL', $stressLab['status'] === 'declared_readiness_only'),
            $this->component('synthetic_long_horizon_corpus', 'Synthetic Long-Horizon Corpus', 'SLHC', $corpus['case_count'] >= 1000),
            $this->component('massive_replay_harness', 'Replay Harness Massivo', 'MRH', $replay['status'] === 'declared_readiness_only'),
            $this->component('golden_context_benchmark', 'Golden Context Benchmark', 'GCB', $golden['status'] === 'ready'),
            $this->component('adversarial_context_evaluation', 'Adversarial Context Evaluation', 'ACE', $adversarial['status'] === 'declared_readiness_only'),
            $this->component('embeddings_graph_readiness', 'Embeddings + Graph Fortes', 'EGF', $embeddingGraph['status'] === 'declared_readiness_only'),
            $this->component('aemor_synthetic_feed', 'AEMOR Feeding Simulado', 'AFS', $aemor['status'] === 'declared_readiness_only'),
            $this->component('real_retrieval_measurement', 'Real Retrieval Measurement (Local RAG)', 'RRM', (bool) $real['available']),
            $this->component('context_quality_certification_gate', 'Context Quality Certification Gate', 'CQCG', $gateReady),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function component(string $id, string $name, string $acronym, bool $ready): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'acronym' => $acronym,
            'status' => $ready ? 'ready' : 'blocked',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $components
     * @param  array<string,mixed>  $real
     * @param  array<string,mixed>  $aucri
     * @return array<int,array<string,mixed>>
     */
    private function blockers(array $components, array $real, ?float $score, float $targetScore, array $aucri): array
    {
        $blockers = [];

        if (! (bool) $real['available']) {
            $blockers[] = [
                'id' => 'no_real_measurement',
                'reason' => 'no_real_retrieval_answer_quality_measured:'.(string) ($real['unavailable_reason'] ?? 'unknown'),
            ];
        }

        foreach ($components as $component) {
            if ($component['status'] !== 'ready') {
                $blockers[] = [
                    'id' => 'component_blocked',
                    'component' => $component['id'],
                    'reason' => 'component_not_ready',
                ];
            }
        }

        if ($score === null) {
            $blockers[] = [
                'id' => 'quality_score_unmeasured',
                'reason' => 'no_numeric_score_without_real_measurement',
            ];
        } elseif ($score < $targetScore) {
            $blockers[] = [
                'id' => 'quality_score_below_target',
                'reason' => 'score '.$score.' below target '.$targetScore,
            ];
        }

        if ((bool) $real['available'] && (int) $real['provider_safe_violation_count'] > 0) {
            $blockers[] = [
                'id' => 'provider_safe_violation',
                'reason' => 'real_provider_safe_violation_count_above_zero',
            ];
        }

        if ((int) data_get($aucri, 'block_ref_summary.executed', 0) < 18) {
            $blockers[] = ['id' => 'aucri_runtime_not_complete', 'reason' => 'less_than_18_blocks_executed'];
        }

        return $blockers;
    }

    private function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
