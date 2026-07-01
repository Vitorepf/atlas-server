<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionCadenceController;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionCadenceControllerTest extends TestCase
{
    private function controller(): AtlasExternalBrainCompressionCadenceController
    {
        return new AtlasExternalBrainCompressionCadenceController;
    }

    private function calmFacts(array $overrides = []): array
    {
        return array_merge([
            'queue_depth' => 0,
            'claimable_high_value_distinct_count' => 0,
            'worker_pressure' => 0.1,
            'recent_give_back_rate' => 0.0,
            'unmeasured_landed_commits' => 0,
            'pending_learning_deltas' => 0,
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $result = $this->controller()->decide($this->calmFacts());

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::SCHEMA, $result['schema']);
    }

    public function test_output_has_decision_and_reasons(): void
    {
        $result = $this->controller()->decide($this->calmFacts());

        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertNotEmpty($result['reasons']);
    }

    // ── create_batch_case ────────────────────────────────────────────────────

    public function test_high_value_distinct_candidates_trigger_create_batch(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'claimable_high_value_distinct_count' => 3,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_CREATE_BATCH, $result['decision']);
    }

    // ── no_queue_depth_wait_bug_case ─────────────────────────────────────────

    public function test_deep_queue_never_blocks_create_batch_when_high_value_distinct_candidates_exist(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'queue_depth' => 500, // looks very busy
            'claimable_high_value_distinct_count' => 1,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_CREATE_BATCH, $result['decision']);
    }

    public function test_empty_queue_with_no_high_value_candidates_never_creates_batch(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'queue_depth' => 0,
            'claimable_high_value_distinct_count' => 0,
        ]));

        $this->assertNotSame(AtlasExternalBrainCompressionCadenceController::DECISION_CREATE_BATCH, $result['decision']);
    }

    public function test_decision_is_never_the_literal_string_wait(): void
    {
        $scenarios = [
            $this->calmFacts(),
            $this->calmFacts(['queue_depth' => 1000]),
            $this->calmFacts(['claimable_high_value_distinct_count' => 5]),
            $this->calmFacts(['worker_pressure' => 0.95]),
            $this->calmFacts(['unmeasured_landed_commits' => 10]),
            $this->calmFacts(['pending_learning_deltas' => 4]),
        ];

        foreach ($scenarios as $facts) {
            $result = $this->controller()->decide($facts);
            $this->assertNotSame('wait', $result['decision']);
            $this->assertContains($result['decision'], [
                AtlasExternalBrainCompressionCadenceController::DECISION_CREATE_BATCH,
                AtlasExternalBrainCompressionCadenceController::DECISION_PAUSE_FOR_MUSCLES,
                AtlasExternalBrainCompressionCadenceController::DECISION_CONSOLIDATE_LEARNING,
                AtlasExternalBrainCompressionCadenceController::DECISION_MEASURE_REGRESSION,
            ]);
        }
    }

    // ── pause_or_consolidate_case ────────────────────────────────────────────

    public function test_high_worker_pressure_pauses_for_muscles_even_with_high_value_candidates(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'claimable_high_value_distinct_count' => 5,
            'worker_pressure' => 0.9,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_PAUSE_FOR_MUSCLES, $result['decision']);
    }

    public function test_high_give_back_rate_pauses_for_muscles(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'claimable_high_value_distinct_count' => 5,
            'recent_give_back_rate' => 0.6,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_PAUSE_FOR_MUSCLES, $result['decision']);
    }

    public function test_pending_learning_deltas_trigger_consolidate_learning(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'pending_learning_deltas' => 2,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_CONSOLIDATE_LEARNING, $result['decision']);
    }

    public function test_unmeasured_landed_commits_trigger_measure_regression_before_anything_else(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'claimable_high_value_distinct_count' => 5,
            'unmeasured_landed_commits' => 3,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_MEASURE_REGRESSION, $result['decision']);
    }

    public function test_measure_regression_beats_pause_for_muscles(): void
    {
        $result = $this->controller()->decide($this->calmFacts([
            'unmeasured_landed_commits' => 5,
            'worker_pressure' => 0.95,
        ]));

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_MEASURE_REGRESSION, $result['decision']);
    }

    public function test_nothing_actionable_defaults_to_measure_regression(): void
    {
        $result = $this->controller()->decide($this->calmFacts());

        $this->assertSame(AtlasExternalBrainCompressionCadenceController::DECISION_MEASURE_REGRESSION, $result['decision']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $controller = $this->controller();
        $facts = $this->calmFacts(['claimable_high_value_distinct_count' => 2]);

        $this->assertSame($controller->decide($facts), $controller->decide($facts));
    }
}
