<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionQueueContinuityForecaster;
use Tests\TestCase;

class AtlasSelfConstructionQueueContinuityForecasterWorkerDrainTest extends TestCase
{
    private function forecaster(): AtlasSelfConstructionQueueContinuityForecaster
    {
        return new AtlasSelfConstructionQueueContinuityForecaster();
    }

    public function test_active_leases_with_recent_completions_forecasts_replenish_before_empty(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 14,
            'claimable_depth' => 14,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
            'active_leases' => 6,
            'completed_dry_run_per_hour_per_lease' => 2.0,
            'safety_window_hours' => 2.0,
        ]);

        // drain rate = 6 * 2.0 = 12/hour; time_to_no_claimable = 14/12 = 1.17h < 2h safety window
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::CONTINUITY_REPLENISH_BEFORE_EMPTY, $result['continuity_status']);
        $this->assertNotNull($result['time_to_no_claimable_hours']);
        $this->assertLessThan(2.0, $result['time_to_no_claimable_hours']);
        $this->assertFalse($result['fail_closed']);
    }

    public function test_large_claimable_buffer_reports_stable_continuity_and_keeps_existing_fields(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 200,
            'claimable_depth' => 200,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
            'active_leases' => 6,
            'completed_dry_run_per_hour_per_lease' => 2.0,
            'safety_window_hours' => 2.0,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::CONTINUITY_STABLE, $result['continuity_status']);
        $this->assertArrayHasKey('hours_until_dry', $result);
        $this->assertArrayHasKey('replenish_by', $result);
        $this->assertArrayHasKey('risk_level', $result);
        $this->assertArrayHasKey('recommended_originator_batch_size', $result);
        $this->assertArrayHasKey('discounted_capacity', $result);
    }

    public function test_no_active_leases_means_no_drain_rate_and_stable_continuity(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 14,
            'claimable_depth' => 14,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
        ]);

        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::CONTINUITY_STABLE, $result['continuity_status']);
        $this->assertNull($result['time_to_no_claimable_hours']);
    }

    public function test_fail_closed_path_does_not_compute_continuity_fields(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 14,
            'claimable_depth' => 14,
            'active_leases' => 6,
            'completed_dry_run_per_hour_per_lease' => 2.0,
        ]);

        $this->assertTrue($result['fail_closed']);
        $this->assertArrayNotHasKey('continuity_status', $result);
    }
}
