<?php

namespace App\Services\Ai\Context;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ValueObjects\AiTaskRequest;

// Intentionally not `final`: this read-only benchmark is constructor-injected
// into AtlasContextQualityCertificationService, which must be able to substitute
// a deterministic real-shaped report in tests (the quality score derives ONLY
// from this harness). Subclassing carries no governance authority — report() is
// pure measurement with no writes, no provider calls, no policy mutation.
class LocalRagBenchmarkService
{
    public const SCHEMA_VERSION = 'atlas.local_rag_benchmark.v1';

    public function __construct(
        private readonly ContextRetrievalRouter $router,
        private readonly LocalRagReadinessService $readiness,
        private readonly AtlasHybridMemoryRetrievalService $memoryRetrieval,
        private readonly AtlasMemoryPrivacyService $memoryPrivacy,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
        private readonly LocalRagPrecisionCorpusService $precisionCorpus,
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
        $memoryRecallCorpus = $this->memoryRecallCorpusReport();
        $memoryRecallGoldenVersions = $this->memoryRecallGoldenFixtureReports();
        $memoryRecallGolden = $this->legacyMemoryRecallGoldenReport($memoryRecallGoldenVersions['v1']);
        // R8: the HONEST independent retrieval-precision number. Unlike the
        // memory-recall corpus above (whose query is the target's own
        // title+summary — a near-tautological known-item lexical test), this runs
        // paraphrased/conceptual queries that are INDEPENDENT of the target text
        // through the REAL semantic engine and reports real precision@k/recall@k,
        // or an honest `attention`/unmeasured when the engine/corpus is absent.
        $independentPrecisionCorpus = $this->precisionCorpus->report();
        $retrievalRivalsPacket = $this->retrievalRivalsPacket($qualityCorpus, $memoryRecallCorpus);
        $ledgerContract = $this->ledgerContract();
        $completedPrerequisites = $qualityCorpus['status'] === 'passed'
            ? ['retrieval_quality_corpus', 'latency_p95_measurement', 'privacy_redaction_verification', 'evidence_ledger_contract']
            : [];
        if (($memoryRecallCorpus['status'] ?? null) === 'passed') {
            $completedPrerequisites[] = 'real_corpus_retrieval_answer_quality';
        }
        if (($independentPrecisionCorpus['status'] ?? null) === 'passed') {
            $completedPrerequisites[] = 'independent_retrieval_precision_corpus';
        }
        $remainingPrerequisites = array_values(array_diff([
            'retrieval_quality_corpus',
            'latency_p95_measurement',
            'privacy_redaction_verification',
            'evidence_ledger_contract',
            'human_review_or_curator_proposal',
            'future_graph_rag_python_ap',
            'decision_receipt_for_runtime_promotion',
            'reviewable_policy_patch_with_rollback',
            'real_corpus_retrieval_answer_quality',
            'independent_retrieval_precision_corpus',
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
            'memory_recall_corpus' => $memoryRecallCorpus,
            'memory_recall_golden' => $memoryRecallGolden,
            'memory_recall_golden_versions' => $memoryRecallGoldenVersions,
            'memory_recall_golden_v2' => $memoryRecallGoldenVersions['v2'] ?? null,
            'independent_precision_corpus' => $independentPrecisionCorpus,
            'retrieval_rivals_packet' => $retrievalRivalsPacket,
            'ledger_contract' => $ledgerContract,
            'cases' => $cases,
            'promotion_review_contract' => [
                'schema_version' => 'atlas.local_rag_graph_promotion_review.v1',
                'status' => 'human_review_required',
                'review_ap' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
                'architecture_operation_id' => 'local_rag_graph_promotion_review',
                'human_review_required' => true,
                'curator_proposal_required' => true,
                'future_ap_required' => true,
                'decision_receipt_required' => true,
                'rollback_plan_required' => true,
                'policy_patch_review_required' => true,
                'promotion_allowed' => false,
                'auto_promotion_allowed' => false,
                'future_runtime_invocation_contract' => $this->futureGraphRuntimeInvocationContract(),
                'external_vector_rag_preflight_contract' => $this->externalRetrievalPromotionPreflight($qualityCorpus, $memoryRecallCorpus),
                'review_packet' => $this->promotionReviewPacket($qualityCorpus),
                'allowed_outputs' => [
                    'proposal_only',
                    'review_packet',
                    'external_vector_rag_preflight_contract',
                    'future_ap_scope',
                    'rollback_plan',
                ],
                'forbidden_outputs' => [
                    'policy_patch_auto_apply',
                    'python_runtime_auto_enable',
                    'graph_rag_auto_enable',
                    'external_vector_store_auto_enable',
                    'embedding_generation_auto_enable',
                    'parallel_memory_creation',
                    'provider_bypass',
                ],
                'next_action' => 'submit_local_rag_graph_promotion_for_human_review',
            ],
            'promotion_gate' => [
                'promotion_allowed' => false,
                'graph_rag_promotion_allowed' => false,
                'python_runtime_promotion_allowed' => false,
                'policy_patch_status' => 'draft_only_until_quality_latency_privacy_benchmark',
                'supersedes_event_required' => 'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED',
                'supersede_authority' => 'human_reviewed_curator_proposal_and_future_ap',
                'reason' => 'Este benchmark prova corpus controlado, governanca do router, privacy boundary sintetico e contrato LOCAL_RAG_* do Evidence Ledger; Graph RAG/Python ainda exige review humano/Curator antes de mudar policy.',
                'completed_prerequisites' => $completedPrerequisites,
                'remaining_prerequisites' => $remainingPrerequisites,
                'required_before_promotion' => AtlasContextStringListNormalizer::uniqueTrimmedStrings(
                    array_merge($completedPrerequisites, $remainingPrerequisites),
                ),
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
     * @param  array<string,mixed>  $qualityCorpus
     * @return array<string,mixed>
     */
    private function promotionReviewPacket(array $qualityCorpus): array
    {
        return [
            'schema_version' => 'atlas.local_rag_graph_promotion_review_packet.v1',
            'status' => 'blocked_until_human_review_and_future_ap',
            'required_human_decision' => 'approve_or_reject_graph_rag_python_future_ap_scope',
            'evidence_required' => [
                'LOCAL_RAG_PLAN_CREATED',
                'LOCAL_RAG_QUALITY_CORPUS_EVALUATED',
                'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED',
                'quality_corpus.status=passed',
                'real_corpus_retrieval_answer_quality',
                'rivals_or_benchmark_delta',
            ],
            'rollback_required' => [
                'keep_context_retrieval_router_graph_available_false',
                'disable_python_graph_rag_runtime_policy',
                'return_to_semantic_rag_or_existing_retrieval_plan',
                'preserve_ledger_replay_of_promotion_attempt',
            ],
            'forbidden_until_review' => [
                'set_graph_retrieval_available_true',
                'enable_python_graph_rag_runtime',
                'auto_apply_policy_patch',
                'create_parallel_memory_or_context_store',
                'surface_direct_graph_rag_call',
            ],
            'quality_corpus_status' => $qualityCorpus['status'] ?? 'unknown',
        ];
    }

    /**
     * @param  array<string,mixed>  $qualityCorpus
     * @param  array<string,mixed>  $memoryRecallCorpus
     * @return array<string,mixed>
     */
    private function externalRetrievalPromotionPreflight(array $qualityCorpus, array $memoryRecallCorpus): array
    {
        $goldenSet = (array) ($memoryRecallCorpus['golden_set'] ?? []);
        $lineage = [
            'quality_corpus_id' => (string) ($qualityCorpus['corpus_id'] ?? 'unknown'),
            'quality_corpus_status' => (string) ($qualityCorpus['status'] ?? 'unknown'),
            'memory_recall_status' => (string) ($memoryRecallCorpus['status'] ?? 'unknown'),
            'memory_recall_case_count' => (int) ($memoryRecallCorpus['case_count'] ?? 0),
            'memory_recall_golden_set_hash' => hash('sha256', json_encode($goldenSet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
        ];

        $payload = [
            'schema_version' => 'atlas.external_vector_rag.promotion_preflight.v1',
            'status' => 'blocked_until_human_review_ap_and_decision_receipt',
            'mode' => 'proposal_only_no_embedding_no_external_runtime',
            'model_ownership' => [
                'structure_mother_modules' => [
                    'Memory/Context Engine',
                    'Knowledge Base/Open Brain',
                ],
                'not_owned_by' => 'Voice/LiveKit',
                'primary_future_surface' => 'Constelacao',
            ],
            'runtime_family' => 'python_ai_data',
            'candidate_capability_id' => 'memory_open_brain.external_vector_rag_candidate',
            'architecture_operation_id' => 'external_vector_rag_promotion_preflight',
            'proposal_only' => true,
            'human_review_required' => true,
            'future_ap_required' => true,
            'decision_receipt_required' => true,
            'runtime_invocation_contract_required' => true,
            'rollback_plan_required' => true,
            'privacy_retention_delete_cascade_review_required' => true,
            'cost_storage_slo_review_required' => true,
            'provider_safety_review_required' => true,
            'golden_set_benchmark_required' => true,
            'evidence_ledger_required' => true,
            'lineage' => $lineage,
            'execution_gate' => [
                'status' => 'blocked',
                'runtime_execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'embedding_generation_allowed_now' => false,
                'external_vector_store_read_allowed' => false,
                'external_vector_store_write_allowed' => false,
                'memory_write_allowed' => false,
                'context_builder_write_allowed' => false,
                'constellation_promotion_allowed' => false,
                'policy_auto_apply_allowed' => false,
                'raw_content_export_allowed' => false,
            ],
            'required_before_any_embedding_or_external_rag' => [
                'docs/ap/AP-683-local-rag-graph-promotion-review.md',
                'docs/ap/AP-684-graphify-external-graph-harness.md',
                'successor_external_vector_rag_runtime_ap',
                'human_reviewed_curator_proposal',
                'decision_receipt_hash',
                'atlas.runtime_invocation_contract.v1',
                'provider_safe_redaction_and_privacy_review',
                'retention_policy_and_delete_cascade_for_embeddings',
                'golden_set_benchmark_with_no_contamination',
                'evidence_ledger_event_contract',
                'rollback_plan',
                'cost_storage_slo_freshness_review',
            ],
            'review_packet' => [
                'schema_version' => 'atlas.external_vector_rag.promotion_preflight_review_packet.v1',
                'status' => 'human_review_required',
                'required_human_decision' => 'approve_or_reject_external_vector_rag_promotion_scope',
                'evidence_required' => [
                    'LOCAL_RAG_QUALITY_CORPUS_EVALUATED',
                    'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED',
                    'memory_recall_golden_set_hash',
                    'AP-683_or_successor_scope',
                    'AP-684_external_graph_review_packet',
                    'privacy_retention_delete_cascade_review',
                    'cost_storage_slo_review',
                ],
                'forbidden_actions' => [
                    'generate_external_embeddings',
                    'write_external_vector_store',
                    'read_external_vector_store_for_context',
                    'execute_python_graph_rag',
                    'send_raw_capture_to_provider',
                    'promote_to_constelacao',
                    'auto_apply_policy_patch',
                    'bypass_kernel_decision_receipt',
                ],
            ],
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'raw_capture_exposed' => false,
        ];
        $payload['preflight_hash_algorithm'] = 'sha256';
        $payload['preflight_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,array<string,mixed>>
     */
    public function recordEvidence(array $report): array
    {
        $benchmarkId = 'local_rag_benchmark:'.hash('sha256', (string) data_get($report, 'quality_corpus.corpus_id', 'unknown'));
        $context = [
            'envelope_id' => $benchmarkId,
            'correlation_id' => $benchmarkId,
            'emitter_stage' => 'atlas.context_retrieval.local_rag_benchmark',
            'emitter_version' => self::SCHEMA_VERSION,
        ];

        $events = [
            $this->ledger->recordLocalRagEvent(LedgerEventType::LocalRagPlanCreated, [
                'benchmark_id' => $benchmarkId,
                'status' => $report['status'] ?? null,
                'readiness_status' => $report['readiness_status'] ?? null,
                'case_count' => $report['case_count'] ?? null,
                'passed_case_count' => $report['passed_case_count'] ?? null,
                'average_score' => $report['average_score'] ?? null,
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
            ], $context),
            $this->ledger->recordLocalRagEvent(LedgerEventType::LocalRagQualityCorpusEvaluated, [
                'benchmark_id' => $benchmarkId,
                'quality_corpus' => $report['quality_corpus'] ?? [],
                'memory_recall_corpus' => $report['memory_recall_corpus'] ?? [],
                'memory_recall_golden' => $report['memory_recall_golden'] ?? [],
                'memory_recall_golden_versions' => $report['memory_recall_golden_versions'] ?? [],
                'memory_recall_golden_v2' => $report['memory_recall_golden_v2'] ?? [],
                'retrieval_rivals_packet' => $report['retrieval_rivals_packet'] ?? [],
                'case_ids' => collect($report['cases'] ?? [])->pluck('id')->values()->all(),
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
            ], $context),
            $this->ledger->recordLocalRagEvent(LedgerEventType::LocalRagGraphPromotionBlocked, [
                'benchmark_id' => $benchmarkId,
                'promotion_gate' => $report['promotion_gate'] ?? [],
                'promotion_review_contract' => $report['promotion_review_contract'] ?? [],
                'retrieval_rivals_packet' => $report['retrieval_rivals_packet'] ?? [],
                'guardrails' => $report['guardrails'] ?? [],
                'next_action' => $report['next_action'] ?? null,
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
            ], $context),
        ];

        return collect($events)
            ->map(fn (?AtlasLedgerEvent $event): array => $this->ledgerEventPayload($event))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public function evidenceLedgerReport(array $report): array
    {
        $events = $this->recordEvidence($report);
        $expectedEventCount = 3;
        $recordedCount = collect($events)
            ->filter(fn (array $event): bool => (bool) ($event['recorded'] ?? false))
            ->count();
        $persisted = $recordedCount === $expectedEventCount;

        return [
            'schema_version' => 'atlas.local_rag.evidence_ledger_report.v1',
            'status' => $persisted ? 'persisted' : 'unavailable',
            'event_family' => 'LOCAL_RAG_*',
            'expected_event_count' => $expectedEventCount,
            'recorded_event_count' => $recordedCount,
            'persistence_required_for_promotion' => true,
            'promotion_evidence_satisfied' => $persisted,
            'missing_reason' => $persisted ? null : 'atlas_ledger_events_table_unavailable_or_write_failed',
            'events' => $events,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public function withGoldenJudgeEvidence(array $report): array
    {
        $versions = (array) ($report['memory_recall_golden_versions'] ?? []);

        foreach ($versions as $version => $golden) {
            if (! is_array($golden)) {
                continue;
            }

            $versions[$version] = $this->applyGoldenJudgeEvidence($golden);
        }

        $report['memory_recall_golden_versions'] = $versions;
        if (isset($versions['v1'])) {
            $report['memory_recall_golden'] = $this->legacyMemoryRecallGoldenReport($versions['v1']);
        }
        if (isset($versions['v2'])) {
            $report['memory_recall_golden_v2'] = $versions['v2'];
        }

        return $report;
    }

    /**
     * @param  array<string,mixed>  $golden
     * @return array<string,mixed>
     */
    private function applyGoldenJudgeEvidence(array $golden): array
    {
        $hash = (string) ($golden['frozen_set_hash'] ?? '');
        $version = (string) ($golden['version'] ?? '');
        if ($hash === '' || $version === 'v1') {
            return $golden;
        }

        $evidence = $this->goldenJudgeEvidence($hash);
        $golden['judged'] = $evidence !== null;
        $golden['judge_event_id'] = $evidence['event_id'] ?? null;
        $golden['judge_event_hash'] = $evidence['event_hash'] ?? null;
        $golden['judge_payload_hash'] = $evidence['payload_hash'] ?? null;
        data_set($golden, 'checks.judge_event_recorded', $evidence !== null);
        $golden['status'] = $this->memoryRecallGoldenStatus((array) ($golden['checks'] ?? []), true);

        return $golden;
    }

    /**
     * Keep the historical `memory_recall_golden` surface as the RAG-05 v1 payload.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function legacyMemoryRecallGoldenReport(array $report): array
    {
        foreach ([
            'version',
            'r5',
            'fd',
            'targets_available',
            'expected_source_available_declared',
            'source_availability_ratio',
            'judged',
            'judge_event_id',
            'judge_event_hash',
            'judge_payload_hash',
            'case_results',
        ] as $key) {
            unset($report[$key]);
        }

        return $report;
    }

    /**
     * @return array<string,string>|null
     */
    private function goldenJudgeEvidence(string $frozenSetHash): ?array
    {
        if ($frozenSetHash === '' || ! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return null;
        }

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::LocalRagQualityCorpusEvaluated->value)
            ->latest('occurred_at')
            ->limit(200)
            ->get()
            ->first(function (AtlasLedgerEvent $event) use ($frozenSetHash): bool {
                $payload = is_array($event->payload) ? $event->payload : [];
                $localRag = is_array(data_get($payload, 'local_rag')) ? (array) data_get($payload, 'local_rag') : $payload;

                return collect([
                    data_get($localRag, 'memory_recall_golden.frozen_set_hash'),
                    data_get($localRag, 'memory_recall_golden_v2.frozen_set_hash'),
                    ...collect((array) data_get($localRag, 'memory_recall_golden_versions', []))
                        ->map(fn (mixed $golden): mixed => is_array($golden) ? ($golden['frozen_set_hash'] ?? null) : null)
                        ->all(),
                ])->contains($frozenSetHash);
            });

        if (! $event instanceof AtlasLedgerEvent) {
            return null;
        }

        return [
            'event_id' => (string) $event->event_id,
            'event_hash' => (string) ($event->event_hash ?: $event->payload_hash),
            'payload_hash' => (string) $event->payload_hash,
        ];
    }

    /**
     * @param  array<string,bool>  $checks
     */
    private function memoryRecallGoldenStatus(array $checks, bool $isV2OrLater): string
    {
        if (collect($checks)->every(fn (bool $passed): bool => $passed)) {
            return 'passed';
        }

        if ($isV2OrLater && ($checks['source_availability_floor'] ?? true) === false) {
            return 'pending_window:live_recall_corpus_insufficient';
        }

        return 'attention';
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
    private function memoryRecallCorpusReport(): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries') && ! DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            return $this->emptyMemoryRecallCorpus('memory_tables_missing');
        }

        $fixtures = $this->memoryRecallFixtures();
        if ($fixtures === []) {
            return $this->emptyMemoryRecallCorpus('no_governed_provider_safe_memory');
        }

        $cases = collect($fixtures)
            ->map(fn (array $fixture): array => $this->evaluateMemoryRecallFixture($fixture))
            ->values()
            ->all();
        $precisionAt3 = collect($cases)->avg('precision_at_3') ?? 0.0;
        $precisionAt5 = collect($cases)->avg('precision_at_5') ?? 0.0;
        $missedCritical = collect($cases)->sum('missed_critical_context_count');
        $contamination = collect($cases)->sum('context_contamination_count');
        $providerSafeViolations = collect($cases)->sum('provider_safe_violation_count');
        $staleUse = collect($cases)->sum('stale_context_use_count');
        $budgetTruncation = collect($cases)->sum('budget_truncation_count');
        $reasonCoverage = collect($cases)->avg('reason_coverage') ?? 0.0;
        $checks = [
            'minimum_case_count' => count($cases) >= 2,
            'precision_at_3_threshold' => $precisionAt3 >= 0.80,
            'precision_at_5_threshold' => $precisionAt5 >= 0.80,
            'no_missed_critical_context' => $missedCritical === 0,
            'no_context_contamination' => $contamination === 0,
            'no_provider_safe_violation' => $providerSafeViolations === 0,
            'no_stale_context_use' => $staleUse === 0,
            'budget_truncation_explained' => $budgetTruncation === 0,
            'reason_coverage_complete' => $reasonCoverage >= 1.0,
        ];

        return [
            'schema_version' => 'atlas.memory_recall_real_corpus.v1',
            'status' => collect($checks)->every(fn (bool $passed): bool => $passed) ? 'passed' : 'attention',
            'evaluation_mode' => 'promoted_memory_hybrid_recall',
            'self_retrieval_sanity' => true,
            'case_count' => count($cases),
            'case_ids' => collect($cases)->pluck('id')->values()->all(),
            'golden_set' => $this->memoryRecallGoldenSet($fixtures),
            'thresholds' => [
                'min_cases' => 2,
                'precision_at_3' => 0.80,
                'precision_at_5' => 0.80,
                'context_contamination_count' => 0,
                'provider_safe_violation_count' => 0,
                'stale_context_use_count' => 0,
                'budget_truncation_count' => 0,
                'reason_coverage' => 1.0,
            ],
            'metrics' => [
                'precision_at_3' => round((float) $precisionAt3, 4),
                'precision_at_5' => round((float) $precisionAt5, 4),
                'missed_critical_context_count' => (int) $missedCritical,
                'context_contamination_count' => (int) $contamination,
                'provider_safe_violation_count' => (int) $providerSafeViolations,
                'stale_context_use_count' => (int) $staleUse,
                'budget_truncation_count' => (int) $budgetTruncation,
                'reason_coverage' => round((float) $reasonCoverage, 4),
            ],
            'checks' => $checks,
            'cases' => $cases,
            'limits' => [
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
                'provider_safe_only' => true,
                'promoted_memory_preferred' => true,
                'governed_provider_safe_fallback_allowed' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function memoryRecallFixtures(): array
    {
        $promoted = $this->nonEmptyMemoryRecallFixtures($this->promotedMemoryRecallFixtures());

        if (count($promoted) >= 2) {
            return array_slice($promoted, 0, 6);
        }

        // A single promoted row is not a corpus. Preserve promoted-first
        // ordering, then top up from governed provider-safe active memory until
        // the minimum independent cases can actually be measured. Dedup by the
        // canonical source ref; no row is minted or promoted here.
        $fixtures = [];
        $seen = [];
        foreach ([
            ...$promoted,
            ...$this->nonEmptyMemoryRecallFixtures($this->governedProviderSafeMemoryRecallFixtures()),
        ] as $fixture) {
            $key = (string) ($fixture['source_ref_type'] ?? '').':'.(string) ($fixture['source_ref_id'] ?? '');
            if ($key === ':' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fixtures[] = $fixture;
            if (count($fixtures) >= 2) {
                break;
            }
        }

        return $fixtures;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function promotedMemoryRecallFixtures(): array
    {
        $fixtures = [];

        if (DatabaseTableAvailability::has('atlas_memory_entries')) {
            AtlasMemoryEntry::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->where('source_type', 'ai_memory_delta')
                ->where('external_ai_allowed', true)
                ->whereIn('privacy_class', ['normal'])
                ->latest('recorded_at')
                ->limit(3)
                ->get()
                ->each(function (AtlasMemoryEntry $entry) use (&$fixtures): void {
                    $fixtures[] = [
                        'id' => 'registry:'.hash('sha256', $entry->id),
                        'source_ref_type' => 'atlas_memory_entry',
                        'source_ref_id' => $entry->id,
                        'query' => trim(implode(' ', array_filter([$entry->title, $entry->summary]))),
                        'context' => $this->memoryContext($entry),
                        'source' => 'memory_registry_promoted',
                    ];
                });
        }

        if (DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            AtlasVerbatimMemory::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereIn('source_type', ['capture', 'semantic_curation_proposal'])
                ->where('external_ai_allowed', true)
                ->whereIn('privacy_class', ['normal'])
                ->whereNotNull('redacted_text')
                ->latest('recorded_at')
                ->limit(3)
                ->get()
                ->each(function (AtlasVerbatimMemory $memory) use (&$fixtures): void {
                    $fixtures[] = [
                        'id' => 'verbatim:'.hash('sha256', $memory->id),
                        'source_ref_type' => 'atlas_verbatim_memory',
                        'source_ref_id' => $memory->id,
                        'query' => trim(implode(' ', array_filter([$memory->title, $memory->summary]))),
                        'context' => $this->memoryContext($memory),
                        'source' => 'verbatim_store_promoted',
                    ];
                });
        }

        return $fixtures;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function governedProviderSafeMemoryRecallFixtures(): array
    {
        $fixtures = [];

        if (DatabaseTableAvailability::has('atlas_memory_entries')) {
            AtlasMemoryEntry::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->latest('recorded_at')
                ->limit(12)
                ->get()
                ->filter(fn (AtlasMemoryEntry $entry): bool => $this->memoryPrivacy->providerAllowed($entry))
                ->take(3)
                ->each(function (AtlasMemoryEntry $entry) use (&$fixtures): void {
                    $fixtures[] = [
                        'id' => 'registry:'.hash('sha256', $entry->id),
                        'source_ref_type' => 'atlas_memory_entry',
                        'source_ref_id' => $entry->id,
                        'query' => trim(implode(' ', array_filter([
                            $this->memoryPrivacy->providerTitle($entry),
                            $this->memoryPrivacy->providerSummary($entry),
                        ]))),
                        'context' => $this->memoryContext($entry),
                        'source' => 'memory_registry_governed_provider_safe_fallback',
                    ];
                });
        }

        if (DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            AtlasVerbatimMemory::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->where('external_ai_allowed', true)
                ->whereNotNull('redacted_text')
                ->latest('recorded_at')
                ->limit(3)
                ->get()
                ->each(function (AtlasVerbatimMemory $memory) use (&$fixtures): void {
                    $fixtures[] = [
                        'id' => 'verbatim:'.hash('sha256', $memory->id),
                        'source_ref_type' => 'atlas_verbatim_memory',
                        'source_ref_id' => $memory->id,
                        'query' => trim(implode(' ', array_filter([$memory->title, $memory->summary]))),
                        'context' => $this->memoryContext($memory),
                        'source' => 'verbatim_store_governed_provider_safe_fallback',
                    ];
                });
        }

        return $fixtures;
    }

    /**
     * @param  array<int,array<string,mixed>>  $fixtures
     * @return array<int,array<string,mixed>>
     */
    private function nonEmptyMemoryRecallFixtures(array $fixtures): array
    {
        return array_values(array_filter($fixtures, fn (array $fixture): bool => trim((string) $fixture['query']) !== ''));
    }

    /**
     * @param  array<int,array<string,mixed>>  $fixtures
     * @return array<string,mixed>
     */
    private function memoryRecallGoldenSet(array $fixtures): array
    {
        return [
            'schema_version' => 'atlas.memory_recall_golden_set.v1',
            'case_count' => count($fixtures),
            'selection_rule' => collect($fixtures)->contains(fn (array $fixture): bool => str_contains((string) ($fixture['source'] ?? ''), 'fallback'))
                ? 'promoted_provider_safe_latest_first_else_governed_provider_safe_active_latest_first'
                : 'promoted_provider_safe_registry_or_verbatim_memory_latest_first',
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'cases' => collect($fixtures)
                ->map(fn (array $fixture): array => [
                    'case_id' => $fixture['id'],
                    'objective_hash' => hash('sha256', (string) ($fixture['query'] ?? '')),
                    'surface' => 'memory_recall',
                    'source' => (string) ($fixture['source'] ?? 'memory'),
                    'must_include' => [[
                        'source_ref_type' => (string) ($fixture['source_ref_type'] ?? 'unknown'),
                        'source_ref_hash' => hash('sha256', (string) ($fixture['source_ref_id'] ?? '')),
                    ]],
                    'must_exclude' => [
                        'raw_private_note',
                        'secret_or_restricted_memory',
                        'unredacted_capture_text',
                        'unsafe_token_or_password',
                        'stale_review_recommended_context',
                    ],
                    'critical_invariants' => [
                        'provider_safe_only',
                        'reason_required_for_each_ref',
                        'no_raw_query_or_context_in_report_or_ledger',
                    ],
                    'expected_reasons' => [
                        'registry_or_verbatim_recall_reason',
                        'lineage_ref_hash',
                        'freshness_status',
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function memoryRecallGoldenFixtureReports(): array
    {
        $fixtures = $this->loadMemoryRecallGoldenFixtures();
        $reports = [];

        foreach ($fixtures as $version => $fixture) {
            $reports[$version] = $this->memoryRecallGoldenFixtureReport($fixture, $version);
        }

        if (! isset($reports['v1'])) {
            $reports['v1'] = $this->missingMemoryRecallGoldenFixtureReport('v1', 'memory_recall_golden_2026_07_rag05_seed');
        }

        ksort($reports, SORT_NATURAL);

        return $reports;
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function memoryRecallGoldenFixtureReport(array $fixture, string $version): array
    {
        $frozenSetHash = hash('sha256', json_encode($fixture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $cases = collect((array) ($fixture['cases'] ?? []))
            ->filter(fn (mixed $case): bool => is_array($case))
            ->map(fn (array $case): array => $this->evaluateMemoryRecallGoldenCase($case))
            ->values()
            ->all();
        $recallAt3 = collect($cases)->avg('recall_at_3_hit') ?? 0.0;
        $recallAt5 = collect($cases)->avg('recall_at_5_hit') ?? 0.0;
        $improperFloorDiscards = (int) collect($cases)->sum('improper_floor_discard_count');
        $providerSafeViolations = (int) collect($cases)->sum('provider_safe_violation_count');
        $caseCount = count($cases);
        $targetsAvailable = (int) collect($cases)->filter(fn (array $case): bool => (bool) ($case['expected_source_available'] ?? false))->count();
        $declaredAvailable = (int) collect((array) ($fixture['cases'] ?? []))
            ->filter(fn (mixed $case): bool => is_array($case) && (bool) ($case['expected_source_available'] ?? false))
            ->count();
        $sourceAvailabilityRatio = $caseCount > 0 ? $targetsAvailable / $caseCount : 0.0;
        $isV2OrLater = $version !== 'v1';
        $judgeEvidence = $this->goldenJudgeEvidence($frozenSetHash);
        $checks = [
            'minimum_case_count' => $caseCount >= 25,
            'zero_improper_floor_discards' => $improperFloorDiscards === 0,
            'provider_safe_reviewed' => (bool) ($fixture['provider_safe_reviewed'] ?? false),
            'author_judge_separated' => (bool) ($fixture['judge_differs_from_author'] ?? false),
            'no_provider_safe_violation' => $providerSafeViolations === 0,
            'raw_query_not_persisted' => (bool) ($fixture['raw_query_persisted'] ?? true) === false,
            'raw_context_not_persisted' => (bool) ($fixture['raw_context_persisted'] ?? true) === false,
        ];
        if ($isV2OrLater) {
            $checks['expected_source_available_declared'] = $declaredAvailable === $caseCount && $caseCount > 0;
            $checks['source_availability_floor'] = $sourceAvailabilityRatio >= 0.90;
            $checks['judge_event_recorded'] = $judgeEvidence !== null;
        } else {
            $checks['recall_at_3_threshold'] = $recallAt3 >= 0.80;
            $checks['recall_at_5_threshold'] = $recallAt5 >= 0.80;
        }

        $payload = [
            'schema_version' => 'atlas.memory_recall_golden_set.v1',
            'version' => $version,
            'status' => $this->memoryRecallGoldenStatus($checks, $isV2OrLater),
            'frozen_set_id' => (string) ($fixture['frozen_set_id'] ?? 'memory_recall_golden_2026_07_rag05_seed'),
            'provider_safe_reviewed' => (bool) ($fixture['provider_safe_reviewed'] ?? false),
            'author' => (string) ($fixture['author'] ?? 'unknown'),
            'judge' => (string) ($fixture['judge'] ?? 'unknown'),
            'judge_differs_from_author' => (bool) ($fixture['judge_differs_from_author'] ?? false),
            'case_count' => $caseCount,
            'query_policy' => 'hand_rewritten_queries_hash_only_output',
            'must_include_policy' => 'source_ref_hash',
            'recall_at_3' => round((float) $recallAt3, 4),
            'recall_at_5' => round((float) $recallAt5, 4),
            'r5' => round((float) $recallAt5, 4),
            'improper_floor_discards' => $improperFloorDiscards,
            'fd' => $improperFloorDiscards,
            'targets_available' => $targetsAvailable,
            'expected_source_available_declared' => $declaredAvailable,
            'source_availability_ratio' => round($sourceAvailabilityRatio, 4),
            'judged' => $judgeEvidence !== null,
            'judge_event_id' => $judgeEvidence['event_id'] ?? null,
            'judge_event_hash' => $judgeEvidence['event_hash'] ?? null,
            'judge_payload_hash' => $judgeEvidence['payload_hash'] ?? null,
            'provider_safe_violation_count' => $providerSafeViolations,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'checks' => $checks,
        ];
        if ($isV2OrLater) {
            $payload['cases'] = $caseCount;
            $payload['case_results'] = $cases;
            $payload['by_source_metrics_aggregate'] = LocalRagGoldenBySourceMetrics::aggregate(array_values(array_filter(array_map(
                static fn (array $case): array => is_array($case['by_source_metrics'] ?? null) ? $case['by_source_metrics'] : [],
                $cases,
            ))));
        } else {
            $payload['cases'] = $cases;
        }
        $payload['frozen_set_hash_algorithm'] = 'sha256';
        $payload['frozen_set_hash'] = $frozenSetHash;
        $payload['case_count'] = $caseCount;

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function missingMemoryRecallGoldenFixtureReport(string $version, string $frozenSetId): array
    {
        return [
            'schema_version' => 'atlas.memory_recall_golden_set.v1',
            'version' => $version,
            'status' => 'attention',
            'frozen_set_id' => $frozenSetId,
            'case_count' => 0,
            'cases' => [],
            'recall_at_3' => 0.0,
            'recall_at_5' => 0.0,
            'r5' => 0.0,
            'improper_floor_discards' => 0,
            'fd' => 0,
            'targets_available' => 0,
            'expected_source_available_declared' => 0,
            'judged' => false,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'missing_reason' => 'memory_recall_golden_fixture_missing_or_invalid',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadMemoryRecallGoldenFixtures(): array
    {
        $dir = base_path('tests/Fixtures/Context/memory_recall_golden');
        $paths = is_dir($dir) ? (glob($dir.'/v*.json') ?: []) : [];
        $fixtures = [];

        foreach ($paths as $path) {
            $version = pathinfo($path, PATHINFO_FILENAME);

            try {
                $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            if (is_array($fixture) && ($fixture['schema_version'] ?? null) === 'atlas.memory_recall_golden_set.v1') {
                $fixtures[$version] = $fixture;
            }
        }

        return $fixtures;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function evaluateMemoryRecallGoldenCase(array $case): array
    {
        $query = trim((string) ($case['query'] ?? ''));
        $mustInclude = collect((array) ($case['must_include'] ?? []))
            ->filter(fn (mixed $expected): bool => is_array($expected))
            ->map(fn (array $expected): array => [
                'source_ref_type' => (string) ($expected['source_ref_type'] ?? 'unknown'),
                'source_ref_hash' => (string) ($expected['source_ref_hash'] ?? ''),
            ])
            ->filter(fn (array $expected): bool => $expected['source_ref_hash'] !== '')
            ->values()
            ->all();
        $recall = $this->memoryRetrieval->recall($query, [], [], [
            'limit' => 5,
            'registry_limit' => 60,
            'verbatim_limit' => 0,
            'semantic_limit' => 0,
            'include_verbatim' => false,
            'include_semantic' => false,
            'include_compounding' => false,
            'record_usage' => false,
            'requester' => 'local_rag_golden_benchmark',
        ]);
        $items = collect((array) ($recall['recall'] ?? []));
        $top3 = $items->take(3);
        $top5 = $items->take(5);
        $top3Matched = $this->allExpectedRefsMatched($top3->all(), $mustInclude);
        $top5Matched = $this->allExpectedRefsMatched($top5->all(), $mustInclude);
        $availableExpected = $this->allExpectedRefsAvailable($mustInclude);
        $improperFloorDiscard = $availableExpected && ! $top5Matched && $top5->count() < 5;
        $providerSafeViolations = $items
            ->filter(fn (array $item): bool => data_get($item, 'audit_trail.provider_safe') !== true)
            ->count();

        $itemsWithRefHashes = $items
            ->map(fn (array $item): array => array_merge($item, [
                'ref_hashes' => $this->sourceRefHashes($item),
            ]))
            ->all();
        $bySourceMetrics = LocalRagGoldenBySourceMetrics::evaluateCase($itemsWithRefHashes, $mustInclude);

        return [
            'case_id' => (string) ($case['case_id'] ?? hash('sha256', $query)),
            'query_hash' => hash('sha256', $query),
            'surface' => 'memory_recall',
            'tags' => AtlasContextStringListNormalizer::uniqueTrimmedStrings((array) ($case['tags'] ?? [])),
            'must_include' => $mustInclude,
            'recall_count' => $items->count(),
            'recall_at_3_hit' => $top3Matched ? 1.0 : 0.0,
            'recall_at_5_hit' => $top5Matched ? 1.0 : 0.0,
            'expected_source_available' => $availableExpected,
            'improper_floor_discard_count' => $improperFloorDiscard ? 1 : 0,
            'provider_safe_violation_count' => $providerSafeViolations,
            'by_source_metrics' => $bySourceMetrics,
            'matched_ref_hashes' => $top5
                ->flatMap(fn (array $item): array => $this->sourceRefHashes($item))
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,string>>  $mustInclude
     */
    private function allExpectedRefsMatched(array $items, array $mustInclude): bool
    {
        if ($mustInclude === []) {
            return false;
        }

        foreach ($mustInclude as $expected) {
            $matched = collect($items)->contains(function (array $item) use ($expected): bool {
                if (($item['source_ref_type'] ?? null) !== $expected['source_ref_type']) {
                    return false;
                }

                return in_array($expected['source_ref_hash'], $this->sourceRefHashes($item), true);
            });

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,string>>  $mustInclude
     */
    private function allExpectedRefsAvailable(array $mustInclude): bool
    {
        if ($mustInclude === [] || ! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return false;
        }

        foreach ($mustInclude as $expected) {
            if ($expected['source_ref_type'] !== 'atlas_memory_entry') {
                return false;
            }

            $hash = $expected['source_ref_hash'];
            $exists = AtlasMemoryEntry::query()
                ->active()
                ->whereNull('archived_at')
                ->limit(200)
                ->get()
                ->contains(function (AtlasMemoryEntry $entry) use ($hash): bool {
                    return in_array($hash, array_filter([
                        hash('sha256', (string) $entry->id),
                        is_string($entry->source_id) && $entry->source_id !== '' ? hash('sha256', $entry->source_id) : null,
                        is_string($entry->content_hash) && $entry->content_hash !== '' ? $entry->content_hash : null,
                    ]), true);
                });

            if (! $exists) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<int,string>
     */
    private function sourceRefHashes(array $item): array
    {
        return array_values(array_unique(array_filter([
            hash('sha256', (string) ($item['source_ref_id'] ?? '')),
            is_string(data_get($item, 'lineage.origin_id')) && data_get($item, 'lineage.origin_id') !== ''
                ? hash('sha256', (string) data_get($item, 'lineage.origin_id'))
                : null,
            is_string(data_get($item, 'lineage.content_hash')) && data_get($item, 'lineage.content_hash') !== ''
                ? (string) data_get($item, 'lineage.content_hash')
                : null,
        ])));
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function evaluateMemoryRecallFixture(array $fixture): array
    {
        $recall = $this->memoryRetrieval->recall((string) $fixture['query'], (array) $fixture['context'], [], [
            'limit' => 5,
            'include_semantic' => false,
            'requester' => 'local_rag_benchmark',
        ]);
        $items = collect((array) ($recall['recall'] ?? []));
        $expectedType = (string) $fixture['source_ref_type'];
        $expectedId = (string) $fixture['source_ref_id'];
        $top3 = $items->take(3);
        $top5 = $items->take(5);
        $matchedInTop3 = $top3->contains(fn (array $item): bool => ($item['source_ref_type'] ?? null) === $expectedType
            && ($item['source_ref_id'] ?? null) === $expectedId);
        $matchedInTop5 = $top5->contains(fn (array $item): bool => ($item['source_ref_type'] ?? null) === $expectedType
            && ($item['source_ref_id'] ?? null) === $expectedId);
        $contamination = $items->filter(fn (array $item): bool => $this->containsUnsafeContext($item))->count();
        $providerSafeViolations = $items->filter(fn (array $item): bool => data_get($item, 'audit_trail.provider_safe') !== true)->count();
        $staleUse = $items->filter(fn (array $item): bool => ($item['freshness']['status'] ?? null) === 'stale_review_recommended')->count();
        $reasonCoverage = $items->isEmpty()
            ? 0.0
            : $items->filter(fn (array $item): bool => trim((string) ($item['reason'] ?? '')) !== '')->count() / $items->count();
        $budgetTruncation = $items->count() >= 5 && (int) data_get($recall, 'summary.budget_chars', 0) <= 0 ? 1 : 0;

        return [
            'id' => $fixture['id'],
            'status' => $matchedInTop3 && $contamination === 0 && $providerSafeViolations === 0 && $staleUse === 0 && $budgetTruncation === 0 && $reasonCoverage >= 1.0 ? 'passed' : 'failed',
            'expected_ref_type' => $expectedType,
            'expected_ref_hash' => hash('sha256', $expectedId),
            'query_hash' => hash('sha256', (string) $fixture['query']),
            'recall_count' => $items->count(),
            'precision_at_3' => $matchedInTop3 ? 1.0 : 0.0,
            'precision_at_5' => $matchedInTop5 ? 1.0 : 0.0,
            'missed_critical_context_count' => $matchedInTop5 ? 0 : 1,
            'context_contamination_count' => $contamination,
            'provider_safe_violation_count' => $providerSafeViolations,
            'stale_context_use_count' => $staleUse,
            'budget_truncation_count' => $budgetTruncation,
            'reason_coverage' => round($reasonCoverage, 4),
            'matched_ref_hashes' => $items
                ->take(5)
                ->map(fn (array $item): string => hash('sha256', (string) ($item['source_ref_id'] ?? '')))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryContext(AtlasMemoryEntry|AtlasVerbatimMemory $memory): array
    {
        return array_filter([
            'project_id' => $memory->project_id,
            'task_id' => $memory->task_id,
            'engineering_run_id' => $memory->engineering_run_id,
            'session_id' => $memory->session_id,
            'user_id' => $memory->user_id,
            'workspace' => $memory->scope_type === 'workspace' ? $memory->scope_id : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function containsUnsafeContext(array $item): bool
    {
        $text = json_encode([
            'title' => $item['title'] ?? null,
            'summary' => $item['summary'] ?? null,
            'excerpt' => $item['excerpt'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($text) && (bool) preg_match('/Bearer\s+[A-Za-z0-9._-]+|sk-[A-Za-z0-9._-]+|password\s*[:=]/i', $text);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyMemoryRecallCorpus(string $reason): array
    {
        return [
            'schema_version' => 'atlas.memory_recall_real_corpus.v1',
            'status' => 'attention',
            'evaluation_mode' => 'promoted_memory_hybrid_recall',
            'self_retrieval_sanity' => true,
            'case_count' => 0,
            'case_ids' => [],
            'golden_set' => $this->memoryRecallGoldenSet([]),
            'metrics' => [
                'precision_at_3' => 0.0,
                'precision_at_5' => 0.0,
                'missed_critical_context_count' => 0,
                'context_contamination_count' => 0,
                'provider_safe_violation_count' => 0,
                'stale_context_use_count' => 0,
                'budget_truncation_count' => 0,
                'reason_coverage' => 0.0,
            ],
            'checks' => [
                'minimum_case_count' => false,
                'precision_at_3_threshold' => false,
                'precision_at_5_threshold' => false,
                'no_missed_critical_context' => true,
                'no_context_contamination' => true,
                'no_provider_safe_violation' => true,
                'no_stale_context_use' => true,
                'budget_truncation_explained' => true,
                'reason_coverage_complete' => false,
            ],
            'cases' => [],
            'limits' => [
                'raw_query_persisted' => false,
                'raw_context_persisted' => false,
                'provider_safe_only' => true,
                'requires_promoted_registry_or_verbatim_memory' => true,
            ],
            'missing_reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $qualityCorpus
     * @param  array<string,mixed>  $memoryRecallCorpus
     * @return array<string,mixed>
     */
    private function retrievalRivalsPacket(array $qualityCorpus, array $memoryRecallCorpus): array
    {
        $memoryStatus = (string) ($memoryRecallCorpus['status'] ?? 'attention');
        $caseCount = (int) ($memoryRecallCorpus['case_count'] ?? 0);
        $precisionAt3 = (float) data_get($memoryRecallCorpus, 'metrics.precision_at_3', 0.0);
        $precisionAt5 = (float) data_get($memoryRecallCorpus, 'metrics.precision_at_5', 0.0);
        $providerSafeViolations = (int) data_get($memoryRecallCorpus, 'metrics.provider_safe_violation_count', 0);
        $qualityStatus = (string) ($qualityCorpus['status'] ?? 'attention');
        $checks = [
            'proposal_only' => true,
            'no_provider_execution' => true,
            'no_external_runtime_execution' => true,
            'no_policy_patch' => true,
            'provider_safe_metrics_only' => $providerSafeViolations === 0,
            'current_strategy_measured' => $caseCount > 0 && $memoryStatus === 'passed',
            'alternatives_not_promoted' => true,
        ];

        return [
            'schema_version' => 'atlas.retrieval_rivals.packet.v1',
            'status' => collect($checks)->every(fn (bool $passed): bool => $passed) ? 'passed' : 'proposal_only_attention',
            'mode' => 'proposal_only_no_runtime_execution',
            'baseline_strategy_id' => 'current_governed_hybrid_memory_recall',
            'quality_corpus_status' => $qualityStatus,
            'memory_recall_status' => $memoryStatus,
            'case_count' => $caseCount,
            'raw_query_persisted' => false,
            'raw_context_persisted' => false,
            'comparison' => [
                'winner' => $caseCount > 0 && $memoryStatus === 'passed'
                    ? 'current_governed_hybrid_memory_recall'
                    : 'no_measured_winner_yet',
                'delta_measured' => false,
                'reason' => 'Only the current governed hybrid recall path is measured. Alternative retrieval strategies remain proposals until a human-reviewed AP and runtime invocation contract exist.',
                'current_metrics' => [
                    'precision_at_3' => round($precisionAt3, 4),
                    'precision_at_5' => round($precisionAt5, 4),
                    'provider_safe_violation_count' => $providerSafeViolations,
                    'case_count' => $caseCount,
                ],
            ],
            'strategies' => [
                [
                    'id' => 'current_governed_hybrid_memory_recall',
                    'status' => $memoryStatus === 'passed' ? 'measured_passed' : 'measured_attention',
                    'runtime_family' => 'laravel_kernel',
                    'promotion_allowed' => true,
                    'provider_bypass_allowed' => false,
                    'raw_text_allowed' => false,
                    'evidence' => [
                        'memory_recall_corpus',
                        'memory_recall_golden_set_hashes',
                        'retrieval_benchmark_history_when_recorded',
                    ],
                ],
                [
                    'id' => 'lexical_keyword_fallback_candidate',
                    'status' => 'proposal_only_not_executed',
                    'runtime_family' => 'laravel_kernel',
                    'promotion_allowed' => false,
                    'provider_bypass_allowed' => false,
                    'raw_text_allowed' => false,
                    'required_before_execution' => [
                        'deterministic_case_contract',
                        'human_reviewed_policy_patch',
                        'evidence_ledger_event_contract',
                    ],
                ],
                [
                    'id' => 'future_graph_rag_python_candidate',
                    'status' => 'blocked_until_human_review_and_future_ap',
                    'runtime_family' => 'python_ai_data',
                    'promotion_allowed' => false,
                    'provider_bypass_allowed' => false,
                    'raw_text_allowed' => false,
                    'required_before_execution' => [
                        'docs/ap/AP-683-local-rag-graph-promotion-review.md',
                        'decision_receipt_hash',
                        'runtime_invocation_contract',
                        'rollback_plan',
                    ],
                ],
            ],
            'checks' => $checks,
            'forbidden_outputs' => [
                'provider_call',
                'python_runtime_execution',
                'graph_rag_auto_enable',
                'policy_patch_auto_apply',
                'raw_query_or_context_persistence',
                'parallel_memory_store',
            ],
            'next_action' => $caseCount > 0
                ? 'open_human_review_before_executing_any_retrieval_rival_strategy'
                : 'seed_provider_safe_memory_recall_corpus_before_rivals_comparison',
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
     * @return array<string,mixed>
     */
    private function futureGraphRuntimeInvocationContract(): array
    {
        return [
            ...$this->runtimeBoundary->invocationContract(),
            'selected_runtime_family' => 'python_ai_data',
            'runtime_id' => 'graph_rag_candidate',
            'capability_id' => 'local_rag.graph_promotion_candidate',
            'evidence_rule' => 'future_graph_rag_runtime_must_return_evidence_refs_only_and_wait_for_human_reviewed_curator_ap',
            'promotion_allowed_now' => false,
            'auto_enable_allowed_now' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerEventPayload(?AtlasLedgerEvent $event): array
    {
        return [
            'recorded' => $event !== null,
            'event_id' => $event?->event_id,
            'event_type' => $event?->event_type,
            'payload_hash' => $event?->payload_hash,
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
