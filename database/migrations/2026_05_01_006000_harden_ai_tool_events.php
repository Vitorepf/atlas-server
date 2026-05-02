<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 7c F1 — schema hardening for ai_tool_events.
 *
 * Audit findings addressed:
 *   - Orphan tool_events from deleted traces (HIGH severity, audit Fix 2 analog)
 *   - No idempotency key — duplicate inserts on retry pass silently (HIGH severity)
 *   - No CHECK constraints on risk / permission_status enums (BUG-08 analog)
 *
 * Step order is important. Constraints can only be enforced after data is clean.
 *   1. Delete orphans (any driver, idempotent)
 *   2. Add event_key column + backfill deterministically
 *   3. Add UNIQUE on event_key
 *   4. Add FK with CASCADE (pgsql only)
 *   5. Add CHECK constraints on enums (pgsql only)
 *
 * SQLite test environments get the data invariant (orphan cleanup) but skip
 * ALTER ADD CONSTRAINT — production gets the full guarantees.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_tool_events') || ! Schema::hasTable('ai_traces')) {
            return;
        }

        // === Step 1 — orphan cleanup ===
        // Tool events whose trace_id no longer points to any ai_traces row are
        // diagnostic dead weight. Aggregator never reaches them (eager-load via
        // $trace->toolEvents skips them). Removing now is required before FK.
        $orphanCount = (int) DB::table('ai_tool_events as e')
            ->leftJoin('ai_traces as t', 't.id', '=', 'e.trace_id')
            ->whereNotNull('e.trace_id')
            ->whereNull('t.id')
            ->count();

        if ($orphanCount > 0) {
            $sampleIds = DB::table('ai_tool_events as e')
                ->leftJoin('ai_traces as t', 't.id', '=', 'e.trace_id')
                ->whereNotNull('e.trace_id')
                ->whereNull('t.id')
                ->limit(5)
                ->pluck('e.id')
                ->all();

            Log::warning('Removing orphan ai_tool_events before adding trace_id FK', [
                'orphan_count' => $orphanCount,
                'sample_ids' => $sampleIds,
                'migration' => '2026_05_01_006000_harden_ai_tool_events',
            ]);

            DB::table('ai_tool_events')
                ->whereNotNull('trace_id')
                ->whereNotIn('trace_id', DB::table('ai_traces')->select('id'))
                ->delete();
        }

        // === Step 2 — event_key column + deterministic backfill ===
        // Must add as nullable first, backfill, then add UNIQUE (constraint cannot
        // be created on a column with duplicate or NULL values).
        if (! Schema::hasColumn('ai_tool_events', 'event_key')) {
            Schema::table('ai_tool_events', function (Blueprint $table): void {
                $table->string('event_key', 180)->nullable()->after('id');
            });

            // Backfill: deterministic hash of (id, tool, trace_id, created_at).
            // Existing rows have no invocation_id available, so id substitutes — id
            // is already unique per row, so the resulting hash is also unique.
            // Format mirrors what AiToolRuntime will write for new rows in F2:
            //   tool:<sha256-trunc>
            $rows = DB::table('ai_tool_events')->whereNull('event_key')->select(['id', 'tool', 'trace_id', 'created_at'])->cursor();
            foreach ($rows as $row) {
                $key = 'tool:'.substr(hash('sha256', implode(':', [
                    (string) $row->trace_id,
                    (string) $row->tool,
                    (string) $row->id,
                    (string) $row->created_at,
                ])), 0, 64);

                DB::table('ai_tool_events')->where('id', $row->id)->update(['event_key' => $key]);
            }
        }

        // === Step 3 — UNIQUE on event_key ===
        // Idempotency: if a worker retries the same tool execution, the second
        // INSERT collides on event_key and fails fast — exactly the behavior we want.
        if (! $this->indexExists('ai_tool_events', 'ai_tool_events_event_key_unique')) {
            Schema::table('ai_tool_events', function (Blueprint $table): void {
                $table->unique('event_key', 'ai_tool_events_event_key_unique');
            });
        }

        // === Step 4 — FK trace_id → ai_traces(id) ON DELETE CASCADE ===
        // CASCADE per the architectural decision in the Fix 7c plan: tool events
        // without parent trace lose 80%+ of diagnostic value, and ai_traces is
        // never deleted in practice (no softDeletes on it). Consistency with
        // ai_trace_metric_summaries.trace_id (Fix 2).
        if (DB::connection()->getDriverName() === 'pgsql') {
            $fkExists = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_constraint
                WHERE conname = 'ai_tool_events_trace_id_foreign'
            SQL);

            if (! $fkExists) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_tool_events
                    ADD CONSTRAINT ai_tool_events_trace_id_foreign
                    FOREIGN KEY (trace_id) REFERENCES ai_traces(id) ON DELETE CASCADE
                SQL);
            }

            // === Step 5 — CHECK constraints on enums ===
            // Locks the vocabulary at DB level so a typo in app code surfaces as a
            // constraint violation instead of polluting analytics with unknown values.
            $riskCheck = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_constraint WHERE conname = 'ai_tool_events_risk_check'
            SQL);
            if (! $riskCheck) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_tool_events
                    ADD CONSTRAINT ai_tool_events_risk_check
                    CHECK (risk IN ('low', 'medium', 'high', 'critical'))
                SQL);
            }

            $permCheck = DB::selectOne(<<<'SQL'
                SELECT 1 FROM pg_constraint WHERE conname = 'ai_tool_events_permission_status_check'
            SQL);
            if (! $permCheck) {
                // Vocabulary mirrors AiToolRuntime::permissionStatus() outputs plus
                // the failure paths (denied / approval_required) and 'approved' set
                // by recordToolEvent on success.
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_tool_events
                    ADD CONSTRAINT ai_tool_events_permission_status_check
                    CHECK (permission_status IN ('auto', 'approved', 'denied', 'approval_required'))
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_tool_events')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_tool_events DROP CONSTRAINT IF EXISTS ai_tool_events_permission_status_check');
            DB::statement('ALTER TABLE ai_tool_events DROP CONSTRAINT IF EXISTS ai_tool_events_risk_check');
            DB::statement('ALTER TABLE ai_tool_events DROP CONSTRAINT IF EXISTS ai_tool_events_trace_id_foreign');
        }

        if ($this->indexExists('ai_tool_events', 'ai_tool_events_event_key_unique')) {
            Schema::table('ai_tool_events', function (Blueprint $table): void {
                $table->dropUnique('ai_tool_events_event_key_unique');
            });
        }

        if (Schema::hasColumn('ai_tool_events', 'event_key')) {
            Schema::table('ai_tool_events', function (Blueprint $table): void {
                $table->dropColumn('event_key');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::selectOne('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$indexName]) !== null;
        }

        // SQLite: pragma_index_list returns names of all indexes on the table
        $indexes = DB::select("PRAGMA index_list({$table})");
        foreach ($indexes as $index) {
            if (($index->name ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
