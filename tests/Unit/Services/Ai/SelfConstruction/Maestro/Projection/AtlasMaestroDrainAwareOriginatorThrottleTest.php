<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroDrainAwareOriginatorThrottle;
use Tests\TestCase;

final class AtlasMaestroDrainAwareOriginatorThrottleTest extends TestCase
{
    private function throttle(): AtlasMaestroDrainAwareOriginatorThrottle
    {
        return new AtlasMaestroDrainAwareOriginatorThrottle;
    }

    // ── AC: sufficient_depth maps to smaller batches not stop ──

    public function test_sufficient_depth_maps_to_smaller_batch_not_stop(): void
    {
        $result = $this->throttle()->throttle([
            'claimable_depth' => 50,
            'active_workers' => 3,
            'serve_rate_per_minute' => 2.5,
        ]);

        $this->assertSame('sufficient_depth', $result['drain_state']);
        $this->assertLessThan(5, $result['batch_size']); // smaller than normal
        $this->assertFalse($result['stop_loop']); // never stops
    }

    // ── AC: dry queue maps to max replenishment ──

    public function test_dry_queue_maps_to_max_replenishment(): void
    {
        $result = $this->throttle()->throttle([
            'claimable_depth' => 0,
            'active_workers' => 3,
            'serve_rate_per_minute' => 2.5,
            'max_batch' => 10,
        ]);

        $this->assertSame('dry_queue', $result['drain_state']);
        $this->assertSame(10, $result['batch_size']); // max replenishment
    }

    // ── AC: active drain maps to normal replenishment ──

    public function test_active_drain_maps_to_normal_replenishment(): void
    {
        $result = $this->throttle()->throttle([
            'claimable_depth' => 5,
            'active_workers' => 2,
            'serve_rate_per_minute' => 0.0, // no serve rate → active drain, not sufficient depth
        ]);

        $this->assertSame('active_drain', $result['drain_state']);
        $this->assertSame(5, $result['batch_size']); // normal
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->throttle()->throttle([
            'claimable_depth' => 10,
            'active_workers' => 2,
            'serve_rate_per_minute' => 1.0,
        ]);

        $this->assertSame(AtlasMaestroDrainAwareOriginatorThrottle::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('batch_size', $result);
        $this->assertArrayHasKey('drain_state', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('stop_loop', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'claimable_depth' => 10,
            'active_workers' => 2,
            'serve_rate_per_minute' => 1.0,
        ];

        $a = $this->throttle()->throttle($input);
        $b = $this->throttle()->throttle($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
