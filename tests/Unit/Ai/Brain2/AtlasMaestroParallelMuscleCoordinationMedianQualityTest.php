<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroParallelMuscleCoordinationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the quality throttle/expand gates in recommend() use the median
 * of worker_quality_scores instead of the mean. The critical case:
 * [10,5,5,5] has mean 6.25 (above threshold → no throttle) but median 5.0
 * (below threshold → quality_risk fires), proving the median gates correctly.
 */
final class AtlasMaestroParallelMuscleCoordinationMedianQualityTest extends TestCase
{
    private AtlasMaestroParallelMuscleCoordinationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasMaestroParallelMuscleCoordinationPolicy;
    }

    private function clean(array $overrides = []): array
    {
        return array_merge([
            'queue_depth'               => 10,
            'servable_count'            => 4,
            'active_leases'             => 2,
            'conflict_free_scope_ratio' => 1.0,
            'lock_contention'           => 0.0,
            'give_back_rate'            => 0.0,
        ], $overrides);
    }

    public function test_mean_above_floor_but_median_below_fires_quality_risk_throttle(): void
    {
        // [10,5,5,5] has mean 6.25 ≥ 6.0 but median 5.0 < 6.0.
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [10, 5, 5, 5],
        ]));

        $this->assertContains('quality_risk', $result['throttle_reasons'],
            'Median 5.0 < 6.0 must fire quality_risk throttle despite mean 6.25');
    }

    public function test_all_high_median_avoids_quality_risk_throttle(): void
    {
        // [8,8,8] has median 8.0 ≥ 6.0 → no quality throttle.
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [8, 8, 8],
        ]));

        $this->assertNotContains('quality_risk', $result['throttle_reasons'],
            'Median 8.0 >= 6.0 must not fire quality_risk');
    }

    public function test_median_above_expand_threshold_produces_workers_demonstrate_quality(): void
    {
        // [9.0, 8.0, 9.0] has median 9.0 ≥ 8.0 → expand signal.
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [9.0, 8.0, 9.0],
        ]));

        $this->assertContains('workers_demonstrate_quality', $result['add_worker_reasons'],
            'Median 9.0 >= 8.0 must produce workers_demonstrate_quality add-worker signal');
    }

    public function test_median_below_expand_threshold_suppresses_workers_demonstrate_quality(): void
    {
        // [7.9, 8.1, 5.0] has median 7.9 < 8.0 → no expand signal.
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [7.9, 8.1, 5.0],
        ]));

        $this->assertNotContains('workers_demonstrate_quality', $result['add_worker_reasons'],
            'Median 7.9 < 8.0 must not produce workers_demonstrate_quality');
    }

    public function test_single_worker_quality_uses_value_directly_low_fires_throttle(): void
    {
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [4.0],
        ]));

        $this->assertContains('quality_risk', $result['throttle_reasons']);
    }

    public function test_single_high_worker_quality_avoids_throttle_and_produces_expand(): void
    {
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [9.5],
        ]));

        $this->assertNotContains('quality_risk', $result['throttle_reasons']);
        $this->assertContains('workers_demonstrate_quality', $result['add_worker_reasons']);
    }
}
