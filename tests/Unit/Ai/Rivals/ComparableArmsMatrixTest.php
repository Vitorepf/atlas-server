<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmComparability;
use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\SixArmPlanBuilder;
use Tests\TestCase;

/**
 * Packet 3 comparable-arms matrix: uplift is manufactured whenever arms are not
 * snapshot/budget/version-comparable, the same-model bare control is missing, or a
 * failure mode (rollback) is unrepresented. Each case is RED before the guard exists.
 */
class ComparableArmsMatrixTest extends TestCase
{
    public function test_six_arm_plan_builds_available_arms_and_marks_human_baseline_unavailable(): void
    {
        $plan = (new SixArmPlanBuilder)->build([
            'same_model' => 'local_fake_model',
            'competitor_model' => 'codex_gpt_5_5',
            'frontier_model' => 'gemini',
        ]);

        $roles = array_column($plan['arms'], 'arm_role');
        $this->assertContains(SixArmPlanBuilder::ROLE_SAME_MODEL_BARE, $roles);
        $this->assertContains(SixArmPlanBuilder::ROLE_SAME_MODEL_ATLAS, $roles);
        $this->assertContains(SixArmPlanBuilder::ROLE_COMPETITOR_NATIVE, $roles);
        $this->assertContains(SixArmPlanBuilder::ROLE_FRONTIER_BARE, $roles);
        $this->assertContains(SixArmPlanBuilder::ROLE_ATLAS_FULL_POWER, $roles);

        // arm_ids are distinct so the adjudicator's exact-set equality holds
        $armIds = array_column($plan['arms'], 'arm_id');
        $this->assertSame($armIds, array_unique($armIds));

        // absent accepted human artifact is recorded unavailable, never blocking
        $unavailableRoles = array_column($plan['unavailable'], 'arm_role');
        $this->assertContains(SixArmPlanBuilder::ROLE_HISTORICAL_HUMAN, $unavailableRoles);
    }

    public function test_missing_competitor_and_frontier_degrade_without_blocking(): void
    {
        $plan = (new SixArmPlanBuilder)->build(['same_model' => 'local_fake_model']);

        $roles = array_column($plan['arms'], 'arm_role');
        $this->assertContains(SixArmPlanBuilder::ROLE_SAME_MODEL_BARE, $roles);
        $unavailableRoles = array_column($plan['unavailable'], 'arm_role');
        $this->assertContains(SixArmPlanBuilder::ROLE_COMPETITOR_NATIVE, $unavailableRoles);
        $this->assertContains(SixArmPlanBuilder::ROLE_FRONTIER_BARE, $unavailableRoles);
    }

    public function test_missing_same_model_bare_control_is_flagged(): void
    {
        // a plan with only an atlas arm cannot prove causal uplift
        $violations = (new ArmComparability)->audit([
            'arms' => [
                ['arm_id' => 'm@atlas_dev', 'arm_role' => SixArmPlanBuilder::ROLE_SAME_MODEL_ATLAS, 'provider_version' => 'v1'],
            ],
        ]);
        $this->assertContains('missing_same_model_bare_control', $violations);
    }

    public function test_unpinned_provider_version_is_flagged(): void
    {
        $violations = (new ArmComparability)->audit([
            'arms' => [
                ['arm_id' => 'm@bare', 'arm_role' => SixArmPlanBuilder::ROLE_SAME_MODEL_BARE, 'provider_version' => null],
                ['arm_id' => 'm@atlas_dev', 'arm_role' => SixArmPlanBuilder::ROLE_SAME_MODEL_ATLAS, 'provider_version' => 'v1'],
            ],
        ]);
        $this->assertContains('arm_provider_version_unpinned:m@bare', $violations);
    }

    public function test_unequal_arm_budgets_are_flagged(): void
    {
        $violations = (new ArmComparability)->assertEqualBudgets([
            'm@bare' => ['max_usd' => 1.0, 'max_minutes' => 10, 'tools' => 'std', 'egress' => 'deny'],
            'm@atlas_dev' => ['max_usd' => 5.0, 'max_minutes' => 10, 'tools' => 'std', 'egress' => 'deny'],
        ]);
        $this->assertContains('arm_budget_unequal:m@atlas_dev', $violations);
    }

    public function test_equal_arm_budgets_pass(): void
    {
        $budget = ['max_usd' => 1.0, 'max_minutes' => 10, 'tools' => 'std', 'egress' => 'deny'];
        $violations = (new ArmComparability)->assertEqualBudgets([
            'm@bare' => $budget,
            'm@atlas_dev' => $budget,
        ]);
        $this->assertSame([], $violations);
    }

    public function test_rollback_is_a_representable_failure_class(): void
    {
        $this->assertContains(FailureClass::ROLLBACK, FailureClass::all());
    }
}
