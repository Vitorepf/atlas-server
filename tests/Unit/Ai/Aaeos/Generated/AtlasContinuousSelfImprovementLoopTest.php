<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasContinuousSelfImprovementLoopService;
use Tests\TestCase;

/**
 * Pins the documented contract of the Continuous Self-Improvement Loop:
 * the 8 Proposal Requirements, the 5-level promotion ladder (no skipping),
 * the autonomy-is-earned principle, and the proposal-first loop resolution.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
 */
class AtlasContinuousSelfImprovementLoopTest extends TestCase
{
    private function service(): AtlasContinuousSelfImprovementLoopService
    {
        return new AtlasContinuousSelfImprovementLoopService();
    }

    private function completeProposal(array $overrides = []): array
    {
        return array_merge([
            'source_evidence' => 'evidence:run-1',
            'affected_docs_code' => ['docs/x.md'],
            'risk' => 'low',
            'expected_gain' => 'fewer failures',
            'validation' => 'docs-health',
            'rollback' => 'revert block',
            'autonomy_level' => 'suggest_only',
            'reason_not_auto_applied' => 'not yet trusted',
        ], $overrides);
    }

    public function test_complete_proposal_with_all_eight_requirements_is_eligible(): void
    {
        $result = $this->service()->validateProposal($this->completeProposal());

        $this->assertTrue($result['eligible']);
        $this->assertSame(8, $result['satisfied_count']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertSame('eligible_to_advance', $result['decision']);
    }

    public function test_proposal_missing_rollback_and_evidence_is_blocked_with_exact_missing_fields(): void
    {
        $proposal = $this->completeProposal();
        unset($proposal['rollback']);
        $proposal['source_evidence'] = '   '; // blank does not satisfy a requirement

        $result = $this->service()->validateProposal($proposal);

        $this->assertFalse($result['eligible']);
        $this->assertSame(2, $result['missing_count']);
        $this->assertContains('rollback', $result['missing_fields']);
        $this->assertContains('source_evidence', $result['missing_fields']);
        $this->assertSame('blocked_incomplete_proposal', $result['decision']);
    }

    public function test_promotion_ladder_advances_exactly_one_level_when_gate_satisfied(): void
    {
        // proposal -> approved_plan requires the review_passed gate.
        $result = $this->service()->nextPromotionLevel('proposal', ['review_passed']);

        $this->assertTrue($result['advanced']);
        $this->assertSame('approved_plan', $result['next_level']);
        $this->assertSame(2, $result['next_rank']);
        $this->assertSame('review_passed', $result['required_gate']);
    }

    public function test_promotion_never_skips_a_level_and_holds_without_the_gate(): void
    {
        // A 'lead' holding the wrong gate cannot leap to approved_plan; it can
        // at most reach 'proposal', and only with 'evidence_backed'. With an
        // unrelated gate it does not advance at all.
        $result = $this->service()->nextPromotionLevel('lead', ['implemented_and_tested']);

        $this->assertFalse($result['advanced']);
        $this->assertSame('lead', $result['next_level']);
        $this->assertSame('evidence_backed', $result['required_gate']);

        // Top of ladder stays put.
        $top = $this->service()->nextPromotionLevel('promoted_law', ['canonical_docs_and_runtime_gates_green']);
        $this->assertFalse($top['advanced']);
        $this->assertSame('promoted_law', $top['next_level']);
    }

    public function test_autonomy_is_earned_not_assumed_across_successful_cycles(): void
    {
        $svc = $this->service();

        // Zero (and negative) cycles earn no autonomy.
        $this->assertSame('none', $svc->earnedAutonomyLevel(0)['earned_autonomy']);
        $this->assertSame('none', $svc->earnedAutonomyLevel(-5)['earned_autonomy']);

        // Autonomy steps up only at proven thresholds, never jumping bands.
        $this->assertSame('suggest_only', $svc->earnedAutonomyLevel(1)['earned_autonomy']);
        $this->assertSame('assisted_apply', $svc->earnedAutonomyLevel(3)['earned_autonomy']);
        $this->assertSame('assisted_apply', $svc->earnedAutonomyLevel(5)['earned_autonomy']);
        $this->assertSame('auto_apply_low_risk', $svc->earnedAutonomyLevel(6)['earned_autonomy']);
        $this->assertSame('auto_apply_with_audit', $svc->earnedAutonomyLevel(12)['earned_autonomy']);
    }

    public function test_loop_routes_failed_validation_to_rollback_even_when_proposal_complete(): void
    {
        $result = $this->service()->runLoop(
            proposal: $this->completeProposal(['promotion_level' => 'validated_block']),
            satisfiedGates: ['canonical_docs_and_runtime_gates_green'],
            validationPassed: false,
            consecutiveSuccessfulCycles: 20,
        );

        $this->assertTrue($result['proposal_eligible']);
        $this->assertSame('rollback', $result['resolution']);
        $this->assertSame('validation_failed_route_to_rollback', $result['reason']);
    }

    public function test_loop_promotes_when_validated_and_gate_met(): void
    {
        $result = $this->service()->runLoop(
            proposal: $this->completeProposal(['promotion_level' => 'approved_plan']),
            satisfiedGates: ['implemented_and_tested'],
            validationPassed: true,
            consecutiveSuccessfulCycles: 6,
        );

        $this->assertSame('promote', $result['resolution']);
        $this->assertSame('validated_block', $result['promotion']['next_level']);
        // 6 cycles earns auto_apply_low_risk, so promotion needs no human review.
        $this->assertFalse($result['human_review_required']);
    }

    public function test_loop_keeps_human_in_review_when_autonomy_not_yet_earned(): void
    {
        $result = $this->service()->runLoop(
            proposal: $this->completeProposal(['promotion_level' => 'approved_plan']),
            satisfiedGates: ['implemented_and_tested'],
            validationPassed: true,
            consecutiveSuccessfulCycles: 2, // only 'suggest_only' earned
        );

        $this->assertSame('promote', $result['resolution']);
        $this->assertTrue($result['human_review_required']);
    }
}
