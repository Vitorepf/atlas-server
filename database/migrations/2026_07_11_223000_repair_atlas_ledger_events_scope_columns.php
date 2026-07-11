<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 2026-07-09 ledger repair recreated atlas_ledger_events without the
 * timeline scope columns from 2026_05_19_050000. That migration was already
 * recorded as run, so this repair re-applies the missing columns idempotently.
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

        if (! Schema::hasIndex('atlas_ledger_events', 'atlas_ledger_events_scope_occurred_index')) {
            Schema::table('atlas_ledger_events', function (Blueprint $table): void {
                $table->index(
                    ['scope_type', 'scope_id', 'occurred_at'],
                    'atlas_ledger_events_scope_occurred_index',
                );
            });
        }
    }

    public function down(): void
    {
        // Evidence is append-only; do not drop scope columns on rollback.
    }
};
