<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingHealthFlagActionRouter;
use PHPUnit\Framework\TestCase;

final class AtlasTaskServingHealthFlagActionRouterWorkerFloorTest extends TestCase
{
    private function router(): AtlasTaskServingHealthFlagActionRouter
    {
        return new AtlasTaskServingHealthFlagActionRouter;
    }

    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'healthy' => true,
            'servable_now' => 10,
            'recoverable' => ['total' => 0],
            'leases_match_claimed' => true,
            'health_flags' => [
                'dry_queue' => false,
                'serving_jammed' => false,
                'recoverable_backlog' => false,
                'lease_leak_detected' => false,
                'malformed_risk' => false,
            ],
            'worker_drain_forecast' => ['queue_pressure' => 'low'],
        ], $overrides);
    }

    public function test_high_queue_pressure_with_replenish_soon_routes_to_top_up_before_starvation(): void
    {
        $r = $this->router()->route($this->snapshot([
            'queue_pressure' => 'high',
            'replenish_recommendation' => 'replenish_soon',
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_TOP_UP_QUEUE_BEFORE_STARVATION, $r['primary_action']);
    }

    public function test_recoverable_backlog_takes_precedence_over_worker_floor_pressure(): void
    {
        $r = $this->router()->route($this->snapshot([
            'queue_pressure' => 'high',
            'replenish_recommendation' => 'replenish_soon',
            'recoverable' => ['total' => 3],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REAP_LEASES, $r['primary_action']);
    }

    public function test_malformed_sweep_takes_precedence_over_worker_floor_pressure(): void
    {
        $r = $this->router()->route($this->snapshot([
            'queue_pressure' => 'high',
            'replenish_recommendation' => 'replenish_soon',
            'health_flags' => [
                'dry_queue' => false,
                'serving_jammed' => false,
                'recoverable_backlog' => false,
                'lease_leak_detected' => false,
                'malformed_risk' => true,
            ],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_SWEEP_MALFORMED, $r['primary_action']);
    }

    public function test_real_lease_leak_takes_precedence_over_worker_floor_pressure(): void
    {
        // r96 ghost-noop floor: parity inspection requires a REAL leak (flag + servable work +
        // recoverable backlog), not a bare count mismatch.
        $r = $this->router()->route($this->snapshot([
            'queue_pressure' => 'high',
            'replenish_recommendation' => 'replenish_soon',
            'leases_match_claimed' => false,
            'recoverable' => ['total' => 2],
            'health_flags' => [
                'dry_queue' => false,
                'serving_jammed' => false,
                'recoverable_backlog' => false,
                'lease_leak_detected' => true,
                'malformed_risk' => false,
            ],
        ]));

        // Queue corruption/leakage outranks worker-floor top-up guidance; with recoverable
        // leases present the reap action dominates parity inspection (router-documented order).
        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REAP_LEASES, $r['primary_action']);
    }

    public function test_low_pressure_without_replenish_soon_continues_work(): void
    {
        $r = $this->router()->route($this->snapshot());

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_CONTINUE_WORK, $r['primary_action']);
    }

    public function test_nested_forecast_replenish_soon_with_dry_queue_false_routes_to_top_up(): void
    {
        $r = $this->router()->route($this->snapshot([
            'active_leases' => 4,
            'worker_drain_forecast' => ['replenish_recommendation' => 'replenish_soon'],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_TOP_UP_QUEUE_BEFORE_STARVATION, $r['primary_action']);
        $this->assertStringContainsString('worker_floor', $r['human_readable_reason']);
    }

    public function test_claimable_per_active_worker_at_or_below_two_with_active_leases_routes_to_top_up(): void
    {
        $r = $this->router()->route($this->snapshot([
            'active_leases' => 5,
            'worker_drain_forecast' => ['claimable_per_active_worker' => 2.0],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_TOP_UP_QUEUE_BEFORE_STARVATION, $r['primary_action']);
        $this->assertStringContainsString('worker_floor', $r['human_readable_reason']);
    }

    public function test_no_active_leases_with_comfortable_buffer_continues_work(): void
    {
        $r = $this->router()->route($this->snapshot([
            'active_leases' => 0,
            'worker_drain_forecast' => ['claimable_per_active_worker' => 1.0],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_CONTINUE_WORK, $r['primary_action']);
    }

    public function test_active_leases_with_comfortable_claimable_buffer_continues_work(): void
    {
        $r = $this->router()->route($this->snapshot([
            'active_leases' => 5,
            'worker_drain_forecast' => ['claimable_per_active_worker' => 5.0],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_CONTINUE_WORK, $r['primary_action']);
    }

    public function test_null_claimable_per_active_worker_does_not_trigger_worker_floor(): void
    {
        // When the claimable_per_active_worker metric is null (missing telemetry),
        // it must NOT be coerced to 0.0 and compared as below the worker floor.
        $r = $this->router()->route($this->snapshot([
            'active_leases' => 5,
            'worker_drain_forecast' => ['claimable_per_active_worker' => null],
        ]));

        // null metric = unknown, not maximum pressure → should NOT trigger top_up.
        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_CONTINUE_WORK, $r['primary_action']);
    }

    public function test_null_claimable_at_snapshot_level_does_not_trigger_worker_floor(): void
    {
        // Same guard at the snapshot level (not nested in forecast).
        $r = $this->router()->route($this->snapshot([
            'active_leases' => 5,
            'claimable_per_active_worker' => null,
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_CONTINUE_WORK, $r['primary_action']);
    }
}
