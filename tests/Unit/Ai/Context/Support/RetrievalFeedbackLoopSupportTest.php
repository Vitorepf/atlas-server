<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Support;

use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService as Host;
use App\Services\Ai\Context\Support\RetrievalFeedbackLoopSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for retrieval feedback loop projectors — no I/O, no host DI, no clock.
 *
 * Explicit path proof: host imports Support and no longer declares the peeled
 * private pure helpers (miss/noise/ROI/attribution/policy/ref-normalize).
 */
final class RetrievalFeedbackLoopSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Context/Support/RetrievalFeedbackLoopSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php';

    /** @var list<string> */
    private const PEELED = [
        'missedRefCandidates',
        'noiseRefCandidates',
        'contextRoi',
        'feedbackEvent',
        'learningCandidate',
        'sourceUtility',
        'explicitUsedUtilityKeys',
        'compoundingMemoryDeliveredRefs',
        'itemStringColumn',
        'failureReason',
        'utilityFormulaVersion',
        'nextRetrievalHint',
        'status',
        'flowId',
        'outcomeStatus',
        'attributionQuality',
        'deliveredLedgerNoiseRefCandidates',
        'usedContextRefKeys',
        'hasExplicitUseSignal',
        'matchingDeliveredKey',
        'noiseCandidateFromContextEntry',
        'contextRefAttribution',
        'nextContextPolicy',
        'appliedPolicySnapshot',
        'selectedContextRefEntry',
        'candidateContextRefEntry',
        'contextRefEntriesFromRefs',
        'inputContextRefEntries',
        'contextRefEntry',
        'providerSafeFeedbackEvent',
        'providerSafeContextRef',
        'inferredSourceType',
        'publicContextRefs',
        'contextRefLabels',
        'deferSections',
        'scalarStringList',
    ];

    /** Host residual private helpers that must stay (I/O or DI orchestrators). */
    /** @var list<string> */
    private const HOST_RESIDUAL = [
        'persistFeedback',
        'deliveredPackLedger',
        'resolvePriorMisses',
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
            'use App\Services\Ai\Context\Support\RetrievalFeedbackLoopSupport;',
            $hostSrc,
            'Host must import RetrievalFeedbackLoopSupport',
        );

        foreach ([
            'RetrievalFeedbackLoopSupport::outcomeStatus',
            'RetrievalFeedbackLoopSupport::attributionQuality',
            'RetrievalFeedbackLoopSupport::missedRefCandidates',
            'RetrievalFeedbackLoopSupport::noiseRefCandidates',
            'RetrievalFeedbackLoopSupport::deliveredLedgerNoiseRefCandidates',
            'RetrievalFeedbackLoopSupport::contextRefAttribution',
            'RetrievalFeedbackLoopSupport::contextRoi',
            'RetrievalFeedbackLoopSupport::nextContextPolicy',
            'RetrievalFeedbackLoopSupport::feedbackEvent',
            'RetrievalFeedbackLoopSupport::learningCandidate',
            'RetrievalFeedbackLoopSupport::status',
            'RetrievalFeedbackLoopSupport::providerSafeFeedbackEvent',
            'RetrievalFeedbackLoopSupport::nextRetrievalHint',
            'RetrievalFeedbackLoopSupport::scalarStringList',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $this->assertStringContainsString(
            "config('atlas.aobg.repromote_specific_refs_enabled', false)",
            $hostSrc,
            'Host injects config into pure nextContextPolicy (Support stays config-free)',
        );
        $this->assertStringContainsString(
            '$persisted?->feedback_hash',
            $hostSrc,
            'Host maps Eloquent model to pure learningCandidate hash arg',
        );

        $supportSrc = (string) file_get_contents($supportAbs);
        $this->assertDoesNotMatchRegularExpression(
            "/(?<!\\w)config\\s*\\(\\s*['\\\"]/",
            $supportSrc,
            'Support must not call config(\'...\') (purity); host injects flags',
        );
        $this->assertStringNotContainsString('Carbon::', $supportSrc);
        $this->assertStringNotContainsString('AiRagFeedbackEvent', $supportSrc);
        $this->assertStringNotContainsString('DatabaseTableAvailability', $supportSrc);
        $this->assertStringNotContainsString('AtlasDeliveredPackLedger', $supportSrc);

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(Host::class);
        foreach (self::PEELED as $method) {
            $this->assertFalse(
                $host->hasMethod($method),
                "Host must no longer declare private {$method} after peel",
            );
        }

        foreach (self::HOST_RESIDUAL as $method) {
            $this->assertTrue($host->hasMethod($method), "Host residual I/O/orchestrator {$method} must remain");
        }

        $this->assertTrue($host->hasMethod('capture'));
    }

    #[Test]
    public function outcome_status_and_attribution_quality_normalize_aliases(): void
    {
        $this->assertSame('passed', Support::outcomeStatus('success'));
        $this->assertSame('passed', Support::outcomeStatus('ok'));
        $this->assertSame('partial', Support::outcomeStatus('needs_review'));
        $this->assertSame('failed', Support::outcomeStatus('blocked'));
        $this->assertSame('unknown', Support::outcomeStatus('weird'));

        $this->assertSame('cited', Support::attributionQuality('cited'));
        $this->assertSame('low', Support::attributionQuality('bogus'));
    }

    #[Test]
    public function flow_id_prefers_explicit_then_domain_task(): void
    {
        $this->assertSame('developer.debug', Support::flowId([
            'flow_id' => 'developer.debug',
            'domain' => 'other',
            'task_type' => 'x',
        ]));
        $this->assertSame('atlas.direct', Support::flowId([]));
        $this->assertSame('finance.review', Support::flowId([
            'domain' => 'finance',
            'task_type' => 'review',
        ]));
    }

    #[Test]
    public function missed_ref_candidates_merge_coverage_and_explicit_unique_by_source_reason(): void
    {
        $missed = Support::missedRefCandidates(
            ['code_intelligence' => false, 'memory_signals' => true, 'evidence_replay' => false],
            [
                'tests',
                ['source_type' => 'code_intelligence', 'reason' => 'required_source_not_covered', 'confidence' => 0.5],
                ['source_type' => 'docs', 'reason' => 'operator_gap', 'confidence' => 1.5],
                '',
            ],
        );

        $pairs = array_map(
            static fn (array $item): string => $item['source_type'].'|'.$item['reason'],
            $missed,
        );
        $this->assertContains('code_intelligence|required_source_not_covered', $pairs);
        $this->assertContains('evidence_replay|required_source_not_covered', $pairs);
        $this->assertContains('tests|operator_or_outcome_reported_miss', $pairs);
        $this->assertContains('docs|operator_gap', $pairs);
        $this->assertSame(1, count(array_filter($pairs, static fn (string $p): bool => $p === 'code_intelligence|required_source_not_covered')));

        $docs = collect($missed)->firstWhere('source_type', 'docs');
        $this->assertSame(1.0, $docs['confidence']);
        $this->assertSame(Host::MISSED_REF_SCHEMA, $missed[0]['schema_version']);
    }

    #[Test]
    public function noise_ref_candidates_flag_explicit_stale_and_low_score_on_failure(): void
    {
        $selected = [
            ['source_ref_hash' => 'aaa', 'candidate_hash' => 'c1', 'source_type' => 'doc', 'context_score' => 0.9, 'status' => 'fresh'],
            ['source_ref_hash' => 'bbb', 'candidate_hash' => 'c2', 'source_type' => 'test', 'context_score' => 0.2, 'status' => 'fresh'],
            ['source_ref_hash' => 'ccc', 'candidate_hash' => 'c3', 'source_type' => 'graph', 'context_score' => 0.8, 'status' => 'stale_or_unknown'],
        ];

        $passed = Support::noiseRefCandidates($selected, ['aaa'], [], 'passed');
        $reasons = array_column($passed, 'reason');
        $this->assertContains('explicit_noise_signal', $reasons);
        $this->assertContains('stale_context', $reasons);
        $this->assertNotContains('low_score_in_failed_outcome', $reasons);

        $failed = Support::noiseRefCandidates($selected, [], [], 'failed');
        $failedReasons = array_column($failed, 'reason');
        $this->assertContains('low_score_in_failed_outcome', $failedReasons);
        $this->assertContains('stale_context', $failedReasons);
    }

    #[Test]
    public function context_roi_stays_unmeasured_without_explicit_utility_and_scores_when_measured(): void
    {
        $unmeasured = Support::contextRoi(
            [['x' => 1], ['x' => 2]],
            1,
            0,
            0,
            ['status' => 'ready'],
            'passed',
            [],
            2,
            false,
        );
        $this->assertFalse($unmeasured['measured']);
        $this->assertNull($unmeasured['roi_score']);
        $this->assertSame('unmeasured', $unmeasured['quality_band']);
        $this->assertSame(Host::CONTEXT_ROI_SCHEMA, $unmeasured['schema_version']);

        $measured = Support::contextRoi(
            [],
            4,
            0,
            0,
            ['status' => 'ready'],
            'passed',
            ['post_execution_utility' => 100],
            4,
            true,
        );
        $this->assertTrue($measured['measured']);
        $this->assertSame(100, $measured['post_execution_utility']);
        $this->assertSame(1.0, $measured['use_ratio']);
        $this->assertSame('strong', $measured['quality_band']);
        $this->assertSame(Host::EXPLICIT_UTILITY_FORMULA_VERSION, $measured['formula_version']);
    }

    #[Test]
    public function next_context_policy_actions_and_budget_multiplier_are_pure(): void
    {
        $keep = Support::nextContextPolicy([
            'missing_source_types' => [],
            'noise_refs' => [],
            'unused_refs' => [],
            'waste_ratio' => 0.0,
            'measured' => false,
        ], ['measured' => false, 'roi_score' => null]);
        $this->assertSame(['keep_current_pack'], $keep['actions']);
        $this->assertSame(1.0, $keep['next_initial_budget_multiplier']);
        $this->assertFalse($keep['specific_ref_repromotion_enabled']);
        $this->assertFalse($keep['requires_review']);

        $busy = Support::nextContextPolicy([
            'missing_source_types' => ['tests'],
            'noise_refs' => [['ref' => 'tests/Feature/FooTest.php', 'source_type' => 'test']],
            'unused_refs' => [['ref' => 'docs/readme.md', 'source_type' => 'doc']],
            'waste_ratio' => 0.55,
            'measured' => true,
        ], [
            'measured' => true,
            'roi_score' => 0.2,
        ], true);

        $this->assertContains('expand_missing_source_types', $busy['actions']);
        $this->assertContains('demote_noise_context_refs', $busy['actions']);
        $this->assertContains('shrink_initial_context', $busy['actions']);
        $this->assertContains('review_context_pack', $busy['actions']);
        $this->assertSame(0.85, $busy['next_initial_budget_multiplier']);
        $this->assertTrue($busy['specific_ref_repromotion_enabled']);
        $this->assertContains('tests', $busy['defer_sections']);
        $this->assertContains('docs', $busy['defer_sections']);
        $this->assertSame(Host::NEXT_CONTEXT_POLICY_SCHEMA, $busy['schema_version']);
    }

    #[Test]
    public function context_ref_attribution_uses_explicit_used_signals_and_provider_safe_refs(): void
    {
        $selected = [
            [
                'source_ref_hash' => 'hash_used_1',
                'candidate_hash' => 'cand1',
                'source_type' => 'code_intelligence',
                'status' => 'fresh',
            ],
            [
                'source_ref_hash' => 'hash_noise_1',
                'candidate_hash' => 'cand2',
                'source_type' => 'doc',
                'status' => 'fresh',
            ],
        ];
        $noise = [[
            'source_ref_hash' => 'hash_noise_1',
            'candidate_hash' => 'cand2',
            'source_type' => 'doc',
            'reason' => 'explicit_noise_signal',
        ]];
        $attribution = Support::contextRefAttribution(
            $selected,
            [['source_type' => 'tests', 'reason' => 'x']],
            $noise,
            'partial',
            [
                'used_context_refs' => ['hash_used_1'],
            ],
            ['hit' => false, 'delivered_refs' => []],
        );

        $this->assertTrue($attribution['measured']);
        $this->assertSame('explicit_used_refs', $attribution['usage_basis']);
        $this->assertSame(1, $attribution['used_count']);
        $this->assertSame(1, $attribution['noise_count']);
        $this->assertSame(['tests'], $attribution['missing_source_types']);
        $this->assertSame(Host::CONTEXT_REF_ATTRIBUTION_SCHEMA, $attribution['schema_version']);
        $this->assertFalse($attribution['source_policy']['raw_text_exposed']);
    }

    #[Test]
    public function status_learning_candidate_and_failure_reason_compose_purely(): void
    {
        $this->assertSame(
            'needs_review',
            Support::status(['status' => 'blocked'], [], [], [], [], 'passed'),
        );
        $this->assertSame(
            'learning_candidate',
            Support::status(
                ['status' => 'ready'],
                ['measured' => true, 'roi_score' => 0.1],
                [],
                [],
                ['measured' => true, 'waste_ratio' => 0.5, 'noise_count' => 0],
                'passed',
            ),
        );
        $this->assertSame(
            'recorded',
            Support::status(
                ['status' => 'ready'],
                ['measured' => true, 'roi_score' => 0.9],
                [],
                [],
                ['measured' => true, 'waste_ratio' => 0.1, 'noise_count' => 0],
                'passed',
            ),
        );

        $learning = Support::learningCandidate(
            [
                'retrieval_receipt_id' => 'r1',
                'freshness_quality_gate_hash' => 'g1',
                'outcome_status' => 'failed',
            ],
            ['measured' => true, 'roi_score' => 0.2],
            [['source_type' => 'tests']],
            [['source_ref_hash' => 'n1']],
            ['measured' => true, 'waste_ratio' => 0.5, 'noise_count' => 1],
            [
                'actions' => ['expand_missing_source_types'],
                'demote_context_refs' => ['hash:abc'],
                'next_initial_budget_multiplier' => 0.85,
            ],
            'fbhash123',
        );
        $this->assertSame('proposed', $learning['status']);
        $this->assertFalse($learning['auto_apply']);
        $this->assertContains('missed_required_sources', $learning['reasons']);
        $this->assertContains('rag_feedback:fbhash123', $learning['evidence_refs']);
        $this->assertSame(['tests'], $learning['proposed_state']['repromote_source_types']);

        $this->assertSame(
            'retrieval_missed_required_source',
            Support::failureReason([], 'failed', [['source_type' => 'x']], []),
        );
        $this->assertNull(Support::failureReason([], 'passed', [], []));
    }

    #[Test]
    public function provider_safe_ref_and_inferred_source_type_hash_unsafe_and_compounding(): void
    {
        $safe = Support::providerSafeContextRef('code:App/Foo.php');
        $this->assertSame('code:App/Foo.php', $safe);

        $hashed = Support::providerSafeContextRef('compounding_memory:secret-body-here');
        $this->assertStringStartsWith('hash:', $hashed);
        $this->assertSame(29, strlen($hashed)); // hash: + 24

        $weird = Support::providerSafeContextRef('has spaces and $ymbols!!!');
        $this->assertStringStartsWith('hash:', $weird);

        $this->assertSame('code', Support::inferredSourceType('code:App/Foo'));
        $this->assertSame('test', Support::inferredSourceType('path/to/MyTest.php'));
        $this->assertSame('doc', Support::inferredSourceType('readme.md'));
        $this->assertSame('unknown', Support::inferredSourceType(''));
    }

    #[Test]
    public function applied_policy_snapshot_and_defer_sections_and_scalar_list_are_pure(): void
    {
        $this->assertSame([], Support::appliedPolicySnapshot(['entries' => []]));
        $single = Support::appliedPolicySnapshot([
            'entries' => [['policy_snapshot' => ['a' => 1]]],
        ]);
        $this->assertSame(['a' => 1], $single);

        $union = Support::appliedPolicySnapshot([
            'entries' => [
                ['policy_snapshot' => ['a' => 1]],
                ['policy_snapshot' => ['b' => 2]],
            ],
        ]);
        $this->assertSame('atlas.aobg.applied_policy_snapshot_union.v1', $union['schema_version']);
        $this->assertSame(2, $union['pack_count']);

        $sections = Support::deferSections([
            ['ref' => 'tests/Unit/XTest.php', 'source_type' => 'test'],
            ['ref' => 'routes/api.php', 'source_type' => 'route'],
            ['ref' => 'database/migrations/2026_x.php', 'source_type' => 'migration'],
        ]);
        $this->assertSame(['tests', 'routes', 'migrations'], $sections);

        $this->assertSame(['a', 'b', '1'], Support::scalarStringList(['a', 'a', ' b ', 1, null, []]));
        $this->assertSame(['a'], Support::scalarStringList(['a', null, []]));
    }

    #[Test]
    public function feedback_event_and_next_retrieval_hint_compose_from_pure_inputs(): void
    {
        $roi = Support::contextRoi([], 1, 1, 1, ['status' => 'ready'], 'failed', [
            'post_execution_utility' => 40,
        ], 3, true);
        $attribution = [
            'measured' => true,
            'usage_basis' => 'explicit_used_refs',
            'delivery_basis' => 'synthetic_rerank_or_explicit_input',
            'used_count' => 1,
            'noise_count' => 1,
            'waste_ratio' => 0.5,
            'missing_source_types' => ['tests'],
            'noise_refs' => [['ref' => 'n1', 'source_type' => 'doc']],
            'unused_refs' => [],
        ];
        $policy = Support::nextContextPolicy($attribution, $roi);
        $event = Support::feedbackEvent(
            [
                'status' => 'ready',
                'freshness_quality_gate_hash' => 'gatehash',
                'ranking_ref' => ['rerank_result_hash' => 'rr'],
                'freshness_report' => ['items' => []],
            ],
            $roi,
            [['source_type' => 'tests']],
            [['source_ref_hash' => 'n1', 'source_type' => 'doc']],
            $attribution,
            'failed',
            'operator_reported',
            ['domain' => 'developer', 'task_type' => 'debug', 'retrieval_receipt_id' => 'rec1'],
            ['hit' => false, 'hashes' => [], 'entries' => []],
        );

        $this->assertSame(Host::FEEDBACK_EVENT_SCHEMA, $event['schema_version']);
        $this->assertSame('developer.debug', $event['flow_id']);
        $this->assertSame('rec1', $event['retrieval_receipt_id']);
        $this->assertSame(['tests'], $event['missed_required_sources']);
        $this->assertSame('retrieval_missed_required_source', $event['failure_reason']);

        $hint = Support::nextRetrievalHint(
            [['source_type' => 'tests']],
            [['source_ref_hash' => 'n1']],
            $roi,
            $policy,
        );
        $this->assertNotNull($hint);
        $this->assertTrue($hint['advisory']);
        $this->assertFalse($hint['auto_apply']);
        $this->assertSame(['tests'], $hint['should_repromote_sources']);

        $quietPolicy = Support::nextContextPolicy([
            'missing_source_types' => [],
            'noise_refs' => [],
            'unused_refs' => [],
            'waste_ratio' => 0.0,
            'measured' => false,
        ], ['measured' => false, 'roi_score' => null]);
        $this->assertNull(Support::nextRetrievalHint([], [], ['roi_score' => null], $quietPolicy));
    }
}
