<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerDrainRateForecaster;
use PHPUnit\Framework\TestCase;

/**
 * Proves the supply-window replenish decision layered on top of forecast(): hours_to_starvation is
 * derived from claimable_depth and proven throughput; recommend_replenish only fires when that proven
 * throughput can drain the claimable window inside the configured supply_window_hours; active leases
 * alone (zero recent_successes) never count as throughput, so replenish never fires on unproven leases.
 */
final class AtlasExternalBrainWorkerDrainRateForecasterTest extends TestCase
{
    private function forecaster(): AtlasExternalBrainWorkerDrainRateForecaster
    {
        return new AtlasExternalBrainWorkerDrainRateForecaster;
    }

    public function test_hours_to_starvation_derived_from_claimable_depth_and_proven_throughput(): void
    {
        $result = $this->forecaster()->forecast([
            'active_leases' => 2,
            'recent_successes' => 8,
            'recent_give_backs' => 2,
            'median_task_minutes' => 30,
            'claimable_depth' => 10,
        ]);

        $this->assertNotNull($result['hours_to_starvation']);
        $this->assertGreaterThan(0.0, $result['hours_to_starvation']);
    }

    public function test_recommend_replenish_true_when_proven_throughput_drains_within_supply_window(): void
    {
        $result = $this->forecaster()->forecast([
            'active_leases' => 4,
            'recent_successes' => 10,
            'recent_give_backs' => 0,
            'median_task_minutes' => 15,
            'claimable_depth' => 5,
            'supply_window_hours' => 24,
        ]);

        $this->assertTrue($result['recommend_replenish']);
    }

    public function test_recommend_replenish_false_when_throughput_cannot_drain_within_supply_window(): void
    {
        $result = $this->forecaster()->forecast([
            'active_leases' => 1,
            'recent_successes' => 5,
            'recent_give_backs' => 0,
            'median_task_minutes' => 60,
            'claimable_depth' => 500,
            'supply_window_hours' => 4,
        ]);

        $this->assertFalse($result['recommend_replenish']);
    }

    public function test_recommend_replenish_false_when_no_supply_window_configured(): void
    {
        $result = $this->forecaster()->forecast([
            'active_leases' => 4,
            'recent_successes' => 10,
            'recent_give_backs' => 0,
            'median_task_minutes' => 15,
            'claimable_depth' => 5,
        ]);

        $this->assertFalse($result['recommend_replenish']);
        $this->assertNull($result['supply_window_hours']);
    }

    public function test_active_leases_without_recent_success_never_count_as_throughput_for_replenish(): void
    {
        $result = $this->forecaster()->forecast([
            'active_leases' => 10,
            'recent_successes' => 0,
            'recent_give_backs' => 0,
            'median_task_minutes' => 15,
            'claimable_depth' => 5,
            'supply_window_hours' => 24,
        ]);

        $this->assertSame(0.0, $result['estimated_drain_per_hour']);
        $this->assertNull($result['hours_to_starvation']);
        $this->assertFalse($result['recommend_replenish']);
    }

    public function test_claimable_depth_defaults_to_queue_depth_when_absent(): void
    {
        $result = $this->forecaster()->forecast([
            'active_leases' => 2,
            'recent_successes' => 5,
            'recent_give_backs' => 0,
            'median_task_minutes' => 30,
            'queue_depth' => 20,
        ]);

        $this->assertSame(20, $result['inputs']['claimable_depth']);
    }
}
