<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerDrainRateForecaster;
use Tests\TestCase;

final class AtlasExternalBrainWorkerDrainRateForecasterTest extends TestCase
{
    public function test_active_leases_alone_without_any_proven_success_yields_zero_drain(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 5,
            'recent_successes' => 0,
            'recent_give_backs' => 0,
            'median_task_minutes' => 15,
            'queue_depth' => 100,
        ]);

        $this->assertSame(0.0, $result['estimated_drain_per_hour']);
        $this->assertNull($result['hours_to_clear_claimable']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame('no_proven_throughput_yet', $result['bottleneck_reason']);
        $this->assertSame('pause_origination', $result['recommended_originator_pace']);
    }

    public function test_no_active_workers_forces_low_confidence_regardless_of_history(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 0,
            'recent_successes' => 50,
            'recent_give_backs' => 1,
            'median_task_minutes' => 10,
            'queue_depth' => 20,
        ]);

        $this->assertSame('low', $result['confidence']);
        $this->assertSame('no_active_workers', $result['bottleneck_reason']);
        $this->assertSame('pause_origination', $result['recommended_originator_pace']);
    }

    public function test_healthy_large_sample_low_give_back_rate_yields_high_confidence(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 4,
            'recent_successes' => 19,
            'recent_give_backs' => 1,
            'median_task_minutes' => 15,
            'queue_depth' => 8,
        ]);

        $this->assertSame('high', $result['confidence']);
        $this->assertSame('none', $result['bottleneck_reason']);
        $this->assertGreaterThan(0.0, $result['estimated_drain_per_hour']);
        $this->assertNotNull($result['hours_to_clear_claimable']);
    }

    public function test_sparse_sample_downgrades_confidence_to_medium(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 2,
            'recent_successes' => 2,
            'recent_give_backs' => 0,
            'median_task_minutes' => 20,
            'queue_depth' => 10,
        ]);

        $this->assertSame('medium', $result['confidence']);
        $this->assertSame('insufficient_sample_data', $result['bottleneck_reason']);
    }

    public function test_high_give_back_rate_downgrades_confidence_and_throttles_pace(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 3,
            'recent_successes' => 5,
            'recent_give_backs' => 5,
            'median_task_minutes' => 15,
            'queue_depth' => 10,
        ]);

        $this->assertSame('medium', $result['confidence']);
        $this->assertSame('high_give_back_rate', $result['bottleneck_reason']);
        $this->assertSame('throttle_origination', $result['recommended_originator_pace']);
    }

    public function test_fast_clear_time_with_high_confidence_recommends_increasing_pace(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 10,
            'recent_successes' => 19,
            'recent_give_backs' => 1,
            'median_task_minutes' => 5,
            'queue_depth' => 5,
        ]);

        $this->assertSame('high', $result['confidence']);
        $this->assertLessThan(2.0, $result['hours_to_clear_claimable']);
        $this->assertSame('increase_origination', $result['recommended_originator_pace']);
    }

    public function test_zero_median_task_minutes_does_not_error_and_yields_zero_drain(): void
    {
        $result = (new AtlasExternalBrainWorkerDrainRateForecaster)->forecast([
            'active_leases' => 3,
            'recent_successes' => 5,
            'recent_give_backs' => 0,
            'median_task_minutes' => 0,
            'queue_depth' => 10,
        ]);

        $this->assertSame(0.0, $result['estimated_drain_per_hour']);
        $this->assertNull($result['hours_to_clear_claimable']);
    }
}
