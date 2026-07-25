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
use App\Services\Ai\Context\Support\LocalRagBenchmarkSupport;
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
        $cases = collect(LocalRagBenchmarkSupport::cases())
            ->map(fn (array $case): array => $this->evaluateCase($case))
            ->values()
            ->all();

        $failed = collect($cases)->filter(fn (array $case): bool => $case['status'] !== 'passed')->values();
        $averageScore = collect($cases)->avg('score');
        $readinessReady = $readiness['status'] !== 'blocked';
        $qualityCorpus = LocalRagBenchmarkSupport::qualityCorpusReport($cases, $readinessReady);
        $memoryRecallCorpus = $this->memoryRecallCorpusReport();
        $memoryRecallGoldenVersions = $this->memoryRecallGoldenFixtureReports();
        $memoryRecallGolden = LocalRagBenchmarkSupport::legacyMemoryRecallGoldenReport($memoryRecallGoldenVersions['v1']);
        // R8: the HONEST independent retrieval-precision number. Unlike the
        // memory-recall corpus above (whose query is the target's own
        // title+summary — a near-tautological known-item lexical test), this runs
        // paraphrased/conceptual queries that are INDEPENDENT of the target text
        // through the REAL semantic engine and reports real precision@k/recall@k,
        // or an honest `attention`/unmeasured when the engine/corpus is absent.
        $independentPrecisionCorpus = $this->precisionCorpus->report();
        $retrievalRivalsPacket = LocalRagBenchmarkSupport::retrievalRivalsPacket($qualityCorpus, $memoryRecallCorpus);
        $ledgerContract = LocalRagBenchmarkSupport::ledgerContract();
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
                'external_vector_rag_preflight_contract' => LocalRagBenchmarkSupport::externalRetrievalPromotionPreflight($qualityCorpus, $memoryRecallCorpus),
                'review_packet' => LocalRagBenchmarkSupport::promotionReviewPacket($qualityCorpus),
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
            $report['memory_recall_golden'] = LocalRagBenchmarkSupport::legacyMemoryRecallGoldenReport($versions['v1']);
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
        $golden['status'] = LocalRagBenchmarkSupport::memoryRecallGoldenStatus((array) ($golden['checks'] ?? []), true);

        return $golden;
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

        return LocalRagBenchmarkSupport::evaluateCaseFromPlan($case, $plan, $latencyMs);
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryRecallCorpusReport(): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries') && ! DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            return LocalRagBenchmarkSupport::emptyMemoryRecallCorpus('memory_tables_missing');
        }

        $fixtures = $this->memoryRecallFixtures();
        if ($fixtures === []) {
            return LocalRagBenchmarkSupport::emptyMemoryRecallCorpus('no_governed_provider_safe_memory');
        }

        $cases = collect($fixtures)
            ->map(fn (array $fixture): array => $this->evaluateMemoryRecallFixture($fixture))
            ->values()
            ->all();

        return LocalRagBenchmarkSupport::memoryRecallCorpusFromCases($cases, $fixtures);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function memoryRecallFixtures(): array
    {
        $promoted = LocalRagBenchmarkSupport::nonEmptyMemoryRecallFixtures($this->promotedMemoryRecallFixtures());

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
            ...LocalRagBenchmarkSupport::nonEmptyMemoryRecallFixtures($this->governedProviderSafeMemoryRecallFixtures()),
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
            $reports['v1'] = LocalRagBenchmarkSupport::missingMemoryRecallGoldenFixtureReport('v1', 'memory_recall_golden_2026_07_rag05_seed');
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
            'status' => LocalRagBenchmarkSupport::memoryRecallGoldenStatus($checks, $isV2OrLater),
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
        $top3Matched = LocalRagBenchmarkSupport::allExpectedRefsMatched($top3->all(), $mustInclude);
        $top5Matched = LocalRagBenchmarkSupport::allExpectedRefsMatched($top5->all(), $mustInclude);
        $availableExpected = $this->allExpectedRefsAvailable($mustInclude);
        $improperFloorDiscard = $availableExpected && ! $top5Matched && $top5->count() < 5;
        $providerSafeViolations = $items
            ->filter(fn (array $item): bool => data_get($item, 'audit_trail.provider_safe') !== true)
            ->count();

        $itemsWithRefHashes = $items
            ->map(fn (array $item): array => array_merge($item, [
                'ref_hashes' => LocalRagBenchmarkSupport::sourceRefHashes($item),
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
                ->flatMap(fn (array $item): array => LocalRagBenchmarkSupport::sourceRefHashes($item))
                ->unique()
                ->values()
                ->all(),
        ];
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
        $items = (array) ($recall['recall'] ?? []);

        return LocalRagBenchmarkSupport::scoreMemoryRecallFixture($fixture, $items, $recall);
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

}
