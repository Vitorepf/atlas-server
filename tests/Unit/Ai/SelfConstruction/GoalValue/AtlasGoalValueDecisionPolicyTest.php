<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueDecisionPolicy;
use Tests\TestCase;

final class AtlasGoalValueDecisionPolicyTest extends TestCase
{
    private function leverageGood(): array
    {
        return [
            'real_leverage'                    => true,
            'proxy_only'                       => false,
            'blockers'                         => [],
            'implementation_evidence_refs'     => ['phpunit:exit_0'],
            'downstream_consumer_evidence_refs' => ['consumer:verified'],
            'compounding_evidence_refs'        => ['compounding:self_construction'],
        ];
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

    public function test_promote_impossible_when_implementation_evidence_refs_empty(): void
    {
        $leverage = array_replace($this->leverageGood(), ['implementation_evidence_refs' => []]);
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide($leverage, $this->gateOk(), $this->verificationGreen());

        $this->assertNotSame(AtlasGoalValueDecisionPolicy::DECISION_PROMOTE, $verdict['decision']);
        $this->assertContains('implementation_evidence_refs_empty', $verdict['reasons']);
        $this->assertContains('attach_implementation_evidence_refs', $verdict['next_required_evidence']);
    }

    public function test_promote_impossible_when_operator_human_provider_approved(): void
    {
        $forbidden = ['operator_approved', 'human_approved', 'provider_approved', 'claude_code_approved', 'codex_approved', 'cursor_approved'];
        foreach ($forbidden as $key) {
            $verdict = (new AtlasGoalValueDecisionPolicy)->decide(
                $this->leverageGood(),
                $this->gateOk(),
                $this->verificationGreen(),
                [$key => true],
            );
            $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REJECT, $verdict['decision'], "$key must cause reject");
            $this->assertContains('finality_forbidden:'.$key, $verdict['reasons']);
        }
    }

    // --- compounding proof floor ---

    public function test_green_with_real_leverage_but_no_downstream_refs_yields_revise(): void
    {
        $lev = array_replace($this->leverageGood(), ['downstream_consumer_evidence_refs' => []]);
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide($lev, $this->gateOk(), $this->verificationGreen());
        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REVISE, $verdict['decision']);
        $this->assertContains('downstream_consumer_evidence_refs_empty', $verdict['reasons']);
        $this->assertContains('attach_downstream_consumer_evidence_refs', $verdict['next_required_evidence']);
    }

    public function test_green_with_real_leverage_but_no_compounding_or_autonomy_refs_yields_revise(): void
    {
        $lev = array_replace($this->leverageGood(), ['compounding_evidence_refs' => [], 'autonomy_unlock_evidence_refs' => []]);
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide($lev, $this->gateOk(), $this->verificationGreen());
        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_REVISE, $verdict['decision']);
        $this->assertContains('compounding_or_autonomy_unlock_evidence_required', $verdict['reasons']);
        $this->assertContains('attach_compounding_or_autonomy_unlock_evidence_refs', $verdict['next_required_evidence']);
    }

    public function test_autonomy_unlock_refs_alone_satisfy_compounding_floor(): void
    {
        $lev = array_replace($this->leverageGood(), [
            'compounding_evidence_refs' => [],
            'autonomy_unlock_evidence_refs' => ['autonomy:loop_enabled'],
        ]);
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide($lev, $this->gateOk(), $this->verificationGreen());
        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_PROMOTE, $verdict['decision']);
    }

    public function test_promote_requires_all_three_evidence_families(): void
    {
        $lev = $this->leverageGood(); // has all three
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide($lev, $this->gateOk(), $this->verificationGreen());
        $this->assertSame(AtlasGoalValueDecisionPolicy::DECISION_PROMOTE, $verdict['decision']);
        $this->assertContains('promotion_criteria_met', $verdict['reasons']);
        $this->assertSame([], $verdict['next_required_evidence']);
    }

    public function test_decisions_are_deterministic(): void
    {
        $svc = new AtlasGoalValueDecisionPolicy;
        $a = $svc->decide($this->leverageGood(), $this->gateOk(), $this->verificationGreen());
        $b = $svc->decide($this->leverageGood(), $this->gateOk(), $this->verificationGreen());
        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_no_scalar_value_score_in_output(): void
    {
        $verdict = (new AtlasGoalValueDecisionPolicy)->decide($this->leverageGood(), $this->gateOk(), $this->verificationGreen());
        $json = (string) json_encode($verdict);
        $this->assertDoesNotMatchRegularExpression('/"(score|grade|percent|magnitude|value_score)"/i', $json);
    }
}
