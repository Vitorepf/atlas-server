<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use Tests\TestCase;

final class Maxi08WatchToTrustedGraduationTest extends TestCase
{
    public function test_fresh_watch_never_becomes_trusted_from_boolean_probation_clearance_alone(): void
    {
        $signals = $this->trustedBaseSignals();
        $signals['on_probation'] = false;

        $result = $this->evaluator()->evaluate($signals);

        $this->assertSame('watch', $result['promotion_status']);
        $this->assertSame('pending', $result['gate_statuses']['G8']);
        $this->assertSame(['G8'], $result['pending_gate_ids']);
        $this->assertSame('probation_watch_time_below_calibrated_threshold', $result['reasons']['G8']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
    }

    public function test_negative_feedback_keeps_otherwise_eligible_watch_candidate_on_watch(): void
    {
        $signals = $this->eligibleProbationSignals();
        $signals['probation_negative_feedback_count'] = 1;

        $result = $this->evaluator()->evaluate($signals);

        $this->assertSame('watch', $result['promotion_status']);
        $this->assertSame('pending', $result['gate_statuses']['G8']);
        $this->assertSame('probation_negative_feedback_present', $result['reasons']['G8']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
    }

    public function test_single_actor_inflated_recalls_do_not_graduate_watch_candidate(): void
    {
        $signals = $this->eligibleProbationSignals();
        $signals['probation_recall_actor_counts'] = [
            'interactive:loud-session' => ImmuneCalibrationService::DENOMINATOR_MIN * 10,
            'autonomos:worker-1' => 0,
        ];

        $result = $this->evaluator()->evaluate($signals);

        $this->assertSame('watch', $result['promotion_status']);
        $this->assertSame('pending', $result['gate_statuses']['G8']);
        $this->assertSame('probation_recall_single_actor_inflated', $result['reasons']['G8']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
    }

    public function test_multi_actor_calibrated_watch_evidence_graduates_to_trusted(): void
    {
        $result = $this->evaluator()->evaluate($this->eligibleProbationSignals());

        $this->assertSame('trusted', $result['promotion_status']);
        $this->assertSame('pass', $result['gate_statuses']['G8']);
        $this->assertSame([], $result['pending_gate_ids']);
        $this->assertSame([], $result['blocking_gate_ids']);
        $this->assertTrue($result['autonomous_promotion_allowed']);
    }

    public function test_supervening_contradiction_keeps_probation_candidate_on_watch(): void
    {
        $signals = $this->eligibleProbationSignals();
        $signals['probation_supervening_contradiction_count'] = 1;

        $result = $this->evaluator()->evaluate($signals);

        $this->assertSame('watch', $result['promotion_status']);
        $this->assertSame('pending', $result['gate_statuses']['G8']);
        $this->assertSame('probation_supervening_contradiction_present', $result['reasons']['G8']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
    }

    private function evaluator(): CognitiveImmunePromotionGateEvaluator
    {
        return new CognitiveImmunePromotionGateEvaluator;
    }

    /** @return array<string,mixed> */
    private function eligibleProbationSignals(): array
    {
        return array_merge($this->trustedBaseSignals(), [
            'probation_watch_age_days' => ImmuneCalibrationService::TTL_DAYS,
            'probation_recall_actor_counts' => [
                'interactive:operator' => ImmuneCalibrationService::DENOMINATOR_MIN,
                'autonomos:worker-1' => ImmuneCalibrationService::DENOMINATOR_MIN,
            ],
            'probation_negative_feedback_count' => 0,
            'probation_supervening_contradiction_count' => 0,
        ]);
    }

    /** @return array<string,mixed> */
    private function trustedBaseSignals(): array
    {
        return [
            'consent_granted' => true,
            'privacy_class' => 'internal',
            'retention_ok' => true,
            'atomic_claim_present' => true,
            'claim_type' => 'technical_learning_candidate',
            'claim_source_present' => true,
            'future_utility' => true,
            'novelty' => true,
            'recurrence_count' => 3,
            'provider_safe' => true,
            'contains_secret' => false,
            'contains_sensitive_unnecessary' => false,
            'contradicts_newer' => false,
            'outcome_validated' => true,
            'scope' => 'project',
            'promotion_mode_hint' => 'auto',
            'on_probation' => true,
        ];
    }
}
