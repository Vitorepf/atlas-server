<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class RetrievalFeedbackLoopTest extends TestCase
{
    use BootsCompoundingSchema;

    /** @var array<int,string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        foreach ($this->tempDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function test_successful_context_feedback_records_strong_roi_without_learning_candidate(): void
    {
        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'passed',
            'max_refs' => 8,
            'record' => true,
        ]);

        $this->assertSame(AtlasRetrievalFeedbackLoopService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('recorded', $payload['status']);
        $this->assertSame(AtlasRetrievalFeedbackLoopService::FEEDBACK_EVENT_SCHEMA, data_get($payload, 'feedback_event.schema_version'));
        $this->assertSame(AtlasRetrievalFeedbackLoopService::CONTEXT_ROI_SCHEMA, data_get($payload, 'context_roi.schema_version'));
        $this->assertSame(AtlasRetrievalFeedbackLoopService::CONTEXT_REF_ATTRIBUTION_SCHEMA, data_get($payload, 'context_ref_attribution.schema_version'));
        $this->assertSame(AtlasRetrievalFeedbackLoopService::NEXT_CONTEXT_POLICY_SCHEMA, data_get($payload, 'next_context_policy.schema_version'));
        $this->assertFalse(data_get($payload, 'measured'));
        $this->assertFalse(data_get($payload, 'context_roi.measured'));
        $this->assertSame('unmeasured', data_get($payload, 'context_roi.quality_band'));
        $this->assertSame(['keep_current_pack'], data_get($payload, 'next_context_policy.actions'));
        $this->assertSame('none', data_get($payload, 'learning_candidate.status'));
        $this->assertTrue(data_get($payload, 'persistence.persisted'));
        $this->assertFalse(data_get($payload, 'learning_candidate.auto_apply'));
        $this->assertFalse(data_get($payload, 'policy.auto_promote_learning'));
        $this->assertSame(1, AiRagFeedbackEvent::query()->count());
        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame(AtlasRetrievalFeedbackLoopService::CONTEXT_REF_ATTRIBUTION_SCHEMA, data_get($event->payload, 'payload.context_ref_attribution.schema_version'));
        $this->assertSame(AtlasRetrievalFeedbackLoopService::NEXT_CONTEXT_POLICY_SCHEMA, data_get($event->payload, 'payload.next_context_policy.schema_version'));
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_failed_context_with_missing_sources_creates_review_only_learning_candidate(): void
    {
        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with missing billing fixture',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'failed',
            'failure_reason' => 'test failed because billing fixture was not retrieved',
            'missed_required_sources' => [['source_type' => 'billing_fixture', 'reason' => 'test_failure_missing_fixture']],
            'max_refs' => 8,
            'record' => true,
        ]);

        $this->assertSame('needs_review', $payload['status']);
        $this->assertSame('proposed', data_get($payload, 'learning_candidate.status'));
        $this->assertTrue(data_get($payload, 'learning_candidate.requires_review'));
        $this->assertFalse(data_get($payload, 'learning_candidate.auto_apply'));
        $this->assertContains('missed_required_sources', data_get($payload, 'learning_candidate.reasons'));
        $this->assertSame(['billing_fixture'], data_get($payload, 'learning_candidate.proposed_state.repromote_source_types'));
        $this->assertStringNotContainsString('billing fixture was not retrieved', json_encode($payload, JSON_THROW_ON_ERROR));

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame('atlas.ai.rag.feedback.v1', $event->schema_version);
        $this->assertSame(['billing_fixture'], $event->missed_required_sources);
        $this->assertNotSame('', $event->feedback_hash);
    }

    public function test_noise_refs_create_demote_candidate_without_auto_apply(): void
    {
        $baseline = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with tests',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'passed',
            'max_refs' => 8,
        ]);
        $noiseHash = array_key_first((array) data_get($baseline, 'feedback_event.source_utility'));

        $this->assertNotNull($noiseHash);

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with tests',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'partial',
            'noise_ref_hashes' => [$noiseHash],
            'max_refs' => 8,
        ]);

        $this->assertSame('learning_candidate', $payload['status']);
        $this->assertSame(1, data_get($payload, 'context_roi.noise_sources'));
        $this->assertContains('noise_context_detected', data_get($payload, 'learning_candidate.reasons'));
        $this->assertSame([$noiseHash], data_get($payload, 'learning_candidate.proposed_state.demote_noise_source_hashes'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
    }

    public function test_context_ref_attribution_drives_next_context_policy_without_raw_text(): void
    {
        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'debug repo with noisy initial context and missing schema signal',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'outcome_status' => 'partial',
            'delivered_context_refs' => [
                'doc:owner-context',
                'test:full-suite-dump',
                'graph:impact-map',
            ],
            'used_context_refs' => ['doc:owner-context'],
            'noise_context_refs' => ['test:full-suite-dump'],
            'missed_required_sources' => [['source_type' => 'migration', 'reason' => 'missing_schema_context']],
            'post_execution_utility' => 45,
            'max_refs' => 1,
        ]);

        $this->assertSame(AtlasRetrievalFeedbackLoopService::CONTEXT_REF_ATTRIBUTION_SCHEMA, data_get($payload, 'context_ref_attribution.schema_version'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'context_ref_attribution.delivered_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'context_ref_attribution.used_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'context_ref_attribution.unused_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'context_ref_attribution.noise_count'));
        $this->assertContains('migration', data_get($payload, 'context_ref_attribution.missing_source_types'));

        $this->assertContains('expand_missing_source_types', data_get($payload, 'next_context_policy.actions'));
        $this->assertContains('demote_noise_context_refs', data_get($payload, 'next_context_policy.actions'));
        $this->assertContains('shrink_initial_context', data_get($payload, 'next_context_policy.actions'));
        $this->assertContains('tests', data_get($payload, 'next_context_policy.defer_sections'));
        $this->assertSame('provider_safe_ref_or_hash_only', data_get($payload, 'context_ref_attribution.source_policy.ref_contract'));
        $this->assertFalse(data_get($payload, 'next_context_policy.auto_apply'));
        $this->assertContains('context_waste_detected', data_get($payload, 'learning_candidate.reasons'));
        $this->assertStringNotContainsString('missing schema signal', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_command_emits_json_without_recording_by_default(): void
    {
        $exit = Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'debug repo with tests',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--outcome' => 'passed',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasRetrievalFeedbackLoopService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse(data_get($payload, 'persistence.requested'));
        $this->assertFalse(data_get($payload, 'persistence.persisted'));
    }

    public function test_command_accepts_provider_safe_ref_feedback_signals(): void
    {
        $exit = Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'debug repo with noisy initial pack',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--outcome' => 'partial',
            '--delivered-ref' => ['doc:owner-context', 'test:full-suite-dump'],
            '--used-ref' => ['doc:owner-context'],
            '--noise-ref' => ['test:full-suite-dump'],
            '--missed-source' => ['migration'],
            '--utility' => '42',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertContains('expand_missing_source_types', data_get($payload, 'next_context_policy.actions'));
        $this->assertContains('demote_noise_context_refs', data_get($payload, 'next_context_policy.actions'));
        $this->assertSame(42, data_get($payload, 'context_roi.post_execution_utility'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
    }

    public function test_command_records_used_memory_ref_and_run_outcome_for_recall_lift_measurement(): void
    {
        config([
            'atlas.ai.loop.learning_recall_use_lift.enabled' => true,
            'atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm' => 1,
            'atlas.ai.loop.learning_recall_use_lift.min_passing_memory_use' => 1,
        ]);

        $memory = AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.ai.compounding.memory.v1',
            'learning_candidate_id' => null,
            'memory_type' => 'routing_memory',
            'scope' => 'atlas-server',
            'flow_id' => 'atlas_dev',
            'status' => 'active',
            'claim' => 'Prefer recorded recall-use feedback for certified Atlas Dev tasks.',
            'confidence' => 92,
            'evidence_refs' => ['receipt:l5-11-command-path'],
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            'valid_until' => now()->addDays(7),
            'last_revalidated_at' => now(),
            'payload' => [],
            'memory_hash' => hash('sha256', 'l5-11-command-memory'),
        ]);

        $passingOutcome = $this->runOutcome('l5-11-with-recall', 'atlas_dev', 'passed', 92);
        $failedOutcome = $this->runOutcome('l5-11-baseline', 'atlas_dev', 'failed', 40);

        $exit = Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'l5-11 certified task used active compounding recall',
            '--task-type' => 'debug',
            '--domain' => 'atlas_dev',
            '--outcome' => 'passed',
            '--memory-ref' => [$memory->memory_hash],
            '--run-outcome-id' => $passingOutcome->id,
            '--utility' => '92',
            '--record' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'persistence.persisted'));

        $baselineExit = Artisan::call('atlas:context:retrieval-feedback', [
            '--query' => 'l5-11 certified task baseline without compounding recall',
            '--task-type' => 'debug',
            '--domain' => 'atlas_dev',
            '--outcome' => 'failed',
            '--run-outcome-id' => $failedOutcome->id,
            '--utility' => '40',
            '--record' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $baselineExit);
        $this->assertSame(2, AiRagFeedbackEvent::query()->count());

        $event = AiRagFeedbackEvent::query()
            ->where('run_outcome_id', $passingOutcome->id)
            ->firstOrFail();
        $this->assertSame('used', $event->source_utility['compounding_memory:'.$memory->memory_hash] ?? null);

        $report = app(AtlasLearningRecallUseLiftService::class)->report(minCases: 1, minPassingUse: 1);

        $this->assertSame('positive_live_lift', $report['status']);
        $this->assertTrue(data_get($report, 'claim_policy.completion_claim_allowed'));
        $this->assertSame(1, data_get($report, 'measurement.with_recalled_memory.case_count'));
        $this->assertSame(1, data_get($report, 'measurement.without_recalled_memory.case_count'));
        $this->assertSame(1.0, data_get($report, 'measurement.passed_rate_lift'));
    }

    public function test_DeliveredAttribution_event_with_real_pack_hash_computes_noise_as_delivered_minus_used_exact_set(): void
    {
        $pack = $this->recordDeliveredPack('delivered-attribution-exact', [
            ['id' => 'sym:ExactOne', 'file_path' => 'app/ExactOne.php', 'symbol_type' => 'class'],
            ['id' => 'sym:ExactTwo', 'file_path' => 'app/ExactTwo.php', 'symbol_type' => 'class'],
            ['id' => 'sym:ExactThree', 'file_path' => 'app/ExactThree.php', 'symbol_type' => 'class'],
        ]);
        $deliveredRefs = AtlasCanonicalContextRef::deliveredFromPack($pack);
        $usedRefs = [$deliveredRefs[0], $deliveredRefs[2]];
        $expectedNoiseRefs = [$deliveredRefs[1]];

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'delivered attribution exact set',
            'flow_id' => 'delivered.attribution.exact',
            'outcome_status' => 'passed',
            'context_pack_hash' => $pack['context_pack_hash'],
            'used_context_refs' => $usedRefs,
            'post_execution_utility' => 81,
            'record' => true,
        ]);

        $this->assertTrue(data_get($payload, 'context_ref_attribution.measured'));
        $this->assertTrue(data_get($payload, 'context_roi.measured'));
        $this->assertSame('explicit_used_refs', data_get($payload, 'context_ref_attribution.usage_basis'));
        $this->assertSame(3, data_get($payload, 'context_ref_attribution.delivered_count'));
        $this->assertSame(2, data_get($payload, 'context_ref_attribution.used_count'));
        $this->assertSame(1, data_get($payload, 'context_ref_attribution.noise_count'));
        $this->assertSame($deliveredRefs, data_get($payload, 'context_ref_attribution.delivered_refs.*.ref'));
        $this->assertSame($usedRefs, data_get($payload, 'context_ref_attribution.used_refs.*.ref'));
        $this->assertSame($expectedNoiseRefs, data_get($payload, 'context_ref_attribution.noise_refs.*.ref'));
        $this->assertSame($expectedNoiseRefs, data_get($payload, 'noise_ref_candidates.*.ref'));

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertTrue(data_get($event->payload, 'payload.measured'));
        $this->assertSame('explicit_used_refs', data_get($event->payload, 'payload.usage_basis'));
        $this->assertSame($expectedNoiseRefs, data_get($event->payload, 'payload.context_ref_attribution.noise_refs.*.ref'));
    }

    public function test_DeliveredAttribution_event_without_explicit_utility_is_unmeasured_and_excluded_from_measured_aggregates(): void
    {
        $unmeasuredPack = $this->recordDeliveredPack('delivered-attribution-unmeasured', [
            ['id' => 'sym:UnmeasuredOne', 'file_path' => 'app/UnmeasuredOne.php', 'symbol_type' => 'class'],
        ]);
        $measuredPack = $this->recordDeliveredPack('delivered-attribution-measured', [
            ['id' => 'sym:MeasuredOne', 'file_path' => 'app/MeasuredOne.php', 'symbol_type' => 'class'],
            ['id' => 'sym:MeasuredTwo', 'file_path' => 'app/MeasuredTwo.php', 'symbol_type' => 'class'],
        ]);
        $measuredRefs = AtlasCanonicalContextRef::deliveredFromPack($measuredPack);

        $unmeasured = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'delivered attribution unmeasured event',
            'flow_id' => 'delivered.attribution.aggregate',
            'outcome_status' => 'passed',
            'context_pack_hash' => $unmeasuredPack['context_pack_hash'],
            'record' => true,
        ]);
        $measured = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'delivered attribution measured event',
            'flow_id' => 'delivered.attribution.aggregate',
            'outcome_status' => 'passed',
            'context_pack_hash' => $measuredPack['context_pack_hash'],
            'used_context_refs' => [$measuredRefs[0]],
            'post_execution_utility' => 74,
            'record' => true,
        ]);

        $this->assertFalse(data_get($unmeasured, 'measured'));
        $this->assertFalse(data_get($unmeasured, 'context_roi.measured'));
        $this->assertNull(data_get($unmeasured, 'context_roi.post_execution_utility'));
        $this->assertTrue(data_get($measured, 'measured'));
        $this->assertSame(2, AiRagFeedbackEvent::query()->count());

        $policyMethod = new ReflectionMethod(AtlasOpenBrainContextPackService::class, 'contextDeliveryPolicy');
        $policyMethod->setAccessible(true);
        $policy = $policyMethod->invoke(app(AtlasOpenBrainContextPackService::class), [
            'flow_id' => 'delivered.attribution.aggregate',
        ]);

        $this->assertSame(2, data_get($policy, 'evidence.total_event_count'));
        $this->assertSame(1, data_get($policy, 'evidence.measured_count'));
        $this->assertSame(1, data_get($policy, 'evidence.feedback_event_count'));
        $this->assertSame(74.0, data_get($policy, 'evidence.averages.post_execution_utility'));
    }

    public function test_DeliveredAttribution_does_not_assign_automatic_86_or_92_scores(): void
    {
        $pack = $this->recordDeliveredPack('delivered-attribution-no-fabricated-scores', [
            ['id' => 'sym:NoFabricatedScores', 'file_path' => 'app/NoFabricatedScores.php', 'symbol_type' => 'class'],
        ]);

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'delivered attribution no fabricated scores',
            'flow_id' => 'delivered.attribution.no_fabricated_scores',
            'outcome_status' => 'passed',
            'context_pack_hash' => $pack['context_pack_hash'],
        ]);

        $this->assertFalse(data_get($payload, 'measured'));
        $this->assertNull(data_get($payload, 'context_roi.post_execution_utility'));
        $this->assertNull(data_get($payload, 'context_roi.context_sufficiency'));
        $this->assertNotSame(86, data_get($payload, 'context_roi.post_execution_utility'));
        $this->assertNotSame(92, data_get($payload, 'context_roi.context_sufficiency'));
        $this->assertSame('unmeasured', data_get($payload, 'context_roi.quality_band'));
    }

    public function test_MeasuredUtility_formula_is_monotonic_and_versioned(): void
    {
        $lowUse = PipelineRunExecutor::postExecutionUtilityMeasurement(
            usedCount: 1,
            deliveredCount: 4,
            unresolvedMissedCount: 0,
            outcomeStatus: 'passed',
            estimatedChars: 800,
            totalBudgetChars: 1000,
        );
        $highUse = PipelineRunExecutor::postExecutionUtilityMeasurement(
            usedCount: 3,
            deliveredCount: 4,
            unresolvedMissedCount: 0,
            outcomeStatus: 'passed',
            estimatedChars: 800,
            totalBudgetChars: 1000,
        );
        $failed = PipelineRunExecutor::postExecutionUtilityMeasurement(
            usedCount: 3,
            deliveredCount: 4,
            unresolvedMissedCount: 0,
            outcomeStatus: 'failed',
            estimatedChars: 800,
            totalBudgetChars: 1000,
        );
        $missed = PipelineRunExecutor::postExecutionUtilityMeasurement(
            usedCount: 3,
            deliveredCount: 4,
            unresolvedMissedCount: 1,
            outcomeStatus: 'passed',
            estimatedChars: 800,
            totalBudgetChars: 1000,
        );
        $noData = PipelineRunExecutor::postExecutionUtilityMeasurement(
            usedCount: 0,
            deliveredCount: 0,
            unresolvedMissedCount: 0,
            outcomeStatus: 'passed',
        );

        $this->assertGreaterThan($lowUse['post_execution_utility'], $highUse['post_execution_utility']);
        $this->assertLessThan($highUse['post_execution_utility'], $failed['post_execution_utility']);
        $this->assertSame(0, $missed['post_execution_utility']);
        $this->assertNull($noData);
        $this->assertSame(PipelineRunExecutor::POST_EXECUTION_UTILITY_FORMULA_VERSION, $highUse['formula_version']);
    }

    public function test_MeasuredUtility_events_carry_formula_version(): void
    {
        $pack = $this->recordDeliveredPack('measured-utility-formula-version', [
            ['id' => 'sym:FormulaOne', 'file_path' => 'app/FormulaOne.php', 'symbol_type' => 'class'],
            ['id' => 'sym:FormulaTwo', 'file_path' => 'app/FormulaTwo.php', 'symbol_type' => 'class'],
        ]);
        $usedRefs = [AtlasCanonicalContextRef::deliveredFromPack($pack)[0]];

        $measurement = PipelineRunExecutor::postExecutionUtilityMeasurement(
            usedCount: 1,
            deliveredCount: 2,
            unresolvedMissedCount: 0,
            outcomeStatus: 'passed',
            estimatedChars: 500,
            totalBudgetChars: 1000,
        );

        $payload = app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'measured utility formula version',
            'flow_id' => 'atlas.dev',
            'outcome_status' => 'passed',
            'context_pack_hash' => $pack['context_pack_hash'],
            'used_context_refs' => $usedRefs,
            'post_execution_utility' => $measurement['post_execution_utility'],
            'post_execution_utility_formula_version' => $measurement['formula_version'],
            'attribution_quality' => 'gate_verified',
            'record' => true,
        ]);

        $this->assertTrue(data_get($payload, 'measured'));
        $this->assertSame(PipelineRunExecutor::POST_EXECUTION_UTILITY_FORMULA_VERSION, data_get($payload, 'context_roi.formula_version'));
        $this->assertSame(PipelineRunExecutor::POST_EXECUTION_UTILITY_FORMULA_VERSION, data_get($payload, 'feedback_event.formula_version'));

        $event = AiRagFeedbackEvent::query()->firstOrFail();
        $this->assertSame(PipelineRunExecutor::POST_EXECUTION_UTILITY_FORMULA_VERSION, data_get($event->payload, 'payload.context_roi.formula_version'));
        $this->assertSame(PipelineRunExecutor::POST_EXECUTION_UTILITY_FORMULA_VERSION, data_get($event->payload, 'payload.formula_version'));
    }

    public function test_MissedResolution_repromotes_source_type_then_resolves_after_subsequent_delivery(): void
    {
        $missedPack = $this->recordDeliveredPack('missed-resolution-initial', [
            ['id' => 'sym:OnlyCode', 'file_path' => 'app/OnlyCode.php', 'symbol_type' => 'class'],
        ]);
        $initialRef = AtlasCanonicalContextRef::deliveredFromPack($missedPack)[0];

        app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'measured miss for memory source',
            'flow_id' => 'missed.resolution',
            'outcome_status' => 'passed',
            'context_pack_hash' => $missedPack['context_pack_hash'],
            'used_context_refs' => [$initialRef],
            'missed_required_sources' => [['source_type' => 'memory', 'reason' => 'needed_memory_source']],
            'post_execution_utility' => 42,
            'attribution_quality' => 'gate_verified',
            'record' => true,
        ]);

        $policyMethod = new ReflectionMethod(AtlasOpenBrainContextPackService::class, 'contextDeliveryPolicy');
        $policyMethod->setAccessible(true);
        $policy = $policyMethod->invoke(app(AtlasOpenBrainContextPackService::class), [
            'flow_id' => 'missed.resolution',
        ]);

        $this->assertContains('memory', $policy['expand_source_types']);
        $this->assertSame('bounded_source_mix_only', data_get($policy, 'evidence.auto_apply_scope'));
        $this->assertFalse(data_get($policy, 'evidence.ref_repromotion_enabled'));
        $this->assertSame(1, data_get($policy, 'evidence.unresolved_missed_count'));

        $resolvedPack = $this->recordDeliveredPackWithSections('missed-resolution-memory', memory: [[
            'id' => 'mem-resolution',
            'type' => 'decision',
            'title' => 'Memory context that resolves the miss',
            'content_hash' => hash('sha256', 'memory-context-that-resolves-the-miss'),
        ]]);
        $resolvedRef = AtlasCanonicalContextRef::deliveredFromPack($resolvedPack)[0];

        app(AtlasRetrievalFeedbackLoopService::class)->capture([
            'objective' => 'memory source delivered after miss',
            'flow_id' => 'missed.resolution',
            'outcome_status' => 'passed',
            'context_pack_hash' => $resolvedPack['context_pack_hash'],
            'used_context_refs' => [$resolvedRef],
            'post_execution_utility' => 88,
            'attribution_quality' => 'gate_verified',
            'record' => true,
        ]);

        $firstEvent = AiRagFeedbackEvent::query()->oldest('created_at')->firstOrFail();
        $this->assertSame('resolved', data_get($firstEvent->payload, 'payload.missed_resolution.status'));
        $this->assertSame(['memory'], data_get($firstEvent->payload, 'payload.missed_resolution.resolved_source_types'));

        $resolvedPolicy = $policyMethod->invoke(app(AtlasOpenBrainContextPackService::class), [
            'flow_id' => 'missed.resolution',
        ]);

        $this->assertSame(0, data_get($resolvedPolicy, 'evidence.unresolved_missed_count'));
    }

    private function runOutcome(string $runId, string $flowId, string $status, int $quality): AiRunOutcome
    {
        return AiRunOutcome::query()->create([
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'trace_id' => null,
            'flow_id' => $flowId,
            'outcome_status' => $status,
            'flow_quality' => $quality,
            'retrieval_quality' => $quality,
            'execution_quality' => $quality,
            'evidence_quality' => $quality,
            'human_override' => false,
            'learning_required' => true,
            'missed_signals' => [],
            'evidence_refs' => ['receipt:'.$runId],
            'payload' => [],
            'outcome_hash' => hash('sha256', 'outcome-'.$runId),
            'evaluated_at' => now(),
        ]);
    }

    /**
     * @param  array<int,array<string,string>>  $codeGraph
     * @return array<string,mixed>
     */
    private function recordDeliveredPack(string $hash, array $codeGraph): array
    {
        return $this->recordDeliveredPackWithSections($hash, codeGraph: $codeGraph);
    }

    /**
     * @param  array<int,array<string,string>>  $codeGraph
     * @param  array<int,array<string,mixed>>  $memory
     * @return array<string,mixed>
     */
    private function recordDeliveredPackWithSections(string $hash, array $codeGraph = [], array $memory = []): array
    {
        $ledgerPath = $this->configureDeliveredPackLedger();
        $pack = [
            'context_pack_hash' => $hash,
            'code_graph' => $codeGraph,
            'reality_graph_paths' => [],
            'memory' => $memory,
            'budget' => ['total_budget_chars' => 1000],
            'context_delivery_policy' => ['status' => 'test'],
            'generated_at' => now()->toJSON(),
        ];

        (new AtlasDeliveredPackLedger($ledgerPath))->record($pack);

        return $pack;
    }

    private function configureDeliveredPackLedger(): string
    {
        $dir = sys_get_temp_dir().'/atlas-rfl-delivered-pack-ledger-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;
        $path = $dir.'/delivered-pack-ledger.jsonl';
        config()->set('atlas.aobg.delivered_pack_ledger.path', $path);

        return $path;
    }
}
