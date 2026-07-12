<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\StatisticalPolicy;
use Tests\TestCase;

/**
 * Richer ITT estimators for world claims: hierarchical bootstrap (case is the unit,
 * repetitions nested), Newcombe effect CI, best/ITT sensitivity, precomputed power,
 * Poisson counts, and byte-stable policy/result hashes. Point estimates and best-run
 * choices must never stand in for these.
 */
class StatisticalEstimatorsTest extends TestCase
{
    public function test_zero_denominator_never_manufactures_a_rate(): void
    {
        $w = StatisticalPolicy::wilson(0, 0);
        $this->assertSame(1.0, $w['width']); // maximal uncertainty, not a 0 or 1 point
    }

    public function test_hierarchical_bootstrap_is_deterministic_and_bounded(): void
    {
        $units = [
            'c1' => [true, true, false],
            'c2' => [true, false, false],
            'c3' => [true, true, true],
        ];
        $a = StatisticalPolicy::hierarchicalBootstrap($units, 500, 42);
        $b = StatisticalPolicy::hierarchicalBootstrap($units, 500, 42);

        $this->assertSame($a, $b, 'same seed must replay byte-identically');
        $this->assertGreaterThanOrEqual(0.0, $a['ci_low']);
        $this->assertLessThanOrEqual(1.0, $a['ci_high']);
        $this->assertLessThanOrEqual($a['ci_high'], $a['ci_low']);
        // the true observed rate (6/9) sits inside the interval
        $this->assertGreaterThanOrEqual($a['ci_low'], $a['estimate']);
        $this->assertLessThanOrEqual($a['ci_high'], $a['estimate']);
    }

    public function test_hierarchical_bootstrap_resamples_cases_not_repetitions_flat(): void
    {
        // one perfect case and one failing case: flat resampling would hide case variance
        $out = StatisticalPolicy::hierarchicalBootstrap([
            'good' => [true, true, true],
            'bad' => [false, false, false],
        ], 400, 7);
        // case-level resampling produces a wide interval spanning both cases
        $this->assertGreaterThan(0.3, $out['ci_high'] - $out['ci_low']);
    }

    public function test_newcombe_effect_ci_brackets_the_difference(): void
    {
        $e = StatisticalPolicy::newcombeDiff(9, 10, 5, 10);
        $this->assertEqualsWithDelta(0.4, $e['diff'], 1e-9);
        $this->assertLessThanOrEqual($e['diff'], $e['ci_low']);
        $this->assertGreaterThanOrEqual($e['diff'], $e['ci_high']);
    }

    public function test_sensitivity_best_case_never_below_itt_and_is_not_the_claim_basis(): void
    {
        $s = StatisticalPolicy::sensitivity(6, 8, 2); // 6 successes, 8 observed, 2 missing
        $this->assertGreaterThanOrEqual($s['itt'], $s['best_case']);
        // ITT (missing = failure) is the conservative claim basis
        $this->assertEqualsWithDelta(6 / 10, $s['itt'], 1e-9);
        $this->assertEqualsWithDelta(8 / 10, $s['best_case'], 1e-9);
    }

    public function test_computed_power_grows_with_sample_size(): void
    {
        $small = StatisticalPolicy::computedPower(0.9, 0.6, 15, 0.05);
        $large = StatisticalPolicy::computedPower(0.9, 0.6, 120, 0.05);
        $this->assertGreaterThan($small, $large);
        $this->assertGreaterThan(0.9, $large);
        // no effect → power collapses to the type-I floor
        $this->assertLessThan(0.1, StatisticalPolicy::computedPower(0.7, 0.7, 100, 0.05));
    }

    public function test_poisson_rate_ci_is_monotone_and_nonnegative(): void
    {
        $low = StatisticalPolicy::poissonRateCi(2, 10);
        $high = StatisticalPolicy::poissonRateCi(9, 10);
        $this->assertGreaterThan($low['rate'], $high['rate']);
        $this->assertGreaterThanOrEqual(0.0, $low['ci_low']);
    }

    public function test_restricted_mean_time_censors_at_the_horizon(): void
    {
        // times 10, 20, 200 with horizon 100 → (10 + 20 + 100) / 3
        $this->assertEqualsWithDelta(
            (10 + 20 + 100) / 3,
            StatisticalPolicy::restrictedMeanTime([10, 20, 200], 100),
            1e-6,
        );
        $this->assertSame(0.0, StatisticalPolicy::restrictedMeanTime([], 100));
    }

    public function test_policy_and_result_hashes_are_stable_and_content_bound(): void
    {
        $policy = ['target_power' => 0.9, 'alpha' => 0.05, 'multiplicity' => ['method' => 'holm']];
        $this->assertSame(
            StatisticalPolicy::policyHash($policy),
            StatisticalPolicy::policyHash(['multiplicity' => ['method' => 'holm'], 'alpha' => 0.05, 'target_power' => 0.9]),
            'key order must not change the hash'
        );
        $this->assertNotSame(
            StatisticalPolicy::resultHash(['blockers' => []]),
            StatisticalPolicy::resultHash(['blockers' => ['x']]),
        );
    }
}
