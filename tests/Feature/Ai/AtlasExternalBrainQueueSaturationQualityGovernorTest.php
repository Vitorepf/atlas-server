<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationQualityGovernor;
use Tests\TestCase;

final class AtlasExternalBrainQueueSaturationQualityGovernorTest extends TestCase
{
    public function test_high_malformed_rate_forces_repair_specs_with_zero_new_tasks(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationQualityGovernor)->decide([
            'servable_depth' => 2,
            'active_workers' => 2,
            'malformed_rate' => 0.5,
        ]);

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $result['decision']);
        $this->assertSame(0, $result['max_new_tasks']);
    }

    public function test_high_give_back_rate_forces_repair_specs_with_zero_new_tasks(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationQualityGovernor)->decide([
            'servable_depth' => 2,
            'active_workers' => 2,
            'give_back_rate' => 0.4,
        ]);

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $result['decision']);
        $this->assertSame(0, $result['max_new_tasks']);
    }

    public function test_high_collision_risk_forces_repair_specs_with_zero_new_tasks(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationQualityGovernor)->decide([
            'servable_depth' => 2,
            'active_workers' => 2,
            'collision_risk' => 0.5,
        ]);

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_REPAIR_SPECS_BEFORE_CREATION, $result['decision']);
        $this->assertSame(0, $result['max_new_tasks']);
    }

    public function test_shallow_healthy_queue_with_enough_leverage_evidence_creates_high_value_batch(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationQualityGovernor)->decide([
            'servable_depth' => 1,
            'active_workers' => 2,
            'malformed_rate' => 0.0,
            'give_back_rate' => 0.0,
            'target_diversity' => 0.9,
            'leverage_evidence_density' => 0.8,
        ]);

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_CREATE_HIGH_VALUE_BATCH, $result['decision']);
        $this->assertGreaterThan(0, $result['max_new_tasks']);
    }

    public function test_deep_low_diversity_queue_returns_quality_review_or_pause_not_terminal_stop(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationQualityGovernor)->decide([
            'servable_depth' => 50,
            'active_workers' => 2,
            'malformed_rate' => 0.0,
            'give_back_rate' => 0.0,
            'target_diversity' => 0.1,
        ]);

        $this->assertSame(AtlasExternalBrainQueueSaturationQualityGovernor::DECISION_QUALITY_REVIEW_OR_PAUSE, $result['decision']);
        $this->assertSame('review', $result['stop_go_decision']);
        $this->assertNotSame('stop', $result['stop_go_decision']);
    }
}
