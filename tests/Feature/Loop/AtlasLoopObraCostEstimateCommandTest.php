<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the obra cost estimator is live at the operator surface: it computes the measured mean spend per
 * provider from the explorations telemetry of a target's work-class, and fail-opens to an empty cost map when
 * the telemetry tables are absent (so an obra stays honestly deferred, never scheduled on a fabricated cost).
 */
final class AtlasLoopObraCostEstimateCommandTest extends TestCase
{
    private const TARGET = 'app/Services/Ai/Marketing/Foo.php';

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_loop_explorations');
        Schema::dropIfExists('atlas_loop_tasks');
        parent::tearDown();
    }

    private function seedTelemetry(): void
    {
        Schema::create('atlas_loop_tasks', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('target_path')->nullable();
        });
        Schema::create('atlas_loop_explorations', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('task_id');
            $t->text('attempt_metrics')->nullable();
            $t->timestamps();
        });

        DB::table('atlas_loop_tasks')->insert(['id' => 'task-1', 'target_path' => self::TARGET]);
        DB::table('atlas_loop_explorations')->insert([
            'task_id' => 'task-1',
            'attempt_metrics' => json_encode([
                ['provider_invoked' => true, 'provider' => 'claude', 'cost_estimate_usd' => 0.4],
                ['provider_invoked' => true, 'provider' => 'claude', 'cost_estimate_usd' => 0.6],
                ['provider_invoked' => false, 'provider' => 'claude', 'cost_estimate_usd' => 9.0], // not invoked ⇒ ignored
            ]),
            'updated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);
    }

    public function test_emits_measured_mean_cost_per_provider(): void
    {
        $this->seedTelemetry();

        $exit = Artisan::call('atlas:loop:obra-cost-estimate', ['--target' => self::TARGET, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.obra_cost_estimate.v1', $decoded['schema']);
        $this->assertSame(self::TARGET, $decoded['target']);
        $this->assertArrayHasKey('claude', $decoded['costs'], (string) json_encode($decoded));
        $this->assertEqualsWithDelta(0.5, $decoded['costs']['claude'], 1e-9); // mean of 0.4 and 0.6
        $this->assertSame(1, $decoded['provider_count']);
    }

    public function test_fail_open_empty_when_no_telemetry_tables(): void
    {
        Schema::dropIfExists('atlas_loop_explorations');
        Schema::dropIfExists('atlas_loop_tasks');

        $exit = Artisan::call('atlas:loop:obra-cost-estimate', ['--target' => self::TARGET, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame([], $decoded['costs']);
        $this->assertSame(0, $decoded['provider_count']);
    }

    public function test_missing_target_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:obra-cost-estimate', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
