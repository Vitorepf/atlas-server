<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        // Step 1 — count rows still on legacy 'actual' so we can audit the migration.
        // Idempotent: re-running this migration on data that has already been normalized
        // results in 0 rows updated and no constraint duplication.
        $legacyCount = (int) DB::table('ai_trace_metric_summaries')
            ->where('cost_confidence', 'actual')
            ->count();

        if ($legacyCount > 0) {
            Log::info('Normalizing cost_confidence vocabulary: actual → metered', [
                'rows_affected' => $legacyCount,
                'migration' => '2026_05_01_004000_normalize_cost_confidence_actual_to_metered',
                'rationale' => 'Renaming for clarity — "actual" implied invoice-grade cost; '
                    .'"metered" reflects the truth: provider tokens × manually-configured rate.',
            ]);

            DB::table('ai_trace_metric_summaries')
                ->where('cost_confidence', 'actual')
                ->update(['cost_confidence' => 'metered']);
        }

        // Note: score_components is a JSON snapshot that also embeds cost_confidence at
        // .efficiency.cost_confidence. We deliberately do NOT rewrite that JSON here. Reasons:
        //   1. The top-level column is the only field downstream aggregations read from.
        //      The JSON copy is for human display/debug, not for filtering.
        //   2. Cross-driver JSON mutation is fragile (jsonb_set on json columns requires casts;
        //      SQLite uses different JSON operators).
        //   3. New writes go through AiTraceMetricAggregator which will write 'metered' into
        //      both the column and the JSON. Existing rows have stale JSON text but the column
        //      is correct — acceptable trade-off. A future recompute (atlas:ai:telemetry:rollup)
        //      regenerates the JSON cleanly.

        // Step 2 — add CHECK constraint locking in the new vocabulary. PostgreSQL only:
        // SQLite ALTER TABLE ADD CONSTRAINT CHECK requires table recreation. Production
        // gets the safety; tests rely on the application-level constants in AiCostEstimator.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $exists = DB::selectOne(<<<'SQL'
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'ai_trace_metric_summaries_cost_confidence_check'
        SQL);

        if ($exists) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE ai_trace_metric_summaries
            ADD CONSTRAINT ai_trace_metric_summaries_cost_confidence_check
            CHECK (cost_confidence IN ('metered', 'estimated', 'unknown'))
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Reverse only the CHECK so the column accepts arbitrary strings again.
        // We do NOT reverse the data normalization — keeping rows as 'metered' is safe
        // because the application accepts both during the transition.
        DB::statement('ALTER TABLE ai_trace_metric_summaries DROP CONSTRAINT IF EXISTS ai_trace_metric_summaries_cost_confidence_check');
    }
};
