<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Support;

use App\Services\Ai\Context\LocalRagBenchmarkService as Host;
use App\Services\Ai\Context\Support\LocalRagBenchmarkSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Local RAG benchmark projectors — no I/O, no host DI, no clock.
 *
 * Explicit path proof: host imports Support and no longer declares the peeled
 * private pure helpers (fixtures / scorers / packets / status / percentile).
 */
final class LocalRagBenchmarkSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Context/Support/LocalRagBenchmarkSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Context/LocalRagBenchmarkService.php';

    /** @var list<string> */
    private const PEELED = [
        'cases',
        'evaluateCaseFromPlan',
        'qualityCorpusReport',
        'promotionReviewPacket',
        'externalRetrievalPromotionPreflight',
        'legacyMemoryRecallGoldenReport',
        'memoryRecallGoldenStatus',
        'nonEmptyMemoryRecallFixtures',
        'memoryRecallGoldenSet',
        'missingMemoryRecallGoldenFixtureReport',
        'allExpectedRefsMatched',
        'sourceRefHashes',
        'scoreMemoryRecallFixture',
        'memoryRecallCorpusFromCases',
        'containsUnsafeContext',
        'emptyMemoryRecallCorpus',
        'retrievalRivalsPacket',
        'ledgerContract',
        'percentile',
    ];

    /** Host residual private helpers that must stay (I/O or thin orchestrators). */
    /** @var list<string> */
    private const HOST_RESIDUAL = [
        'evaluateCase',
        'memoryRecallCorpusReport',
        'memoryRecallFixtures',
        'promotedMemoryRecallFixtures',
        'governedProviderSafeMemoryRecallFixtures',
        'memoryRecallGoldenFixtureReports',
        'memoryRecallGoldenFixtureReport',
        'loadMemoryRecallGoldenFixtures',
        'evaluateMemoryRecallGoldenCase',
        'allExpectedRefsAvailable',
        'evaluateMemoryRecallFixture',
        'memoryContext',
        'futureGraphRuntimeInvocationContract',
        'ledgerEventPayload',
        'applyGoldenJudgeEvidence',
        'goldenJudgeEvidence',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Context\Support\LocalRagBenchmarkSupport;',
            $hostSrc,
            'Host must import LocalRagBenchmarkSupport',
        );

        foreach ([
            'LocalRagBenchmarkSupport::cases',
            'LocalRagBenchmarkSupport::qualityCorpusReport',
            'LocalRagBenchmarkSupport::evaluateCaseFromPlan',
            'LocalRagBenchmarkSupport::retrievalRivalsPacket',
            'LocalRagBenchmarkSupport::ledgerContract',
            'LocalRagBenchmarkSupport::externalRetrievalPromotionPreflight',
            'LocalRagBenchmarkSupport::promotionReviewPacket',
            'LocalRagBenchmarkSupport::emptyMemoryRecallCorpus',
            'LocalRagBenchmarkSupport::memoryRecallCorpusFromCases',
            'LocalRagBenchmarkSupport::scoreMemoryRecallFixture',
            'LocalRagBenchmarkSupport::allExpectedRefsMatched',
            'LocalRagBenchmarkSupport::sourceRefHashes',
            'LocalRagBenchmarkSupport::memoryRecallGoldenStatus',
            'LocalRagBenchmarkSupport::legacyMemoryRecallGoldenReport',
            'LocalRagBenchmarkSupport::nonEmptyMemoryRecallFixtures',
            'LocalRagBenchmarkSupport::missingMemoryRecallGoldenFixtureReport',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(Host::class);
        foreach (self::PEELED as $method) {
            // evaluateCaseFromPlan / scoreMemoryRecallFixture / memoryRecallCorpusFromCases are Support-only names
            $this->assertFalse(
                $host->hasMethod($method),
                "Host must no longer declare private {$method} after peel",
            );
        }

        foreach (self::HOST_RESIDUAL as $method) {
            $this->assertTrue($host->hasMethod($method), "Host residual I/O/orchestrator {$method} must remain");
        }

        $this->assertTrue($host->hasMethod('report'));
        $this->assertTrue($host->hasMethod('recordEvidence'));
    }

    #[Test]
    public function cases_fixture_covers_four_domain_families_with_expected_sources(): void
    {
        $cases = Support::cases();
        $this->assertCount(4, $cases);
        $families = array_column($cases, 'domain_family');
        sort($families);
        $this->assertSame(
            ['architecture', 'finance', 'personal_development', 'programming'],
            $families,
        );

        $byId = collect($cases)->keyBy('id');
        $this->assertFalse($byId['programming_patch_context']['expected_graph']);
        $this->assertTrue($byId['architecture_relation_context']['expected_graph']);
        $this->assertContains('graph_retrieval', $byId['finance_review_context']['expected_sources']);
    }

    #[Test]
    public function evaluate_case_from_plan_scores_checks_purely(): void
    {
        $case = Support::cases()[0];
        $passingPlan = [
            'mode' => 'local',
            'selected_sources' => [
                ['type' => 'vector_retrieval', 'provider_bypass_allowed' => false],
                ['type' => 'memory_signals', 'provider_bypass_allowed' => false],
                ['type' => 'code_intelligence', 'provider_bypass_allowed' => false],
                ['type' => 'evidence_replay', 'provider_bypass_allowed' => false],
            ],
            'readiness' => [
                'status' => 'ready',
                'required_unavailable_sources' => [],
                'unavailable_selected_sources' => [],
            ],
            'policy' => [
                'provider_bypass_allowed' => false,
                'do_not_create_parallel_memory' => true,
            ],
        ];

        $passed = Support::evaluateCaseFromPlan($case, $passingPlan, 12.34567);
        $this->assertSame('passed', $passed['status']);
        $this->assertSame(1.0, $passed['score']);
        $this->assertSame(12.3457, $passed['latency_ms']);
        $this->assertTrue($passed['checks']['expected_sources_selected']);
        $this->assertTrue($passed['checks']['provider_bypass_blocked']);

        $failingPlan = $passingPlan;
        $failingPlan['selected_sources'] = [
            ['type' => 'vector_retrieval', 'provider_bypass_allowed' => true],
        ];
        $failingPlan['policy']['provider_bypass_allowed'] = true;
        $failed = Support::evaluateCaseFromPlan($case, $failingPlan, 1.0);
        $this->assertSame('failed', $failed['status']);
        $this->assertLessThan(1.0, $failed['score']);
        $this->assertNotEmpty($failed['missing_expected_sources']);
        $this->assertFalse($failed['checks']['provider_bypass_blocked']);
    }

    #[Test]
    public function quality_corpus_report_requires_coverage_score_and_latency(): void
    {
        $cases = array_map(
            fn (array $case): array => Support::evaluateCaseFromPlan($case, $this->passingPlanFor($case), 10.0),
            Support::cases(),
        );

        $passed = Support::qualityCorpusReport($cases, true);
        $this->assertSame('passed', $passed['status']);
        $this->assertTrue($passed['checks']['domain_coverage']);
        $this->assertTrue($passed['checks']['latency_p95_measured']);
        $this->assertSame('local_rag_controlled_router_quality_v1', $passed['corpus_id']);

        $attention = Support::qualityCorpusReport($cases, false);
        $this->assertSame('attention', $attention['status']);
        $this->assertFalse($attention['checks']['readiness_not_blocked']);
    }

    #[Test]
    public function promotion_packets_and_ledger_contract_are_proposal_only(): void
    {
        $quality = ['status' => 'passed', 'corpus_id' => 'local_rag_controlled_router_quality_v1'];
        $memory = [
            'status' => 'attention',
            'case_count' => 0,
            'golden_set' => ['schema_version' => 'atlas.memory_recall_golden_set.v1', 'cases' => []],
            'metrics' => [
                'precision_at_3' => 0.0,
                'precision_at_5' => 0.0,
                'provider_safe_violation_count' => 0,
            ],
        ];

        $review = Support::promotionReviewPacket($quality);
        $this->assertSame('blocked_until_human_review_and_future_ap', $review['status']);
        $this->assertSame('passed', $review['quality_corpus_status']);
        $this->assertContains('LOCAL_RAG_GRAPH_PROMOTION_BLOCKED', $review['evidence_required']);

        $preflight = Support::externalRetrievalPromotionPreflight($quality, $memory);
        $this->assertSame('atlas.external_vector_rag.promotion_preflight.v1', $preflight['schema_version']);
        $this->assertTrue($preflight['proposal_only']);
        $this->assertFalse($preflight['execution_gate']['runtime_execution_allowed']);
        $this->assertNotEmpty($preflight['preflight_hash']);
        $this->assertSame(64, strlen($preflight['preflight_hash']));

        $ledger = Support::ledgerContract();
        $this->assertSame('LOCAL_RAG_*', $ledger['event_family']);
        $this->assertContains('LOCAL_RAG_PLAN_CREATED', $ledger['event_types']);
        $this->assertFalse($ledger['payload_contract']['raw_query_persistence_allowed']);

        $rivals = Support::retrievalRivalsPacket($quality, $memory);
        $this->assertSame('proposal_only_attention', $rivals['status']);
        $this->assertSame('no_measured_winner_yet', $rivals['comparison']['winner']);
        $this->assertCount(3, $rivals['strategies']);
    }

    #[Test]
    public function memory_recall_golden_status_and_legacy_surface_strip_v2_keys(): void
    {
        $this->assertSame('passed', Support::memoryRecallGoldenStatus([
            'a' => true,
            'b' => true,
        ], false));
        $this->assertSame('attention', Support::memoryRecallGoldenStatus([
            'a' => true,
            'b' => false,
        ], false));
        $this->assertSame(
            'pending_window:live_recall_corpus_insufficient',
            Support::memoryRecallGoldenStatus([
                'source_availability_floor' => false,
                'other' => true,
            ], true),
        );

        $legacy = Support::legacyMemoryRecallGoldenReport([
            'status' => 'passed',
            'version' => 'v2',
            'r5' => 0.9,
            'judged' => true,
            'case_results' => [['x' => 1]],
            'frozen_set_hash' => 'abc',
        ]);
        $this->assertArrayNotHasKey('version', $legacy);
        $this->assertArrayNotHasKey('r5', $legacy);
        $this->assertArrayNotHasKey('judged', $legacy);
        $this->assertArrayNotHasKey('case_results', $legacy);
        $this->assertSame('passed', $legacy['status']);
        $this->assertSame('abc', $legacy['frozen_set_hash']);
    }

    #[Test]
    public function memory_recall_fixture_scoring_and_corpus_aggregation_are_pure(): void
    {
        $fixture = [
            'id' => 'registry:abc',
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_id' => 'entry-1',
            'query' => 'provider safe memory title',
            'source' => 'memory_registry_promoted',
        ];
        $safeItem = [
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_id' => 'entry-1',
            'title' => 'safe',
            'summary' => 'ok',
            'reason' => 'registry match',
            'audit_trail' => ['provider_safe' => true],
            'freshness' => ['status' => 'fresh'],
        ];
        $scored = Support::scoreMemoryRecallFixture($fixture, [$safeItem], ['summary' => ['budget_chars' => 1200]]);
        $this->assertSame('passed', $scored['status']);
        $this->assertSame(1.0, $scored['precision_at_3']);
        $this->assertSame(0, $scored['context_contamination_count']);

        $unsafe = $safeItem;
        $unsafe['excerpt'] = 'password: hunter2';
        $contaminated = Support::scoreMemoryRecallFixture($fixture, [$unsafe], ['summary' => ['budget_chars' => 1200]]);
        $this->assertSame('failed', $contaminated['status']);
        $this->assertSame(1, $contaminated['context_contamination_count']);
        $this->assertTrue(Support::containsUnsafeContext($unsafe));
        $this->assertFalse(Support::containsUnsafeContext($safeItem));

        $corpus = Support::memoryRecallCorpusFromCases([$scored, $scored], [$fixture, $fixture]);
        $this->assertSame('passed', $corpus['status']);
        $this->assertSame(2, $corpus['case_count']);
        $this->assertTrue($corpus['checks']['precision_at_3_threshold']);

        $empty = Support::emptyMemoryRecallCorpus('memory_tables_missing');
        $this->assertSame('attention', $empty['status']);
        $this->assertSame('memory_tables_missing', $empty['missing_reason']);
        $this->assertSame(0, $empty['case_count']);

        $filtered = Support::nonEmptyMemoryRecallFixtures([
            ['query' => '  '],
            ['query' => 'real'],
        ]);
        $this->assertCount(1, $filtered);

        $golden = Support::memoryRecallGoldenSet([$fixture]);
        $this->assertSame(1, $golden['case_count']);
        $this->assertSame('promoted_provider_safe_registry_or_verbatim_memory_latest_first', $golden['selection_rule']);
        $this->assertSame(64, strlen($golden['cases'][0]['must_include'][0]['source_ref_hash']));

        $missing = Support::missingMemoryRecallGoldenFixtureReport('v1', 'seed-id');
        $this->assertSame('attention', $missing['status']);
        $this->assertSame('seed-id', $missing['frozen_set_id']);
    }

    #[Test]
    public function source_ref_hashes_and_expected_ref_matching_are_deterministic(): void
    {
        $item = [
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_id' => 'id-1',
            'lineage' => [
                'origin_id' => 'origin-1',
                'content_hash' => 'content-hash-abc',
            ],
        ];
        $hashes = Support::sourceRefHashes($item);
        $this->assertContains(hash('sha256', 'id-1'), $hashes);
        $this->assertContains(hash('sha256', 'origin-1'), $hashes);
        $this->assertContains('content-hash-abc', $hashes);

        $must = [[
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_hash' => hash('sha256', 'id-1'),
        ]];
        $this->assertTrue(Support::allExpectedRefsMatched([$item], $must));
        $this->assertFalse(Support::allExpectedRefsMatched([], $must));
        $this->assertFalse(Support::allExpectedRefsMatched([$item], []));
        $this->assertFalse(Support::allExpectedRefsMatched([$item], [[
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_hash' => hash('sha256', 'missing'),
        ]]));
    }

    #[Test]
    public function percentile_is_interpolated_and_null_on_empty(): void
    {
        $this->assertNull(Support::percentile([], 95));
        $this->assertSame(10.0, Support::percentile([10.0], 95));
        $p95 = Support::percentile([1.0, 2.0, 3.0, 4.0, 5.0], 95);
        $this->assertNotNull($p95);
        $this->assertGreaterThan(4.0, $p95);
        $this->assertLessThanOrEqual(5.0, $p95);
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function passingPlanFor(array $case): array
    {
        $sources = array_map(
            fn (string $type): array => [
                'type' => $type,
                'provider_bypass_allowed' => false,
                'status' => $type === 'graph_retrieval' ? 'future_governed' : 'available',
                'runtime' => $type === 'graph_retrieval' ? 'python_ai_data_candidate' : 'laravel',
                'required' => false,
            ],
            (array) $case['expected_sources'],
        );

        return [
            'mode' => 'local',
            'selected_sources' => $sources,
            'readiness' => [
                'status' => 'ready',
                'required_unavailable_sources' => [],
                'unavailable_selected_sources' => [],
            ],
            'policy' => [
                'provider_bypass_allowed' => false,
                'do_not_create_parallel_memory' => true,
            ],
        ];
    }
}
