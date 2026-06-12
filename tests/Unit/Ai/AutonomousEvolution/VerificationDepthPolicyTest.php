<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\VerificationDepthPolicy as P;
use PHPUnit\Framework\TestCase;

/**
 * O-9: proof depth is proportional to risk AND encodes the two merge-livre exceptions —
 * judge/gate/immune code is always reinforced; autonomy-widening always needs the key.
 */
final class VerificationDepthPolicyTest extends TestCase
{
    private function policy(): P
    {
        return new P();
    }

    public function test_trivial_change_floor_is_just_a_frozen_test(): void
    {
        $r = $this->policy()->obligationsFor('docs', 'low');
        $this->assertSame([P::FROZEN_TEST], $r['obligations']);
        $this->assertSame(0, $r['refuter_count']);
        $this->assertFalse($r['requires_operator_key']);
    }

    public function test_non_trivial_code_adds_adversarial_review(): void
    {
        $r = $this->policy()->obligationsFor('code', 'low');
        $this->assertContains(P::FROZEN_TEST, $r['obligations']);
        $this->assertContains(P::ADVERSARIAL_REVIEW, $r['obligations']);
    }

    public function test_high_risk_adds_independent_refuters(): void
    {
        $r = $this->policy()->obligationsFor('code', 'high');
        $this->assertContains(P::INDEPENDENT_REFUTERS, $r['obligations']);
        $this->assertSame(2, $r['refuter_count']);

        $crit = $this->policy()->obligationsFor('code', 'critical');
        $this->assertSame(3, $crit['refuter_count']);
    }

    public function test_judge_surface_is_always_reinforced_even_at_low_risk(): void
    {
        // Exception 1: a broken judge silently blinds fix-forward — always reinforced.
        foreach (['gate', 'judge', 'immune', 'measurement', 'eval_gate', 'verification'] as $cls) {
            $r = $this->policy()->obligationsFor($cls, 'low');
            $this->assertContains(P::ADVERSARIAL_REVIEW, $r['obligations'], "{$cls} must get adversarial review");
            $this->assertContains(P::INDEPENDENT_REFUTERS, $r['obligations'], "{$cls} must get refuters");
            $this->assertGreaterThanOrEqual(3, $r['refuter_count']);
        }
    }

    public function test_autonomy_widening_always_requires_the_operator_key(): void
    {
        // Exception 2: the system never grants itself more autonomy.
        foreach (['autonomy', 'autonomy_widening', 'policy', 'merge_policy'] as $cls) {
            $r = $this->policy()->obligationsFor($cls, 'low');
            $this->assertTrue($r['requires_operator_key'], "{$cls} must require the operator key");
            $this->assertContains(P::OPERATOR_KEY, $r['obligations']);
        }
    }
}
