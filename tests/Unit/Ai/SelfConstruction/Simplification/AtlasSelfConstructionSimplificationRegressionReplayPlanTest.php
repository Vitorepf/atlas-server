<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationRegressionReplayPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationRegressionReplayPlanTest extends TestCase
{
    private function planner(): AtlasSelfConstructionSimplificationRegressionReplayPlan
    {
        return new AtlasSelfConstructionSimplificationRegressionReplayPlan;
    }

    public function test_every_plan_includes_all_four_check_sections(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA', 'OrganB'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertArrayHasKey('pre_checks', $result);
        $this->assertArrayHasKey('post_checks', $result);
        $this->assertArrayHasKey('replay_checks', $result);
        $this->assertArrayHasKey('acceptance_gates', $result);
        $this->assertNotEmpty($result['pre_checks']);
        $this->assertNotEmpty($result['post_checks']);
        $this->assertNotEmpty($result['replay_checks']);
        $this->assertNotEmpty($result['acceptance_gates']);
    }

    public function test_ready_plan_has_no_not_ready_reasons(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['not_ready_reasons']);
    }

    public function test_plan_without_behavior_equivalence_is_not_ready(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => false,
            'rollback_plan_present' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('behavior_equivalence_not_proven', $result['not_ready_reasons']);
    }

    public function test_plan_without_rollback_coverage_is_not_ready(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'tests_covering_targets' => ['tests/Unit/OrganATest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('rollback_plan_missing', $result['not_ready_reasons']);
    }

    public function test_replay_checks_reference_each_target_organ(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA', 'OrganB'],
        ]);

        $replayChecks = implode('|', $result['replay_checks']);
        $this->assertStringContainsString('OrganA', $replayChecks);
        $this->assertStringContainsString('OrganB', $replayChecks);
    }

    public function test_no_tests_covering_targets_is_a_not_ready_reason(): void
    {
        $result = $this->planner()->compile([
            'target_organs' => ['OrganA'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('no_tests_covering_targets', $result['not_ready_reasons']);
    }
}
