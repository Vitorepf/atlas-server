<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExpectedValueDecider;
use Tests\TestCase;

/**
 * EXPECTED-VALUE / BOTTLENECK decider — frozen proof of the explicit math: the pick is argmax of
 * EV = P_success(class, Bayesian) · value · reliefMultiplier(bottleneck) − cost, so (1) a high-VALUE
 * candidate with low landing PROBABILITY loses to a moderate-value high-probability one (decision
 * theory, not raw value); (2) work relieving the BINDING grade axis (theory of constraints) outranks
 * equal-value work that does not; (3) P_success is Bayesian and calibrates with observed soak results;
 * (4) the receipt never claims optimality.
 */
final class AtlasLoopExpectedValueDeciderTest extends TestCase
{
    private function decider(): AtlasLoopExpectedValueDecider
    {
        return new AtlasLoopExpectedValueDecider();
    }

    public function test_expected_value_beats_raw_value_when_landing_probability_differs(): void
    {
        $result = $this->decider()->decide(
            [
                ['candidateId' => 'shiny_but_risky', 'class' => 'risky', 'value' => 100, 'node_count' => 2],
                ['candidateId' => 'modest_but_proven', 'class' => 'proven', 'value' => 60, 'node_count' => 2],
            ],
            [
                'axis_values' => ['wired' => 0.9, 'real_target' => 0.9, 'non_trivial' => 0.9, 'compounding' => 0.9, 'safety' => 0.9],
                'class_stats' => ['risky' => ['successes' => 0, 'failures' => 9], 'proven' => ['successes' => 9, 'failures' => 0]],
            ],
        );
        $this->assertSame('modest_but_proven', $result['winner']['candidateId'], 'EV (P×value) decides, not raw value');
        // Bayesian Laplace: proven 10/11 ≈ 0.909, risky 1/11 ≈ 0.0909.
        $this->assertSame(0.9091, $result['winner']['p_success']);
    }

    public function test_work_relieving_the_binding_bottleneck_axis_outranks_equal_value_work(): void
    {
        $ctx = [
            // non_trivial is the lowest axis -> the largest weighted gap (0.15·0.9) -> the bottleneck.
            'axis_values' => ['wired' => 0.9, 'real_target' => 0.9, 'non_trivial' => 0.1, 'compounding' => 0.5, 'safety' => 0.9],
            'class_stats' => ['c' => ['successes' => 4, 'failures' => 4]],
        ];
        $result = $this->decider()->decide(
            [
                ['candidateId' => 'relieves_bottleneck', 'class' => 'c', 'value' => 60, 'node_count' => 2, 'touches_axes' => ['non_trivial']],
                ['candidateId' => 'relieves_nothing_binding', 'class' => 'c', 'value' => 60, 'node_count' => 2, 'touches_axes' => ['safety']],
            ],
            $ctx,
        );
        $this->assertSame('non_trivial', $result['bottleneck']['binding_axis'], 'the lowest weighted-value axis is the binding constraint');
        $this->assertSame('relieves_bottleneck', $result['winner']['candidateId'], 'relieving the binding axis is worth more per unit');
    }

    public function test_p_success_is_bayesian_and_calibrates_with_observed_results(): void
    {
        $mk = fn (int $s, int $f) => $this->decider()->decide(
            [['candidateId' => 'c', 'class' => 'k', 'value' => 50, 'node_count' => 1]],
            ['axis_values' => [], 'class_stats' => ['k' => ['successes' => $s, 'failures' => $f]]],
        )['winner']['p_success'];

        $this->assertSame(0.5, $mk(0, 0), 'zero data => 0.5 (honest maximum uncertainty)');
        $this->assertSame(0.9091, $mk(9, 0), 'all successes => high but never 1.0 (Laplace)');
        $this->assertSame(0.0909, $mk(0, 9), 'all failures => low but never 0.0');
        $this->assertGreaterThan($mk(1, 1), $mk(5, 1), 'more successes move the estimate up — the loop calibrates');
    }

    public function test_receipt_never_claims_optimality_only_a_calibrating_estimate(): void
    {
        $result = $this->decider()->decide(
            [['candidateId' => 'c', 'class' => 'k', 'value' => 50, 'node_count' => 1]],
            ['axis_values' => [], 'class_stats' => []],
        );
        $this->assertFalse($result['is_optimal']);
        $this->assertTrue($result['estimate_calibrates'], 'optimal-given-current-beliefs, which improve each cycle');
    }
}
