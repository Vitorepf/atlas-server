<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use App\Services\Ai\Compounding\CausalLearningPromotionService;
use App\Services\Ai\Compounding\CausalLearningVerdict;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CausalLearningPromotionTest extends TestCase
{
    public function test_reversible_promotion_is_scoped_and_expiring(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $service = new CausalLearningPromotionService;
        $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2');

        self::assertSame('promoted', $promotion->status);
        self::assertSame('atlas.route', $promotion->scope);
        self::assertSame('route-v1', $promotion->rollbackVersion);
        self::assertFalse($promotion->claimEligible);
    }

    public function test_adverse_late_outcome_revokes_and_restores_previous_version(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $service = new CausalLearningPromotionService;
        $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2');

        $revoked = $service->revoke($promotion, 'late_regression');

        self::assertSame('revoked', $revoked->status);
        self::assertSame('route-v1', $revoked->activeVersion);
    }

    public function test_code_task_cannot_be_promoted_as_policy(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), ['change_class' => 'code_task']));

        $this->expectException(InvalidArgumentException::class);
        (new CausalLearningPromotionService)->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2');
    }

    public function test_verdict_from_another_candidate_cannot_be_reused(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $other = CausalLearningCandidate::fromArray(array_replace($this->candidate(), ['scope' => 'other.scope']));
        $verdict = (new CausalLearningGate)->adjudicate($other);

        $this->expectExceptionMessage('causal_verdict_stale_or_mismatched');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2');
    }

    public function test_claim_eligibility_cannot_be_escalated_by_learning_layer(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $gateVerdict = (new CausalLearningGate)->adjudicate($candidate);
        $verdict = new CausalLearningVerdict($gateVerdict->verdict, $gateVerdict->reason, $gateVerdict->decisionHash, true);

        $this->expectExceptionMessage('causal_claim_authority_escalation');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2');
    }

    public function test_expired_candidate_cannot_be_promoted_even_with_a_stale_green_verdict(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'expiry' => '2026-07-01T00:00:00Z',
        ]));
        $verdict = new CausalLearningVerdict(
            'promote_reversible',
            'causal_evidence_admitted',
            \App\Services\Ai\Compounding\CompoundingHash::make([
                'candidate' => $candidate->candidateHash,
                'verdict' => 'promote_reversible',
                'reason' => 'causal_evidence_admitted',
            ]),
        );

        $this->expectExceptionMessage('causal_policy_promotion_expired');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2');
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'assignment_hash' => str_repeat('b', 64), 'experiment_hash' => str_repeat('c', 64), 'order_hash' => str_repeat('7', 64),
            'run_hash' => str_repeat('d', 64), 'release_hash' => str_repeat('e', 64), 'outcome_hash' => str_repeat('f', 64),
            'change_class' => 'routing', 'hypothesis' => 'route improves quality', 'baseline' => 'route-v1', 'metric' => 'quality',
            'window' => '7d', 'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.3, 'confounders' => ['provider' => 'controlled'],
            'rollback' => 'route-v1', 'reversible' => true, 'assignment_precedes_run' => true, 'real_outcome' => true,
            'authority_hash' => str_repeat('1', 64), 'scope' => 'atlas.route', 'expiry' => '2026-08-01T00:00:00Z',
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z',
            'run_at' => '2026-07-12T00:20:00Z', 'outcome_at' => '2026-07-12T01:00:00Z',
        ];
    }
}
