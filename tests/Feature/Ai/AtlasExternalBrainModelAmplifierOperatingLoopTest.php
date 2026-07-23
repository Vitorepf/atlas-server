<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelAmplifierOperatingLoop;
use Tests\TestCase;

final class AtlasExternalBrainModelAmplifierOperatingLoopTest extends TestCase
{
    public function test_default_steady_state_runs_small_with_frontier_not_required(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->decide([]);

        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::DECISION_RUN_SMALL, $result['decision']);
        $this->assertFalse($result['frontier_required']);
    }

    public function test_proxy_leak_detected_repairs_scaffold(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->decide([
            'proxy_leak_detected' => true,
        ]);

        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::DECISION_REPAIR_SCAFFOLD, $result['decision']);
    }

    public function test_scaffold_repair_signal_repairs_scaffold(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->decide([
            'scaffold_evidence' => ['repair_signal' => true],
        ]);

        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::DECISION_REPAIR_SCAFFOLD, $result['decision']);
    }

    public function test_scaffold_retire_signal_retires_scaffold(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->decide([
            'scaffold_evidence' => ['retire_signal' => true],
        ]);

        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::DECISION_RETIRE_SCAFFOLD, $result['decision']);
    }

    public function test_run_operating_loop_blocks_promotion_when_lift_evidence_is_self_declared(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->runOperatingLoop([
            'candidate_scaffolds' => [
                ['id' => 'a', 'lift_evidence_present' => true, 'lift_evidence_self_declared' => true, 'observed_lift' => 0.5],
            ],
        ]);

        $this->assertTrue($result['promotion_blocked']);
        $this->assertSame('keep_testing', $result['lifecycle_decision']);
    }

    public function test_run_operating_loop_promotes_only_when_runtime_confirmed_lift_meets_threshold(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->runOperatingLoop([
            'candidate_scaffolds' => [
                ['id' => 'a', 'lift_evidence_present' => true, 'lift_evidence_self_declared' => false, 'observed_lift' => 0.20],
            ],
        ]);

        $this->assertFalse($result['promotion_blocked']);
        $this->assertSame('promote', $result['lifecycle_decision']);
    }

    public function test_run_operating_loop_selects_only_task_quality_scaffolds_under_low_worker_supply(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->runOperatingLoop([
            'servable_now' => 5,
            'active_leases' => 2,
            'candidate_scaffolds' => [
                ['id' => 'research', 'lift_evidence_present' => true, 'observed_lift' => 0.9, 'improves_task_quality' => false],
                ['id' => 'quality', 'lift_evidence_present' => true, 'observed_lift' => 0.2, 'improves_task_quality' => true],
            ],
        ]);

        $this->assertSame('quality', $result['selected_scaffold_id']);
    }

    public function test_run_operating_loop_defers_when_low_supply_and_no_task_quality_candidate(): void
    {
        $result = (new AtlasExternalBrainModelAmplifierOperatingLoop)->runOperatingLoop([
            'servable_now' => 5,
            'active_leases' => 2,
            'candidate_scaffolds' => [
                ['id' => 'research', 'lift_evidence_present' => true, 'observed_lift' => 0.9, 'improves_task_quality' => false],
            ],
        ]);

        $this->assertNull($result['selected_scaffold_id']);
        $this->assertSame('deferred_low_worker_feed_supply', $result['lifecycle_decision']);
        $this->assertTrue($result['promotion_blocked']);
    }
}
