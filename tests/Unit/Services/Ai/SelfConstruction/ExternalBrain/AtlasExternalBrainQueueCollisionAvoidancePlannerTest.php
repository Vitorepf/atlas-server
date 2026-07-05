<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueCollisionAvoidancePlanner;
use Tests\TestCase;

final class AtlasExternalBrainQueueCollisionAvoidancePlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainQueueCollisionAvoidancePlanner
    {
        return new AtlasExternalBrainQueueCollisionAvoidancePlanner;
    }

    // ── AC: critical collisions block the exact target ──

    public function test_critical_collision_blocks_exact_target(): void
    {
        $result = $this->planner()->plan([
            'collisions' => [
                ['target_family' => 'refactor', 'severity' => 'critical', 'reason' => 'already_queued'],
            ],
            'candidate_families' => ['refactor', 'testing', 'implementation'],
        ]);

        $this->assertContains('refactor', $result['denylist']);
        $this->assertNotContains('refactor', $result['eligible_families']);
    }

    // ── AC: warnings require pivot recommendations ──

    public function test_warning_collision_produces_pivot_recommendation(): void
    {
        $result = $this->planner()->plan([
            'collisions' => [
                ['target_family' => 'frontend', 'severity' => 'warning', 'reason' => 'high_queue_pressure'],
            ],
            'candidate_families' => ['frontend', 'backend'],
        ]);

        $this->assertContains('frontend', $result['pivot_recommendations']);
        $this->assertNotContains('frontend', $result['denylist']);
    }

    // ── AC: unrelated targets remain eligible ──

    public function test_unrelated_targets_remain_eligible(): void
    {
        $result = $this->planner()->plan([
            'collisions' => [
                ['target_family' => 'refactor', 'severity' => 'critical', 'reason' => 'already_queued'],
                ['target_family' => 'frontend', 'severity' => 'warning', 'reason' => 'high_pressure'],
            ],
            'candidate_families' => ['refactor', 'frontend', 'backend', 'testing'],
        ]);

        $this->assertContains('backend', $result['eligible_families']);
        $this->assertContains('testing', $result['eligible_families']);
        $this->assertNotContains('refactor', $result['eligible_families']);
        $this->assertNotContains('frontend', $result['eligible_families']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertSame(AtlasExternalBrainQueueCollisionAvoidancePlanner::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('denylist', $result);
        $this->assertArrayHasKey('pivot_recommendations', $result);
        $this->assertArrayHasKey('eligible_families', $result);
        $this->assertArrayHasKey('collision_reasons', $result);
    }

    public function test_empty_input_produces_empty_lists(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertSame([], $result['denylist']);
        $this->assertSame([], $result['pivot_recommendations']);
        $this->assertSame([], $result['eligible_families']);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'collisions' => [
                ['target_family' => 'b', 'severity' => 'critical', 'reason' => 'x'],
                ['target_family' => 'a', 'severity' => 'warning', 'reason' => 'y'],
            ],
            'candidate_families' => ['c', 'a', 'b'],
        ];

        $a = $this->planner()->plan($input);
        $b = $this->planner()->plan($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_collision_reasons_preserved(): void
    {
        $result = $this->planner()->plan([
            'collisions' => [
                ['target_family' => 'refactor', 'severity' => 'critical', 'reason' => 'already_queued_3_times'],
            ],
        ]);

        $this->assertSame('already_queued_3_times', $result['collision_reasons']['refactor']);
    }
}
