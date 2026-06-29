<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the worker-count planner is live at the operator surface: the planned count never exceeds the
 * configured ceiling or the request, never drops below 1; a missing --requested is a usage_error.
 */
final class AtlasLoopWorkerCountPlanCommandTest extends TestCase
{
    public function test_plan_respects_ceiling_and_floor(): void
    {
        config(['atlas.loop.parallel.max_workers' => 3]);

        $exit = Artisan::call('atlas:loop:worker-count-plan', ['--requested' => 100, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.worker_count_plan.v1', $decoded['schema_version']);
        $this->assertSame(100, $decoded['requested']);
        $this->assertGreaterThanOrEqual(1, $decoded['planned_workers']);
        $this->assertLessThanOrEqual(3, $decoded['planned_workers'], 'planned count never exceeds the ceiling');
    }

    public function test_plan_of_one_is_one(): void
    {
        $exit = Artisan::call('atlas:loop:worker-count-plan', ['--requested' => 1, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $decoded['planned_workers']);
    }

    public function test_missing_requested_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:worker-count-plan', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
