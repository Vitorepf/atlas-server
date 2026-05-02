<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surfaces ai_router_decisions data into ai_trace_metric_summaries so the scorecard
 * can answer questions like:
 *   - "Is the router picking the right provider for this task type?"
 *   - "How often does the user override the router's pick?"
 *   - "Which router mode produces the best quality?"
 *
 * Without these columns the data exists in ai_router_decisions but never reaches the
 * scorecard, so the daily report cannot break metrics down by router decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        Schema::table('ai_trace_metric_summaries', function (Blueprint $table): void {
            // 32-char limits match the source ai_router_decisions schema exactly.
            if (! Schema::hasColumn('ai_trace_metric_summaries', 'router_mode')) {
                $table->string('router_mode', 32)->nullable()->after('task_type');
            }

            if (! Schema::hasColumn('ai_trace_metric_summaries', 'router_selected_provider')) {
                $table->string('router_selected_provider', 80)->nullable()->after('router_mode');
                // Note: 80-char width here (not 32 like the source) on purpose. The scorecard
                // joins router_selected_provider to summaries.provider for cross-checks; both
                // need to fit the widest provider name we ever store.
            }

            if (! Schema::hasColumn('ai_trace_metric_summaries', 'router_fallback_provider')) {
                $table->string('router_fallback_provider', 80)->nullable()->after('router_selected_provider');
            }

            if (! Schema::hasColumn('ai_trace_metric_summaries', 'router_was_overridden')) {
                $table->boolean('router_was_overridden')->default(false)->after('router_fallback_provider');
            }
        });

        // Index supports the scorecard's by_router_mode aggregation. Composite with
        // computed_at so the time-windowed query can use the index efficiently.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $exists = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_indexes
                WHERE indexname = 'idx_ai_trace_metric_router_mode'
            SQL);

            if (! $exists) {
                DB::statement('CREATE INDEX idx_ai_trace_metric_router_mode ON ai_trace_metric_summaries (router_mode, computed_at)');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_ai_trace_metric_router_mode');
        }

        Schema::table('ai_trace_metric_summaries', function (Blueprint $table): void {
            foreach (['router_was_overridden', 'router_fallback_provider', 'router_selected_provider', 'router_mode'] as $column) {
                if (Schema::hasColumn('ai_trace_metric_summaries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
