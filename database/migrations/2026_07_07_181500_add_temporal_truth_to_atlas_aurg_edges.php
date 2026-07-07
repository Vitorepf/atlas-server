<?php

use App\Support\TemporalTruth\HasTemporalTruth;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIS4 (Obra #20) — Temporal Truth Fields on `atlas_aurg_edges`.
 *
 * The bi-temporal AURG model (T4-S1) lives in the append-only tick log as the
 * code-truth axis; SIS4 promotes the *edge* validity to SQL so `stateAt(T)`
 * becomes a query ({@see HasTemporalTruth} scopeCurrent) instead of a replay.
 * 5 NEW nullable columns — the SAME byte-for-byte pattern already applied to
 * atlas_memory_entries / atlas_decision_receipts / ai_codebase_world_model_edges.
 *
 * Backfill: existing edges get valid_from = created_at (when the relation first
 * appeared) so as-of over the live graph is correct from day one; without it a
 * NULL valid_from would be read as "always current" and break past-date queries.
 *
 * Aditive only — runtime behaviour unchanged; tests run on sqlite :memory:.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_aurg_edges')) {
            return;
        }

        Schema::table('atlas_aurg_edges', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_aurg_edges', 'valid_from')) {
                $table->timestamp('valid_from')->nullable()->after('meta');
            }
            if (! Schema::hasColumn('atlas_aurg_edges', 'valid_until')) {
                $table->timestamp('valid_until')->nullable()->after('valid_from');
            }
            if (! Schema::hasColumn('atlas_aurg_edges', 'stale_after')) {
                $table->timestamp('stale_after')->nullable()->after('valid_until');
            }
            if (! Schema::hasColumn('atlas_aurg_edges', 'superseded_by')) {
                $table->string('superseded_by', 300)->nullable()->after('stale_after');
            }
            if (! Schema::hasColumn('atlas_aurg_edges', 'authority_level')) {
                $table->string('authority_level', 40)->nullable()->after('superseded_by');
            }
        });

        Schema::table('atlas_aurg_edges', function (Blueprint $table): void {
            $existing = $this->indexNames('atlas_aurg_edges');
            if (! in_array('atlas_aurg_edges_valid_from_index', $existing, true)) {
                $table->index('valid_from');
            }
            if (! in_array('atlas_aurg_edges_stale_after_index', $existing, true)) {
                $table->index('stale_after');
            }
        });

        // Backfill: an edge's validity begins when the relation first appeared.
        try {
            DB::table('atlas_aurg_edges')
                ->whereNull('valid_from')
                ->update(['valid_from' => DB::raw('created_at')]);
        } catch (Throwable) {
            // best-effort — as-of is still correct for rows the model stamps.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_aurg_edges')) {
            return;
        }

        Schema::table('atlas_aurg_edges', function (Blueprint $table): void {
            foreach (['valid_from', 'stale_after'] as $column) {
                try {
                    $table->dropIndex(['atlas_aurg_edges_'.$column.'_index']);
                } catch (Throwable) {
                    // best-effort rollback
                }
            }
            foreach (['valid_from', 'valid_until', 'stale_after', 'superseded_by', 'authority_level'] as $column) {
                if (Schema::hasColumn('atlas_aurg_edges', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        try {
            return array_values(array_map(
                static fn (array $index): string => (string) ($index['name'] ?? ''),
                Schema::getIndexes($table),
            ));
        } catch (Throwable) {
            return [];
        }
    }
};
