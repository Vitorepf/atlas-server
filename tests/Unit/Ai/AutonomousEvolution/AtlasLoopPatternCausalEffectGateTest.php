<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternCausalEffectGate as Gate;
use PHPUnit\Framework\TestCase;

/**
 * KEYSTONE #4 causal gate — the brain's compounding becomes RIGOROUS: a pattern is "proven" only when its
 * uplift vs the pooled baseline has a CI that excludes zero (and is positive). These freeze the math + the
 * anti-fabrication property: a high but NOISY mean is NOT proven (the exact "fabricated compounding" trap).
 */
final class AtlasLoopPatternCausalEffectGateTest extends TestCase
{
    /** @param array<int,float> $treat @param array<int,float> $control @return list<array{pattern_id:string,value:float}> */
    private function pairs(array $treat, array $control): array
    {
        $out = [];
        foreach ($treat as $v) {
            $out[] = ['pattern_id' => 'A', 'value' => (float) $v];
        }
        foreach ($control as $v) {
            $out[] = ['pattern_id' => 'B', 'value' => (float) $v];
        }

        return $out;
    }

    public function test_consistent_uplift_is_proven(): void
    {
        $pairs = $this->pairs([1.0, 1.0, 0.9, 1.0, 0.95], [0.0, 0.1, 0.0, 0.05, 0.0]);
        $e = Gate::effectFor($pairs, 'A');
        self::assertTrue($e['proven'], 'a large, consistent uplift must clear the CI gate');
        self::assertSame('compounding_proven', $e['reason']);
        self::assertGreaterThan(0.0, (float) $e['ci_low']);
    }

    public function test_high_but_noisy_mean_is_no_t_proven(): void
    {
        // mean(A)=0.5 == mean(B)=0.5 but A swings wildly — the fabricated-compounding trap. CI straddles 0.
        $pairs = $this->pairs([1.0, 0.0, 1.0, 0.0, 1.0, 0.0], [0.5, 0.5, 0.5, 0.5, 0.5, 0.5]);
        self::assertFalse(Gate::isCompoundingProven($pairs, 'A'));
    }

    public function test_no_effect_is_not_proven(): void
    {
        $pairs = $this->pairs([0.5, 0.5, 0.5, 0.5], [0.5, 0.5, 0.5, 0.5]);
        $e = Gate::effectFor($pairs, 'A');
        self::assertFalse($e['proven']);
        self::assertSame('ci_includes_zero_or_negative', $e['reason']);
    }

    public function test_insufficient_samples_is_never_proven(): void
    {
        $e = Gate::effectFor($this->pairs([1.0, 1.0], [0.0, 0.0, 0.0, 0.0]), 'A', Gate::DEFAULT_Z, 3);
        self::assertFalse($e['proven']);
        self::assertSame('insufficient_evidence', $e['reason']);
        self::assertNull($e['effect']);
    }

    public function test_negative_effect_is_not_proven(): void
    {
        // A is WORSE than baseline — must never be "proven" (gate is positive-uplift only).
        $pairs = $this->pairs([0.0, 0.0, 0.1, 0.0], [1.0, 0.9, 1.0, 1.0]);
        self::assertFalse(Gate::isCompoundingProven($pairs, 'A'));
    }

    public function test_pairs_from_uses_measured_outcome_then_success_binary(): void
    {
        $pairs = Gate::pairsFrom([
            ['pattern_id' => 'P', 'measured_outcome' => 0.7, 'result' => 'failure'], // numeric wins
            ['pattern_id' => 'P', 'measured_outcome' => null, 'result' => 'success'], // fallback → 1.0
            ['pattern_id' => 'P', 'measured_outcome' => null, 'result' => 'failure'], // fallback → 0.0
            ['pattern_id' => '', 'measured_outcome' => 1.0], // no id → skipped
        ]);
        self::assertSame(
            [['pattern_id' => 'P', 'value' => 0.7], ['pattern_id' => 'P', 'value' => 1.0], ['pattern_id' => 'P', 'value' => 0.0]],
            $pairs,
        );
    }
}
