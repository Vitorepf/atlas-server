<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Engine de Relatório — Phase 1 Trust Gate.
 *
 * Persists every Trust Gate evaluation so trends in data confidence are visible
 * over time. The trust score is the FIRST gate the engine pipeline applies —
 * if today's window has insufficient coverage, downstream layers (statistical,
 * diagnostic) suppress claims rather than producing them on shaky data.
 *
 * The audit row is written in shadow mode (engine_version='shadow') even though
 * the trust score doesn't yet appear in the report payload. This lets the
 * operator observe whether the gate would have fired before cutover, calibrating
 * thresholds with real data instead of guesses.
 *
 * Per Decisão #5: cost_confidence_score uses TRACE fraction, not spend fraction.
 * Per Decisão #7: NO caching — every call recomputes; this table is the audit
 * record, not a cache.
 * Per Decisão #12: when this table is missing or write fails, the engine
 * fail-opens with trust_gate_skipped flag rather than blocking the report.
 *
 * FK to ai_performance_report_runs is nullable because shadow runs may not have
 * a run row yet (during early Phase 1 rollout); cascade delete keeps cleanup simple.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_data_confidence_audit')) {
            return;
        }

        Schema::create('ai_data_confidence_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->nullable();           // FK to ai_performance_report_runs; nullable for early shadow
            $table->date('report_date');
            $table->string('report_type', 32);             // 'daily' | 'multi_window'
            $table->decimal('trust_score', 5, 4);          // 0.0000 - 1.0000
            $table->string('trust_level', 16);             // 'insufficient' | 'low' | 'moderate' | 'sufficient'
            $table->decimal('coverage', 5, 4);             // overall coverage rate
            $table->boolean('usable_for_attribution');     // overall pass for downstream layers
            $table->string('aggregator_version', 64);      // 'ai_trace_metric_aggregator_v2' or 'v1' or mixed
            $table->boolean('mixed_aggregator_versions')->default(false);
            $table->integer('sample_count');               // total traces in window
            // Score components breakdown — used to debug WHY trust is what it is:
            // {volume_score, coverage_score, cost_confidence_score, version_purity_score}
            $table->json('score_components')->default('{}');
            // Audit trail — per-dimension coverage details, top_gaps, suppressions:
            // {dimensions: {...}, top_gaps: [...], suppressions: [...], cost_confidence_method: 'trace_fraction'}
            $table->json('audit_trail')->default('{}');
            $table->timestamp('evaluated_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            // Primary access pattern: trend queries over time
            $table->index(['report_date', 'report_type'], 'idx_ai_confidence_audit_date_type');
            // Lookup by run for replay/debugging
            $table->index('run_id', 'idx_ai_confidence_audit_run');
            // Detect degradation across days
            $table->index(['report_date', 'trust_score'], 'idx_ai_confidence_audit_date_score');
        });

        // FK to ai_performance_report_runs — pgsql only (SQLite test env can't ALTER ADD FK)
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('ai_performance_report_runs')) {
            $exists = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_constraint
                WHERE conname = 'ai_data_confidence_audit_run_id_foreign'
            SQL);

            if (! $exists) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_data_confidence_audit
                    ADD CONSTRAINT ai_data_confidence_audit_run_id_foreign
                    FOREIGN KEY (run_id) REFERENCES ai_performance_report_runs(id) ON DELETE CASCADE
                SQL);
            }

            // CHECK constraint: trust_level vocabulary locked at DB level
            $checkExists = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_constraint WHERE conname = 'ai_data_confidence_audit_trust_level_check'
            SQL);
            if (! $checkExists) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_data_confidence_audit
                    ADD CONSTRAINT ai_data_confidence_audit_trust_level_check
                    CHECK (trust_level IN ('insufficient', 'low', 'moderate', 'sufficient'))
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_data_confidence_audit')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_data_confidence_audit DROP CONSTRAINT IF EXISTS ai_data_confidence_audit_trust_level_check');
            DB::statement('ALTER TABLE ai_data_confidence_audit DROP CONSTRAINT IF EXISTS ai_data_confidence_audit_run_id_foreign');
        }

        Schema::dropIfExists('ai_data_confidence_audit');
    }
};
