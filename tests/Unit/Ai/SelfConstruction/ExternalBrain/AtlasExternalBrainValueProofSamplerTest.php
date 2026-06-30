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
            'task_packet_id'    => 'task-1',
            'changed_files'     => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
            'tests'             => ['tests/Unit/AtlasFooTest.php'],
            'integration_status' => false,
            'downstream_usage'  => [],
            'behavior_evidence' => [],
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->sampler->sample($this->record());

        foreach (['schema', 'task_packet_id', 'value_class', 'confidence', 'signals'] as $key) {
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
}
