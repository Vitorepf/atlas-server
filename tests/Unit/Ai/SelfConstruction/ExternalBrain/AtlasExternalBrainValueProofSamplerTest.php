<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueProofSampler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueProofSamplerTest extends TestCase
{
    private AtlasExternalBrainValueProofSampler $sampler;

    protected function setUp(): void
    {
        $this->sampler = new AtlasExternalBrainValueProofSampler;
    }

    private function record(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id'     => 'task-1',
            'changed_files'      => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'integration_status' => false,
            'downstream_usage'   => [],
            'behavior_evidence'  => [],
            'tests_passed'       => true,
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->sampler->sample($this->record());

        foreach (['schema', 'task_packet_id', 'value_class', 'confidence', 'signals', 'evidence_class', 'value_proven', 'missing_value_evidence'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainValueProofSampler::SCHEMA, $result['schema']);
        $this->assertSame('task-1', $result['task_packet_id']);
    }

    public function test_value_class_is_one_of_valid_values(): void
    {
        $valid = [
            AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY,
            AtlasExternalBrainValueProofSampler::CLASS_OBSERVABILITY,
            AtlasExternalBrainValueProofSampler::CLASS_CONSOLIDATION,
            AtlasExternalBrainValueProofSampler::CLASS_SCAFFOLDING,
            AtlasExternalBrainValueProofSampler::CLASS_UNKNOWN,
        ];

        $result = $this->sampler->sample($this->record());
        $this->assertContains($result['value_class'], $valid);
    }

    // ── AC1: real_capability requires integration + behavior evidence ─────────

    public function test_real_capability_when_integration_status_true_and_tests_present(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    public function test_real_capability_when_downstream_usage_non_empty_and_tests_present(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => ['app/Services/AtlasBar.php'],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    public function test_real_capability_confidence_high_with_multiple_signals(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'downstream_usage'   => ['app/Services/AtlasBar.php'],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'behavior_evidence'  => ['green_gate_run'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
        $this->assertSame(AtlasExternalBrainValueProofSampler::CONFIDENCE_HIGH, $result['confidence']);
    }

    // ── AC2: commit count / file count alone NOT sufficient ──────────────────

    public function test_not_real_capability_when_integration_missing_even_with_many_files(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'changed_files'      => array_map(fn ($i) => "app/Services/Svc{$i}.php", range(1, 20)),
        ]));

        $this->assertNotSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class'],
            'Many files without integration evidence must not be classified as real_capability');
    }

    public function test_not_real_capability_with_integration_but_no_behavior_proof(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => [],
            'behavior_evidence'  => [],
        ]));

        $this->assertNotSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    // ── observability classification ─────────────────────────────────────────

    public function test_observability_when_all_impl_files_are_logging_or_monitoring(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/EventLogger.php', 'tests/Unit/EventLoggerTest.php'],
            'tests'              => ['tests/Unit/EventLoggerTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_OBSERVABILITY, $result['value_class']);
    }

    // ── consolidation classification ─────────────────────────────────────────

    public function test_consolidation_when_all_impl_files_are_dedup_or_merge(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/DeduplicateService.php', 'tests/Unit/DeduplicateServiceTest.php'],
            'tests'              => ['tests/Unit/DeduplicateServiceTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_CONSOLIDATION, $result['value_class']);
    }

    // ── scaffolding classification ───────────────────────────────────────────

    public function test_scaffolding_when_impl_files_are_stubs_or_placeholders(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/StubProvider.php'],
            'tests'              => [],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_SCAFFOLDING, $result['value_class']);
        $this->assertSame(AtlasExternalBrainValueProofSampler::CONFIDENCE_LOW, $result['confidence']);
    }

    // ── unknown ──────────────────────────────────────────────────────────────

    public function test_unknown_when_no_evidence_at_all(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => [],
            'behavior_evidence'  => [],
            'changed_files'      => [],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_UNKNOWN, $result['value_class']);
    }

    // ── batch ────────────────────────────────────────────────────────────────

    public function test_sample_batch_returns_schema_samples_and_count(): void
    {
        $result = $this->sampler->sampleBatch([
            $this->record(['task_packet_id' => 'a']),
            $this->record(['task_packet_id' => 'b', 'integration_status' => true, 'tests' => ['t.php']]),
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::SCHEMA, $result['schema']);
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['samples']);
    }

    // ── admit() — schema / envelope ──────────────────────────────────────────

    public function test_admit_result_has_required_keys(): void
    {
        $result = $this->sampler->admit(['unlocks_task_count' => 3]);

        foreach (['schema', 'admitted', 'max_dimension_score', 'dimension_scores', 'admission_evidence', 'rejection_reason'] as $key) {
            $this->assertArrayHasKey($key, $result, "admit() missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainValueProofSampler::SCHEMA_ADMIT, $result['schema']);
    }

    public function test_admit_dimension_scores_has_all_five_dimensions(): void
    {
        $result = $this->sampler->admit([]);

        foreach (['unlock', 'risk_reduction', 'simplification', 'autonomy_gain', 'certification_strength'] as $dim) {
            $this->assertArrayHasKey($dim, $result['dimension_scores'], "dimension_scores missing: {$dim}");
        }
    }

    // ── admit() — unlock dimension ───────────────────────────────────────────

    public function test_high_unlock_count_is_admitted(): void
    {
        $result = $this->sampler->admit(['unlocks_task_count' => 5]);

        $this->assertTrue($result['admitted']);
        $this->assertSame(1.0, $result['dimension_scores']['unlock']);
    }

    public function test_partial_unlock_score_normalized_correctly(): void
    {
        $result = $this->sampler->admit(['unlocks_task_count' => 2]);

        $this->assertEqualsWithDelta(0.4, $result['dimension_scores']['unlock'], 0.001);
        $this->assertTrue($result['admitted']); // 0.4 >= 0.30
    }

    // ── admit() — risk_reduction dimension ───────────────────────────────────

    public function test_high_risk_reduction_is_admitted(): void
    {
        $result = $this->sampler->admit(['give_back_risk_delta' => 0.50]);

        $this->assertTrue($result['admitted']);
        $this->assertSame(0.5, $result['dimension_scores']['risk_reduction']);
    }

    public function test_negative_risk_delta_is_clamped_to_zero(): void
    {
        $result = $this->sampler->admit(['give_back_risk_delta' => -0.5]);

        $this->assertSame(0.0, $result['dimension_scores']['risk_reduction']);
    }

    // ── admit() — runnable but low-impact → rejected ─────────────────────────

    public function test_all_dimensions_zero_is_rejected(): void
    {
        $result = $this->sampler->admit([]);

        $this->assertFalse($result['admitted']);
        $this->assertNotNull($result['rejection_reason']);
        $this->assertStringContainsString('no_dimension_above_min', $result['rejection_reason']);
        $this->assertSame([], $result['admission_evidence']);
    }

    public function test_all_dimensions_below_min_is_rejected_even_with_tests(): void
    {
        $result = $this->sampler->admit([
            'unlocks_task_count'           => 1,   // 0.20 — below 0.30
            'give_back_risk_delta'         => 0.1,
            'simplification_score'         => 0.1,
            'autonomy_gain_score'          => 0.1,
            'certification_strength_score' => 0.1,
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertNotNull($result['rejection_reason']);
    }

    // ── admit() — admission_evidence ─────────────────────────────────────────

    public function test_admission_evidence_lists_only_passing_dimensions(): void
    {
        $result = $this->sampler->admit([
            'autonomy_gain_score'  => 0.80,
            'simplification_score' => 0.10,
        ]);

        $this->assertTrue($result['admitted']);
        $evidence = implode(' ', $result['admission_evidence']);
        $this->assertStringContainsString('autonomy_gain', $evidence);
        $this->assertStringNotContainsString('simplification', $evidence);
    }

    public function test_rejection_reason_is_null_when_admitted(): void
    {
        $result = $this->sampler->admit(['autonomy_gain_score' => 0.80]);

        $this->assertTrue($result['admitted']);
        $this->assertNull($result['rejection_reason']);
    }

    // ── admit() — configurable threshold ─────────────────────────────────────

    public function test_custom_min_dimension_score_via_config(): void
    {
        $result = $this->sampler->admit(
            ['unlocks_task_count' => 1],           // unlock = 0.20
            ['min_dimension_score' => 0.10],
        );

        $this->assertTrue($result['admitted'], '0.20 >= 0.10 → admitted');
    }

    // ── AC2: evidence_class (proven_value / partial_value / proxy_green / missing_evidence / contradicted_value) ──

    public function test_evidence_class_proven_value_for_real_capability(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::EVIDENCE_PROVEN_VALUE, $result['evidence_class']);
    }

    public function test_evidence_class_proxy_green_for_observability(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/EventLogger.php', 'tests/Unit/EventLoggerTest.php'],
            'tests'              => ['tests/Unit/EventLoggerTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::EVIDENCE_PROXY_GREEN, $result['evidence_class']);
    }

    public function test_evidence_class_proxy_green_for_scaffolding(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/StubProvider.php'],
            'tests'              => [],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::EVIDENCE_PROXY_GREEN, $result['evidence_class']);
    }

    public function test_evidence_class_missing_evidence_when_no_signals(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => [],
            'behavior_evidence'  => [],
            'changed_files'      => [],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::EVIDENCE_MISSING_EVIDENCE, $result['evidence_class']);
    }

    public function test_evidence_class_partial_value_when_some_signal_but_unconfirmed(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'changed_files'      => ['app/Services/AtlasFoo.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::EVIDENCE_PARTIAL_VALUE, $result['evidence_class']);
    }

    public function test_evidence_class_contradicted_value_overrides_everything(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status'      => true,
            'tests'                   => ['tests/Unit/AtlasFooTest.php'],
            'contradicting_evidence'  => ['post_commit_regression_detected'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::EVIDENCE_CONTRADICTED_VALUE, $result['evidence_class']);
        // value_class classification itself is unaffected by the contradiction flag.
        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    public function test_evidence_class_absent_contradicting_evidence_does_not_trigger_contradiction(): void
    {
        $result = $this->sampler->sample($this->record());

        $this->assertNotSame(AtlasExternalBrainValueProofSampler::EVIDENCE_CONTRADICTED_VALUE, $result['evidence_class']);
    }

    // ── AC3: capability_lift dimension + unevidenced_claims flagging ───────────

    public function test_admit_includes_capability_lift_dimension(): void
    {
        $result = $this->sampler->admit(['capability_lift_score' => 0.9]);

        $this->assertArrayHasKey('capability_lift', $result['dimension_scores']);
        $this->assertSame(0.9, $result['dimension_scores']['capability_lift']);
        $this->assertTrue($result['admitted']);
    }

    public function test_admit_flags_unevidenced_claim_when_no_evidence_refs_supplied(): void
    {
        $result = $this->sampler->admit(['autonomy_gain_score' => 0.8]);

        $this->assertContains('autonomy_gain', $result['unevidenced_claims']);
    }

    public function test_admit_does_not_flag_claim_with_supporting_evidence_refs(): void
    {
        $result = $this->sampler->admit([
            'autonomy_gain_score' => 0.8,
            'autonomy_gain_evidence_refs' => ['receipt:autonomy-lift-1'],
        ]);

        $this->assertNotContains('autonomy_gain', $result['unevidenced_claims']);
    }

    public function test_admit_never_flags_zero_score_dimension_as_unevidenced(): void
    {
        $result = $this->sampler->admit(['autonomy_gain_score' => 0.8]);

        $this->assertNotContains('risk_reduction', $result['unevidenced_claims']);
        $this->assertNotContains('simplification', $result['unevidenced_claims']);
    }

    // ── AC4: family_guidance lowers future priority for proxy-green families ───

    public function test_family_guidance_absent_when_no_task_family_supplied(): void
    {
        $result = $this->sampler->sampleBatch([$this->record()]);

        $this->assertSame([], $result['family_guidance']);
    }

    public function test_family_guidance_lowers_priority_for_high_proxy_green_family(): void
    {
        $records = [
            array_merge($this->record(['changed_files' => ['app/StubA.php'], 'tests' => []]), ['task_family' => 'family-proxy']),
            array_merge($this->record(['changed_files' => ['app/StubB.php'], 'tests' => []]), ['task_family' => 'family-proxy']),
        ];

        $result = $this->sampler->sampleBatch($records);

        $guidance = $result['family_guidance'][0];
        $this->assertSame('family-proxy', $guidance['task_family']);
        $this->assertSame(1.0, $guidance['proxy_green_rate']);
        $this->assertTrue($guidance['lowered_future_priority']);
        $this->assertLessThan(0.0, $guidance['priority_adjustment']);
    }

    public function test_family_guidance_does_not_lower_priority_for_proven_value_family(): void
    {
        $records = [
            array_merge($this->record(['integration_status' => true]), ['task_family' => 'family-good']),
        ];

        $result = $this->sampler->sampleBatch($records);

        $guidance = $result['family_guidance'][0];
        $this->assertFalse($guidance['lowered_future_priority']);
        $this->assertSame(0.0, $guidance['priority_adjustment']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // NEW: AC2 — Green-only gate (value_proven / missing_value_evidence)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_value_proven_false_when_only_tests_passed_without_delta(): void
    {
        // Sample with only tests_passed but no delta evidence → value_proven must be false.
        $result = $this->sampler->sample($this->record([
            'tests_passed'       => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'integration_status' => false,
            'downstream_usage'   => [],
            'behavior_evidence'  => [],
            'behavior_delta'     => [],
            'autonomy_delta'     => [],
        ]));

        $this->assertFalse($result['value_proven'], 'Green tests alone must not prove value');
    }

    public function test_value_proven_false_when_only_tests_passed_without_any_delta_evidence(): void
    {
        // All delta fields empty → value_proven=false even with tests_passed=true.
        $result = $this->sampler->sample($this->record([
            'tests_passed'          => true,
            'tests'                 => ['tests/Unit/AtlasFooTest.php'],
            'behavior_delta'        => [],
            'autonomy_delta'        => [],
            'risk_reduction_delta'  => [],
            'simplification_delta'  => [],
            'learning_delta'        => [],
        ]));

        $this->assertFalse($result['value_proven'],
            'value_proven must be false when all five delta fields are empty regardless of tests_passed');
    }

    public function test_value_proven_true_with_behavior_delta(): void
    {
        $result = $this->sampler->sample($this->record([
            'behavior_delta' => ['behavior:added-command-handler'],
        ]));

        $this->assertTrue($result['value_proven'],
            'behavior_delta alone must set value_proven=true');
    }

    public function test_value_proven_true_with_autonomy_delta(): void
    {
        $result = $this->sampler->sample($this->record([
            'autonomy_delta' => ['autonomy:added-self-healing'],
        ]));

        $this->assertTrue($result['value_proven'],
            'autonomy_delta alone must set value_proven=true');
    }

    public function test_value_proven_true_with_risk_reduction_delta(): void
    {
        $result = $this->sampler->sample($this->record([
            'risk_reduction_delta' => ['risk:added-circuit-breaker'],
        ]));

        $this->assertTrue($result['value_proven'],
            'risk_reduction_delta alone must set value_proven=true');
    }

    public function test_value_proven_true_with_simplification_delta(): void
    {
        $result = $this->sampler->sample($this->record([
            'simplification_delta' => ['simplification:removed-complexity'],
        ]));

        $this->assertTrue($result['value_proven'],
            'simplification_delta alone must set value_proven=true');
    }

    public function test_value_proven_true_with_learning_delta(): void
    {
        $result = $this->sampler->sample($this->record([
            'learning_delta' => ['learning:extracted-pattern-x'],
        ]));

        $this->assertTrue($result['value_proven'],
            'learning_delta alone must set value_proven=true');
    }

    public function test_value_proven_false_when_contradicted_even_with_delta(): void
    {
        // contradicting_evidence overrides delta evidence — value_proven must be false.
        $result = $this->sampler->sample($this->record([
            'behavior_delta'         => ['behavior:added-command-handler'],
            'contradicting_evidence' => ['post_commit_regression_detected'],
        ]));

        $this->assertFalse($result['value_proven'],
            'contradicting_evidence must override delta evidence making value_proven=false');
    }

    public function test_value_proven_emitted_on_every_sample_result(): void
    {
        $result = $this->sampler->sample($this->record());
        $this->assertArrayHasKey('value_proven', $result);
        $this->assertIsBool($result['value_proven']);
    }

    public function test_value_proven_emitted_across_all_classifications(): void
    {
        // real_capability with delta
        $r1 = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'behavior_delta'     => ['behavior:added-feature'],
        ]));
        $this->assertTrue($r1['value_proven']);

        // real_capability without delta
        $r2 = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
        ]));
        $this->assertFalse($r2['value_proven']);

        // scaffolding without delta
        $r3 = $this->sampler->sample($this->record([
            'changed_files' => ['app/StubProvider.php'],
            'tests'         => [],
        ]));
        $this->assertFalse($r3['value_proven']);

        // observability without delta
        $r4 = $this->sampler->sample($this->record([
            'changed_files' => ['app/MetricCollector.php'],
            'tests'         => [],
        ]));
        $this->assertFalse($r4['value_proven']);

        // consolidation without delta
        $r5 = $this->sampler->sample($this->record([
            'changed_files' => ['app/Deduplicator.php'],
            'tests'         => ['tests/Unit/DeduplicatorTest.php'],
        ]));
        $this->assertFalse($r5['value_proven']);
    }

    // ── missing_value_evidence (AC4) ─────────────────────────────────────────

    public function test_missing_value_evidence_true_when_value_proven_false_and_some_evidence_exists(): void
    {
        // tests_passed=true, tests present, no delta → value_proven=false, missing_value_evidence=true
        $result = $this->sampler->sample($this->record([
            'tests_passed'       => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'integration_status' => false,
            'downstream_usage'   => [],
            'behavior_evidence'  => [],
        ]));

        $this->assertFalse($result['value_proven']);
        $this->assertTrue($result['missing_value_evidence'],
            'Should flag missing_value_evidence when tests_passed is true but no delta evidence');
    }

    public function test_missing_value_evidence_false_when_no_evidence_at_all(): void
    {
        // No evidence at all → also no "missing value evidence" because nothing exists to be narrow about.
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => [],
            'behavior_evidence'  => [],
            'changed_files'      => [],
            'tests_passed'       => false,
        ]));

        $this->assertFalse($result['value_proven']);
        $this->assertFalse($result['missing_value_evidence'],
            'No evidence at all should not flag missing_value_evidence');
    }

    public function test_missing_value_evidence_false_when_value_proven_true(): void
    {
        // Delta evidence present → value_proven=true, missing_value_evidence=false
        $result = $this->sampler->sample($this->record([
            'behavior_delta' => ['behavior:added-feature'],
        ]));

        $this->assertTrue($result['value_proven']);
        $this->assertFalse($result['missing_value_evidence'],
            'When value is proven, missing_value_evidence must be false');
    }

    public function test_missing_value_evidence_true_when_only_integration_signal_without_delta(): void
    {
        // Has integration_status but no delta → value_proven=false, missing_value_evidence=true
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => [],
            'behavior_evidence'  => [],
        ]));

        $this->assertFalse($result['value_proven']);
        $this->assertTrue($result['missing_value_evidence'],
            'Integration signal without delta should flag missing_value_evidence');
    }

    public function test_missing_value_evidence_true_when_only_behavior_evidence_without_delta(): void
    {
        // Has behavior_evidence but no delta → value_proven=false, missing_value_evidence=true
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => [],
            'behavior_evidence'  => ['external_verification'],
        ]));

        $this->assertFalse($result['value_proven']);
        $this->assertTrue($result['missing_value_evidence'],
            'Behavior evidence without delta should flag missing_value_evidence');
    }

    public function test_missing_value_evidence_emitted_in_signals_when_true(): void
    {
        $result = $this->sampler->sample($this->record([
            'tests_passed' => true,
            'tests'        => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertTrue($result['missing_value_evidence']);
        $this->assertContains('missing_value_evidence', $result['signals'],
            'signals list should contain missing_value_evidence when flag is true');
    }

    public function test_delta_evidence_present_signal_emitted(): void
    {
        $result = $this->sampler->sample($this->record([
            'behavior_delta' => ['behavior:added-feature'],
        ]));

        $this->assertContains('delta_evidence_present', $result['signals'],
            'signals list should contain delta_evidence_present when delta is present');
    }

    public function test_value_proven_emitted_in_batch_samples(): void
    {
        $result = $this->sampler->sampleBatch([
            $this->record(['task_packet_id' => 'a', 'tests_passed' => true]),
            $this->record(['task_packet_id' => 'b', 'behavior_delta' => ['behavior:added-feature']]),
        ]);

        $this->assertCount(2, $result['samples']);
        $this->assertFalse($result['samples'][0]['value_proven'], 'Record with only tests_passed must have value_proven=false');
        $this->assertTrue($result['samples'][1]['value_proven'], 'Record with behavior_delta must have value_proven=true');
    }
}
