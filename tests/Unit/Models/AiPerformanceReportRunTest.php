<?php

namespace Tests\Unit\Models;

use App\Models\AiPerformanceReportRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Engine F0 — pin the contract of AiPerformanceReportRun model + table.
 *
 * Verifies casts, fillable, lifecycle pattern (insert started → update with completion).
 */
class AiPerformanceReportRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_performance_report_runs');
        parent::tearDown();
    }

    public function test_insert_started_then_complete_lifecycle(): void
    {
        // Phase 1: orchestrator INSERTs at run start
        $run = AiPerformanceReportRun::query()->create([
            'report_date' => '2026-04-30',
            'report_type' => 'daily',
            'engine_version' => 'shadow',
            'run_mode' => 'live',
            'status' => 'started',
            'started_at' => now(),
        ]);

        $this->assertSame('started', $run->status);
        $this->assertNull($run->completed_at);
        $this->assertNull($run->duration_ms);

        // Phase 2: orchestrator UPDATEs at completion
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_ms' => 1240,
            'traces_processed' => 47,
            'trust_score' => 0.78,
            'finding_count' => 2,
            'recommendation_count' => 1,
            'layer_timings' => [
                'trust_ms' => 12,
                'statistical_ms' => 340,
                'diagnostic_ms' => 180,
                'recommendation_ms' => 95,
                'assembly_ms' => 22,
            ],
            'schema_version' => 2,
        ]);

        $fresh = $run->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(1240, $fresh->duration_ms);
        $this->assertSame(47, $fresh->traces_processed);
        $this->assertEqualsWithDelta(0.78, $fresh->trust_score, 0.0001);
        $this->assertSame(2, $fresh->finding_count);
        $this->assertSame(2, $fresh->schema_version);
        $this->assertIsArray($fresh->layer_timings);
        $this->assertSame(340, $fresh->layer_timings['statistical_ms']);
    }

    public function test_partial_status_carries_layer_errors(): void
    {
        $run = AiPerformanceReportRun::query()->create([
            'report_date' => '2026-04-30',
            'report_type' => 'daily',
            'engine_version' => 'shadow',
            'status' => 'partial',
            'started_at' => now(),
            'completed_at' => now(),
            'duration_ms' => 800,
            'layer_errors' => [
                'statistical' => 'ai_metric_daily_snapshots not found; layer skipped',
            ],
        ]);

        $fresh = $run->fresh();
        $this->assertSame('partial', $fresh->status);
        $this->assertIsArray($fresh->layer_errors);
        $this->assertStringContainsString('not found', $fresh->layer_errors['statistical']);
    }

    public function test_replay_snapshots_are_cast_to_arrays(): void
    {
        $run = AiPerformanceReportRun::query()->create([
            'report_date' => '2026-04-30',
            'report_type' => 'daily',
            'engine_version' => 'shadow',
            'status' => 'completed',
            'started_at' => now(),
            'input_hash' => str_repeat('a', 64),
            'input_snapshot' => ['summary' => ['traces' => 3]],
            'output_hash' => str_repeat('b', 64),
            'output_snapshot' => ['decision' => 'ok'],
        ]);

        $fresh = $run->fresh();
        $this->assertIsArray($fresh->input_snapshot);
        $this->assertSame(3, $fresh->input_snapshot['summary']['traces']);
        $this->assertIsArray($fresh->output_snapshot);
        $this->assertSame('ok', $fresh->output_snapshot['decision']);
    }


    public function test_no_updated_at_column_or_cast(): void
    {
        // Documented design: $timestamps = false; created_at + completed_at are explicit.
        $run = AiPerformanceReportRun::query()->create([
            'report_date' => '2026-04-30',
            'report_type' => 'daily',
            'engine_version' => 'legacy',
            'status' => 'started',
            'started_at' => now(),
        ]);

        $this->assertFalse($run->timestamps,
            'Model intentionally disables Laravel auto-timestamps; explicit started_at/completed_at carry the meaning.');
    }

    public function test_status_index_supports_listing_runs_by_status(): void
    {
        AiPerformanceReportRun::query()->create([
            'report_date' => '2026-04-30',
            'report_type' => 'daily',
            'engine_version' => 'shadow',
            'status' => 'started',
            'started_at' => now()->subHours(3),
        ]);
        AiPerformanceReportRun::query()->create([
            'report_date' => '2026-04-30',
            'report_type' => 'daily',
            'engine_version' => 'shadow',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(50),
        ]);

        $stale = AiPerformanceReportRun::query()
            ->where('status', 'started')
            ->where('started_at', '<', now()->subHours(2))
            ->count();

        $this->assertSame(1, $stale,
            'Stale "started" runs (process killed mid-run) are detectable via the (status, started_at) index.');
    }

    private function bootTable(): void
    {
        Schema::dropIfExists('ai_performance_report_runs');

        Schema::create('ai_performance_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('report_date');
            $table->string('report_type', 32);
            $table->string('engine_version', 16);
            $table->string('run_mode', 16)->default('live');
            $table->string('status', 16);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('traces_processed')->nullable();
            $table->decimal('trust_score', 5, 4)->nullable();
            $table->integer('finding_count')->nullable();
            $table->integer('recommendation_count')->nullable();
            $table->json('layer_timings')->default('{}');
            $table->json('layer_errors')->default('{}');
            $table->unsignedSmallInteger('schema_version')->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->json('input_snapshot')->nullable();
            $table->string('output_hash', 64)->nullable();
            $table->json('output_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
