<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Engine de Relatório — Phase 0 Foundation.
 *
 * Self-observability table written by ReportOrchestrator at the start and end
 * of every report run. The orchestrator does a two-phase write: INSERT at run
 * start with status='started', UPDATE at completion with status='completed' or
 * 'failed' or 'partial' plus duration_ms, layer timings, layer errors.
 *
 * This is the operator's primary debug surface. A Tinker one-liner against
 * this table answers "what did the last 10 runs look like, where did time
 * go, which layer failed?".
 *
 * Engine version distinction (per docs/atlas-ai-aggregator-versions.md):
 *   - aggregator_version = state of AiTraceMetricAggregator output schema (currently v2)
 *   - schema_version (in payload.report.validation) = mobile-facing payload shape
 *   - engine_version (this column) = which code path produced the report:
 *       'legacy'  → existing AiTelemetryPerformanceReportService god class
 *       'shadow'  → new engine ran in parallel; payload still from legacy
 *       'next'    → new engine produced the payload; schema_version bumped to 2
 *
 * All columns nullable except id, started_at, status, engine_version, run_mode
 * because the row is written before any computation finishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_performance_report_runs')) {
            return;
        }

        Schema::create('ai_performance_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('report_date');
            $table->string('report_type', 32);             // 'daily' | 'multi_window'
            $table->string('engine_version', 16);          // 'legacy' | 'shadow' | 'next'
            $table->string('run_mode', 16)->default('live');// 'live' | 'shadow' | 'replay' | 'dry_run'
            $table->string('status', 16);                  // 'started' | 'completed' | 'failed' | 'partial'
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('traces_processed')->nullable();
            $table->decimal('trust_score', 5, 4)->nullable();
            $table->integer('finding_count')->nullable();
            $table->integer('recommendation_count')->nullable();
            // Per-layer timings in ms: {trust_ms, statistical_ms, diagnostic_ms, recommendation_ms, assembly_ms}
            $table->json('layer_timings')->default('{}');
            // Per-layer error messages when status='partial' or 'failed': {layer_name: 'message'}
            $table->json('layer_errors')->default('{}');
            $table->unsignedSmallInteger('schema_version')->nullable();
            // sha256 of canonical-JSON serialized WindowAggregates, used by --replay mode
            // and by determinism verification (two runs with same hash should produce
            // byte-identical assembled payload). Nullable because computed mid-run.
            $table->string('input_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Primary access patterns: list runs by date, find stale 'started' runs for cleanup
            $table->index(['report_date', 'status'], 'idx_ai_report_runs_date_status');
            $table->index(['status', 'started_at'], 'idx_ai_report_runs_status_started');
            $table->index('engine_version', 'idx_ai_report_runs_engine_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_performance_report_runs');
    }
};
