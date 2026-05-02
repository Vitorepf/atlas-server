<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_trace_metric_summaries') || ! Schema::hasTable('ai_traces')) {
            return;
        }

        // Step 1 — cleanup orphan summaries before the FK is enforced.
        // Without this, ALTER TABLE ADD CONSTRAINT would fail on any pre-existing orphan.
        // The SELECT runs first so we can audit-log the count before deletion.
        $orphanCount = (int) DB::table('ai_trace_metric_summaries as s')
            ->leftJoin('ai_traces as t', 't.id', '=', 's.trace_id')
            ->whereNull('t.id')
            ->count();

        if ($orphanCount > 0) {
            // Sample up to 5 trace_ids for the audit trail.
            $sampleIds = DB::table('ai_trace_metric_summaries as s')
                ->leftJoin('ai_traces as t', 't.id', '=', 's.trace_id')
                ->whereNull('t.id')
                ->limit(5)
                ->pluck('s.trace_id')
                ->all();

            Log::warning('Removing orphan ai_trace_metric_summaries rows before adding trace_id FK', [
                'orphan_count' => $orphanCount,
                'sample_trace_ids' => $sampleIds,
                'migration' => '2026_05_01_003000_add_trace_id_fk_to_ai_trace_metric_summaries',
            ]);

            DB::table('ai_trace_metric_summaries')
                ->whereNotIn('trace_id', DB::table('ai_traces')->select('id'))
                ->delete();
        }

        // Step 2 — add the FK. SQLite cannot ALTER TABLE ADD CONSTRAINT FOREIGN KEY,
        // so we restrict to pgsql (the production driver). The cleanup above runs on
        // every driver so the data invariant holds even when the constraint cannot be enforced.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Idempotent: if the constraint already exists from a previous partial run, skip.
        $exists = DB::selectOne(<<<'SQL'
            SELECT 1
            FROM pg_constraint
            WHERE conname = 'ai_trace_metric_summaries_trace_id_foreign'
        SQL);

        if ($exists) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE ai_trace_metric_summaries
            ADD CONSTRAINT ai_trace_metric_summaries_trace_id_foreign
            FOREIGN KEY (trace_id) REFERENCES ai_traces(id) ON DELETE CASCADE
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

        DB::statement('ALTER TABLE ai_trace_metric_summaries DROP CONSTRAINT IF EXISTS ai_trace_metric_summaries_trace_id_foreign');
    }
};
