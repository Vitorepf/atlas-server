<?php

namespace App\Services\Ai\Context;

use App\Services\Ai\ValueObjects\AiTaskRequest;

final class LocalRagBenchmarkService
{
    public const SCHEMA_VERSION = 'atlas.local_rag_benchmark.v1';

    public function __construct(
        private readonly ContextRetrievalRouter $router,
        private readonly LocalRagReadinessService $readiness,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $readiness = $this->readiness->report();
        $cases = collect($this->cases())
            ->map(fn (array $case): array => $this->evaluateCase($case))
            ->values()
            ->all();

        $failed = collect($cases)->filter(fn (array $case): bool => $case['status'] !== 'passed')->values();
        $averageScore = collect($cases)->avg('score');
        $readinessReady = $readiness['status'] !== 'blocked';
        $qualityCorpus = $this->qualityCorpusReport($cases, $readinessReady);
        $ledgerContract = $this->ledgerContract();
        $completedPrerequisites = $qualityCorpus['status'] === 'passed'
            ? ['retrieval_quality_corpus', 'latency_p95_measurement', 'privacy_redaction_verification', 'evidence_ledger_contract']
            : [];
        $remainingPrerequisites = array_values(array_diff([
            'retrieval_quality_corpus',
            'latency_p95_measurement',
            'privacy_redaction_verification',
            'evidence_ledger_contract',
            'human_review_or_curator_proposal',
        ], $completedPrerequisites));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed->isEmpty() && $readinessReady ? 'passed' : 'attention',
            'benchmark_type' => 'controlled_router_quality_corpus',
            'runtime_family' => 'laravel_kernel_now_python_ai_data_future',
            'readiness_status' => $readiness['status'],
            'case_count' => count($cases),
            'passed_case_count' => count($cases) - $failed->count(),
            'average_score' => round((float) $averageScore, 4),
            'quality_corpus' => $qualityCorpus,
            'ledger_contract' => $ledgerContract,
            'cases' => $cases,
            'promotion_gate' => [
                'graph_rag_promotion_allowed' => false,
                'policy_patch_status' => 'draft_only_until_quality_latency_privacy_benchmark',
                'reason' => 'Este benchmark prova corpus controlado, governanca do router, privacy boundary sintetico e contrato LOCAL_RAG_* do Evidence Ledger; Graph RAG/Python ainda exige review humano/Curator antes de mudar policy.',
                'completed_prerequisites' => $completedPrerequisites,
                'remaining_prerequisites' => $remainingPrerequisites,
                'required_before_promotion' => array_values(array_unique(array_merge($completedPrerequisites, $remainingPrerequisites))),
            ],
            'guardrails' => [
                'kernel_decides' => true,
                'provider_bypass_allowed' => false,
                'parallel_memory_allowed' => false,
                'python_graph_rag_is_candidate_runtime_only' => true,
            ],
            'next_action' => $failed->isEmpty()
                ? ($readinessReady
                    ? ($qualityCorpus['status'] === 'passed'
                        ? 'obtain_human_review_or_curator_proposal_before_graph_rag_runtime_promotion'
                        : 'fix_quality_corpus_before_graph_rag_runtime_promotion')
                    : 'fix_router_or_readiness_gates_before_graph_rag_work')
                : 'fix_router_or_readiness_gates_before_graph_rag_work',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function cases(): array
    {
        return [
            [
                'id' => 'programming_patch_context',
                'input' => 'implementar patch no repo com bug de teste e evidencias recentes',
                'task' => ['task_type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'low', 'domain' => 'developer'],
                'payload' => ['trace_id' => 'benchmark_trace'],
                'domain_family' => 'programming',
                'privacy_class' => 'internal',
                'expected_sources' => ['vector_retrieval', 'memory_signals', 'code_intelligence', 'evidence_replay'],
                'expected_graph' => false,
            ],
            [
                'id' => 'architecture_relation_context',
                'input' => 'analisar arquitetura, dependencia, impacto e tradeoff antes de mudar runtime',
                'task' => ['task_type' => 'planning', 'desired_mode' => 'plan', 'risk_level' => 'high', 'domain' => 'programming'],
                'payload' => [],
                'domain_family' => 'architecture',
                'privacy_class' => 'internal',
                'expected_sources' => ['vector_retrieval', 'memory_signals', 'code_intelligence', 'evidence_replay', 'graph_retrieval'],
                'expected_graph' => true,
            ],
            [
                'id' => 'constelacao_serendipity_context',
                'input' => 'relacao semantica entre capturas, notas e modelos mentais para constelacao',
                'task' => ['task_type' => 'research', 'desired_mode' => 'explore', 'risk_level' => 'low', 'domain' => 'personal_development'],
                'payload' => ['surface' => 'constelacao'],
                'domain_family' => 'personal_development',
                'privacy_class' => 'personal',
                'expected_sources' => ['vector_retrieval', 'memory_signals', 'graph_retrieval'],
                'expected_graph' => true,
            ],
            [
                'id' => 'finance_review_context',
                'input' => 'avaliar risco financeiro, compliance, evidencia e relacao causal',
                'task' => ['task_type' => 'decision', 'desired_mode' => 'review', 'risk_level' => 'high', 'domain' => 'finance'],
                'payload' => ['receipt_id' => 'benchmark_receipt'],
                'domain_family' => 'finance',
                'privacy_class' => 'restricted',
                'expected_sources' => ['vector_retrieval', 'memory_signals', 'evidence_replay', 'graph_retrieval'],
                'expected_graph' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function evaluateCase(array $case): array
    {
        $startedAt = hrtime(true);
        $plan = $this->router->plan(
            (string) $case['input'],
            new AiTaskRequest((array) $case['task']),
            (array) $case['payload'],
        );
        $latencyMs = (hrtime(true) - $startedAt) / 1_000_000;
        $sources = collect($plan['selected_sources'])->keyBy('type');
        $expected = collect($case['expected_sources']);
        $missingExpected = $expected->reject(fn (string $type): bool => $sources->has($type))->values()->all();
        $providerBypass = collect($plan['selected_sources'])
            ->contains(fn (array $source): bool => (bool) ($source['provider_bypass_allowed'] ?? false));
        $graph = $sources->get('graph_retrieval');

        $checks = [
            'expected_sources_selected' => $missingExpected === [],
            'required_sources_available' => data_get($plan, 'readiness.required_unavailable_sources') === [],
            'provider_bypass_blocked' => ! $providerBypass && data_get($plan, 'policy.provider_bypass_allowed') === false,
            'parallel_memory_blocked' => data_get($plan, 'policy.do_not_create_parallel_memory') === true,
            'graph_rag_governed_when_selected' => ! (bool) $case['expected_graph'] || (
                is_array($graph)
                && data_get($graph, 'status') === 'future_governed'
                && data_get($graph, 'runtime') === 'python_ai_data_candidate'
                && data_get($graph, 'provider_bypass_allowed') === false
                && data_get($graph, 'required') === false
            ),
        ];
        $score = collect($checks)->filter()->count() / count($checks);

        return [
            'id' => $case['id'],
            'status' => $score >= 1.0 ? 'passed' : 'failed',
            'score' => round($score, 4),
            'domain_family' => $case['domain_family'],
            'privacy_class' => $case['privacy_class'],
            'latency_ms' => round($latencyMs, 4),
            'mode' => $plan['mode'],
            'readiness_status' => data_get($plan, 'readiness.status'),
            'selected_sources' => array_values($sources->keys()->all()),
            'missing_expected_sources' => $missingExpected,
            'unavailable_selected_sources' => data_get($plan, 'readiness.unavailable_selected_sources'),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function qualityCorpusReport(array $cases, bool $readinessReady): array
    {
        $scores = collect($cases)->pluck('score')->map(fn (mixed $score): float => (float) $score)->values()->all();
        $latencies = collect($cases)->pluck('latency_ms')->map(fn (mixed $latency): float => (float) $latency)->values()->all();
        $domainFamilies = collect($cases)->pluck('domain_family')->unique()->sort()->values()->all();
        $privacyClasses = collect($cases)->pluck('privacy_class')->unique()->sort()->values()->all();
        $minScore = $scores === [] ? 0.0 : min($scores);
        $averageScore = $scores === [] ? 0.0 : array_sum($scores) / count($scores);
        $p95Latency = $this->percentile($latencies, 95);
        $coverageRequired = ['architecture', 'finance', 'personal_development', 'programming'];
        $missingCoverage = array_values(array_diff($coverageRequired, $domainFamilies));

        $checks = [
            'readiness_not_blocked' => $readinessReady,
            'minimum_case_count' => count($cases) >= 4,
            'domain_coverage' => $missingCoverage === [],
            'minimum_case_score' => $minScore >= 1.0,
            'average_score_threshold' => $averageScore >= 0.95,
            'latency_p95_measured' => $p95Latency !== null && $p95Latency <= 250.0,
            'privacy_boundary_declared' => in_array('restricted', $privacyClasses, true)
                && in_array('personal', $privacyClasses, true)
                && collect($cases)->every(fn (array $case): bool => data_get($case, 'checks.provider_bypass_blocked') === true),
            'graph_future_governed' => collect($cases)->every(fn (array $case): bool => data_get($case, 'checks.graph_rag_governed_when_selected') === true),
        ];

        return [
            'schema_version' => 'atlas.local_rag_quality_corpus.v1',
            'corpus_id' => 'local_rag_controlled_router_quality_v1',
            'status' => collect($checks)->every(fn (bool $passed): bool => $passed) ? 'passed' : 'attention',
            'evaluation_mode' => 'deterministic_controlled_queries',
            'case_ids' => collect($cases)->pluck('id')->values()->all(),
            'coverage' => [
                'domain_families' => $domainFamilies,
                'missing_domain_families' => $missingCoverage,
                'privacy_classes' => $privacyClasses,
            ],
            'thresholds' => [
                'min_cases' => 4,
                'min_case_score' => 1.0,
                'min_average_score' => 0.95,
                'max_latency_p95_ms' => 250.0,
            ],
            'metrics' => [
                'min_score' => round($minScore, 4),
                'average_score' => round($averageScore, 4),
                'latency_p95_ms' => $p95Latency === null ? null : round($p95Latency, 4),
            ],
            'checks' => $checks,
            'limits' => [
                'synthetic_corpus_only' => true,
                'does_not_measure_retrieval_answer_quality_yet' => true,
                'does_not_authorize_python_graph_rag_promotion' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerContract(): array
    {
        return [
            'schema_version' => 'atlas.local_rag.ledger_contract.v1',
            'status' => 'declared',
            'event_family' => 'LOCAL_RAG_*',
            'event_types' => [
                'LOCAL_RAG_PLAN_CREATED',
                'LOCAL_RAG_QUALITY_CORPUS_EVALUATED',
                'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED',
            ],
            'recording_method' => 'AtlasEvidenceLedger::recordLocalRagEvent',
            'payload_contract' => [
                'raw_context_persistence_allowed' => false,
                'raw_query_persistence_allowed' => false,
                'query_hash_required_when_query_seen' => true,
                'sources_must_be_hash_or_id_only' => true,
                'graph_promotion_must_emit_block_or_review_event' => true,
            ],
            'promotion_rule' => 'Graph RAG/Python policy patch remains blocked until LOCAL_RAG_GRAPH_PROMOTION_BLOCKED is superseded by human-reviewed Curator proposal.',
        ];
    }

    /**
     * @param  array<int,float>  $values
     */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = ((count($values) - 1) * $percentile) / 100;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return $values[$lower];
        }

        $weight = $index - $lower;

        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }
}
