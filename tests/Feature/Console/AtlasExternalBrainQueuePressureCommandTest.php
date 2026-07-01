<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * atlas:external-brain:queue-pressure is a read-only control surface combining drain forecast,
 * adaptive batch size, and worker-floor pressure. Proves: correct JSON output shape and that a
 * worker-floor starvation signal overrides a habitual/comfortable adaptive batch size.
 */
final class AtlasExternalBrainQueuePressureCommandTest extends TestCase
{
    private function exec(array $options): array
    {
        Artisan::call('atlas:external-brain:queue-pressure', $options + ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_json_output_contains_all_three_organ_sections(): void
    {
        $output = $this->exec([]);

        $this->assertArrayHasKey('drain_forecast', $output);
        $this->assertArrayHasKey('adaptive_batch', $output);
        $this->assertArrayHasKey('worker_floor', $output);
        $this->assertArrayHasKey('recommended_batch_size', $output);
    }

    public function test_comfortable_queue_with_no_starvation_signal_uses_adaptive_batch_only(): void
    {
        $output = $this->exec([
            '--queue-depth' => 5,
            '--servable-now' => 20,
            '--active-leases' => 2,
            '--recent-successes' => 10,
            '--recent-give-backs' => 1,
            '--median-task-minutes' => 20,
            '--claimable-per-active-worker' => 8,
        ]);

        $this->assertSame('hold', $output['worker_floor']['action']);
        $this->assertSame($output['adaptive_batch']['recommended_batch_size'], $output['recommended_batch_size']);
    }

    public function test_worker_floor_starvation_overrides_low_adaptive_batch(): void
    {
        $output = $this->exec([
            '--queue-depth' => 100,
            '--servable-now' => 40,
            '--active-leases' => 5,
            '--recent-successes' => 10,
            '--recent-give-backs' => 1,
            '--median-task-minutes' => 20,
            '--theme-saturation' => 0.9,
            '--claimable-per-active-worker' => 1,
        ]);

        $this->assertSame('request_bounded_batch', $output['worker_floor']['action']);
        $this->assertGreaterThanOrEqual($output['worker_floor']['max_tasks'], $output['recommended_batch_size']);
        $this->assertGreaterThan(0, $output['recommended_batch_size']);
    }

    public function test_malformed_packets_hold_worker_floor_even_with_thin_buffer(): void
    {
        $output = $this->exec([
            '--active-leases' => 3,
            '--claimable-per-active-worker' => 1,
            '--malformed-count' => 5,
        ]);

        $this->assertSame('hold', $output['worker_floor']['action']);
    }

    public function test_drain_forecast_reflects_supplied_leases_and_successes(): void
    {
        $output = $this->exec([
            '--active-leases' => 4,
            '--recent-successes' => 8,
            '--recent-give-backs' => 2,
            '--median-task-minutes' => 15,
            '--queue-depth' => 10,
        ]);

        $this->assertSame(4, $output['drain_forecast']['inputs']['active_leases']);
        $this->assertGreaterThan(0.0, $output['drain_forecast']['estimated_drain_per_hour']);
    }
}
