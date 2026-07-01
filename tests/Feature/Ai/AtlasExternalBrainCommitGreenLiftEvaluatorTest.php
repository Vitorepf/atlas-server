<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCommitGreenLiftEvaluator;
use Tests\TestCase;

final class AtlasExternalBrainCommitGreenLiftEvaluatorTest extends TestCase
{
    private function evaluator(): AtlasExternalBrainCommitGreenLiftEvaluator
    {
        return new AtlasExternalBrainCommitGreenLiftEvaluator;
    }

    public function test_commit_with_no_value_signal_is_low_lift_cosmetic(): void
    {
        $r = $this->evaluator()->evaluateCommit([]);

        self::assertSame('low_lift_cosmetic', $r['verdict']);
        self::assertTrue($r['is_low_lift']);
        self::assertSame(0, $r['signal_count']);
    }

    public function test_commit_with_capability_lift_evidence_is_real_value_lift(): void
    {
        $r = $this->evaluator()->evaluateCommit(['capability_lift_evidence' => ['new capability proven']]);

        self::assertSame('real_value_lift', $r['verdict']);
        self::assertContains('capability_lift_confirmed_author_more_in_this_family', $r['learning_feedback']);
    }

    public function test_commit_with_downstream_unlock_is_real_value_lift(): void
    {
        $r = $this->evaluator()->evaluateCommit(['downstream_unlocks' => ['app/Console/Commands/Foo.php']]);

        self::assertSame('real_value_lift', $r['verdict']);
        self::assertContains('downstream_tasks_unlocked_prioritize_them_next_cycle', $r['learning_feedback']);
    }

    public function test_commit_with_simplification_delta_is_real_value_lift(): void
    {
        $r = $this->evaluator()->evaluateCommit(['simplification_delta' => 0.3]);

        self::assertSame('real_value_lift', $r['verdict']);
        self::assertContains('simplification_reduced_complexity_reward_this_pattern', $r['learning_feedback']);
    }

    public function test_commit_with_risk_reduction_evidence_is_real_value_lift(): void
    {
        $r = $this->evaluator()->evaluateCommit(['risk_reduction_evidence' => ['fixed a race condition']]);

        self::assertSame('real_value_lift', $r['verdict']);
        self::assertContains('risk_reduction_evidenced_reinforce_this_safety_pattern', $r['learning_feedback']);
    }

    public function test_batch_green_rate_increase_with_non_positive_impact_lift_is_regression(): void
    {
        $r = $this->evaluator()->evaluate([
            'before' => [['status' => 'give_back', 'impact_weight' => 0.0]],
            'after' => [['status' => 'green_commit', 'impact_weight' => 0.0]],
        ]);

        self::assertSame('regression', $r['verdict']);
        self::assertGreaterThan(0, $r['green_commit_rate_delta']);
        self::assertLessThanOrEqual(0, $r['impact_weighted_lift']);
    }

    public function test_batch_give_back_rate_material_worsening_is_regression(): void
    {
        $r = $this->evaluator()->evaluate([
            'before' => [
                ['status' => 'green_commit', 'impact_weight' => 0.5],
                ['status' => 'green_commit', 'impact_weight' => 0.5],
            ],
            'after' => [
                ['status' => 'green_commit', 'impact_weight' => 0.5],
                ['status' => 'give_back', 'impact_weight' => 0.5],
            ],
        ]);

        self::assertSame('regression', $r['verdict']);
        self::assertGreaterThan(0.05, $r['give_back_rate_delta']);
    }

    public function test_batch_real_improvement_when_green_rate_and_impact_both_rise(): void
    {
        $r = $this->evaluator()->evaluate([
            'before' => [['status' => 'failed', 'impact_weight' => 0.0]],
            'after' => [['status' => 'green_commit', 'impact_weight' => 0.8]],
        ]);

        self::assertSame('improvement', $r['verdict']);
    }
}
