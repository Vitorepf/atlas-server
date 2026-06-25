<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueDecisionPolicy;
use Tests\TestCase;

final class AtlasGoalValueDecisionPolicyTest extends TestCase
{
    private function leverageGood(): array
    {
        return ['real_leverage' => true, 'proxy_only' => false, 'blockers' => []];
    }

    private function leverageBad(): array
    {
        return ['real_leverage' => false, 'proxy_only' => false, 'blockers' => ['capability_lift:no_evidence']];
    }

    private function proxyOnlyLeverage(): array
    {
        return ['real_leverage' => false, 'proxy_only' => true, 'blockers' => []];
    }

    private function gateOk(): array
    {
        return ['blocked' => false, 'blocked_proxy_categories' => []];
    }

    private function gateBlocked(): array
    {
        return ['blocked' => true, 'blocked_proxy_categories' => ['line_churn', 'task_count']];
    }

    private function verificationGreen(): array
    {
        return ['passed' => true, 'color' => 'green', 'evidence' => ['phpunit:exit_0']];
    }

    private function verificationRed(): array
    {
        return ['passed' => false, 'color' => 'red', 'evidence' => ['phpunit:exit_1']];
    }

    private function verificationAmber(): array
    {
        return ['passed' => false, 'color' => 'amber', 'evidence' => []];
    }

    public function test_promote_when_real_leverage_and_green_verification(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->leverageGood(),
            $this->gateOk(),
            $this->verificationGreen(),
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_PROMOTE, $verdict['decision']);
        $this->assertContains('promotion_criteria_met', $verdict['reasons']);
        $this->assertSame([], $verdict['next_required_evidence']);
    }

    public function test_reject_when_anti_proxy_gate_blocks(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->leverageGood(),
            $this->gateBlocked(),
            $this->verificationGreen(),
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('anti_proxy_gate_blocked', $verdict['reasons']);
        $this->assertContains('proxy_category:line_churn', $verdict['reasons']);
        $this->assertContains('concrete_capability_or_failure_removal_evidence', $verdict['next_required_evidence']);
    }

    public function test_reject_when_leverage_proxy_only_even_if_gate_clean(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->proxyOnlyLeverage(),
            $this->gateOk(),
            $this->verificationGreen(),
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('leverage_contract_proxy_only', $verdict['reasons']);
    }

    public function test_reject_when_verification_red(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->leverageGood(),
            $this->gateOk(),
            $this->verificationRed(),
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REJECT, $verdict['decision']);
        $this->assertContains('verification_red', $verdict['reasons']);
        $this->assertContains('verification:phpunit:exit_1', $verdict['reasons']);
        $this->assertContains('fix_failing_verification_then_resubmit', $verdict['next_required_evidence']);
    }

    public function test_learn_when_verification_amber(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->leverageGood(),
            $this->gateOk(),
            $this->verificationAmber(),
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_LEARN, $verdict['decision']);
        $this->assertContains('verification_amber_insufficient_evidence', $verdict['reasons']);
        $this->assertContains('record_learning_candidate_for_future_corroboration', $verdict['next_required_evidence']);
    }

    public function test_revise_when_leverage_not_yet_established_with_clean_gate(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->leverageBad(),
            $this->gateOk(),
            $this->verificationGreen(),
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REVISE, $verdict['decision']);
        $this->assertContains('real_leverage_not_yet_established', $verdict['reasons']);
        $this->assertContains('leverage:capability_lift:no_evidence', $verdict['reasons']);
        $this->assertContains('add_missing_dimension_evidence', $verdict['next_required_evidence']);
    }

    public function test_revise_when_verification_color_unknown(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
            $this->leverageGood(),
            $this->gateOk(),
            ['passed' => true, 'color' => 'unknown'],
        );

        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REVISE, $verdict['decision']);
        $this->assertContains('verification_status_unknown', $verdict['reasons']);
    }
}
