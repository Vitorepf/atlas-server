<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEOS-I1 / M3 — Ledger Timeline Extension.
 *
 * Additive, nullable columns on the canonical `atlas_ledger_events` table so
 * TEOS can reconstruct scoped timelines without spawning a parallel ledger
 * (forbidden by atlas-teos-increment-1-plan.md). Backwards compatible:
 * existing rows have NULL for new columns; existing writers continue to work
 * untouched.
 *
 * Adds:
 *   - scope_type (string, 40, nullable, indexed)
 *   - scope_id   (string, 80, nullable, indexed)
 *   - event_hash (string, 64, nullable, indexed) — deterministic SHA-256
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_type')) {
                $table->string('scope_type', 40)->nullable()->index('atlas_ledger_events_scope_type_index');
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_id')) {
                $table->string('scope_id', 80)->nullable()->index('atlas_ledger_events_scope_id_index');
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'event_hash')) {
                $table->string('event_hash', 64)->nullable()->index('atlas_ledger_events_event_hash_index');
            }
        });

        // Composite index used by scoped timeline queries.
        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            $table->index(
                ['scope_type', 'scope_id', 'occurred_at'],
                'atlas_ledger_events_scope_occurred_index',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            $table->dropIndex('atlas_ledger_events_scope_occurred_index');
        });

        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            foreach (['scope_type', 'scope_id', 'event_hash'] as $column) {
                if (Schema::hasColumn('atlas_ledger_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
