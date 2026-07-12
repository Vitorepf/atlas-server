<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_ledger_events', 'prev_event_hash')) {
                $table->string('prev_event_hash', 64)->nullable()->index('atlas_ledger_events_prev_event_hash_index');
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_basis')) {
                $table->string('chain_basis', 40)->nullable()->index('atlas_ledger_events_chain_basis_index');
            }
        });

        $this->ensureIndex('prev_event_hash', 'atlas_ledger_events_prev_event_hash_index');
        $this->ensureIndex('chain_basis', 'atlas_ledger_events_chain_basis_index');

        if (Schema::hasColumn('atlas_ledger_events', 'scope_type')
            && Schema::hasColumn('atlas_ledger_events', 'scope_id')
            && Schema::hasColumn('atlas_ledger_events', 'chain_basis')
            && Schema::hasColumn('atlas_ledger_events', 'occurred_at')
            && ! Schema::hasIndex('atlas_ledger_events', 'atlas_ledger_events_scope_chain_head_index')) {
            Schema::table('atlas_ledger_events', function (Blueprint $table): void {
                $table->index(
                    ['scope_type', 'scope_id', 'chain_basis', 'occurred_at'],
                    'atlas_ledger_events_scope_chain_head_index',
                );
            });
        }

        if (Schema::hasColumn('atlas_ledger_events', 'correlation_id')
            && Schema::hasColumn('atlas_ledger_events', 'chain_basis')
            && Schema::hasColumn('atlas_ledger_events', 'occurred_at')
            && ! Schema::hasIndex('atlas_ledger_events', 'atlas_ledger_events_correlation_chain_head_index')) {
            Schema::table('atlas_ledger_events', function (Blueprint $table): void {
                $table->index(
                    ['correlation_id', 'chain_basis', 'occurred_at'],
                    'atlas_ledger_events_correlation_chain_head_index',
                );
            });
        }

        DB::table('atlas_ledger_events')
            ->whereNull('chain_basis')
            ->update(['chain_basis' => 'legacy_unchained']);
    }

    public function down(): void
    {
        // Evidence is append-only; a repair rollback must not delete ledger bytes.
    }

    private function ensureIndex(string $column, string $index): void
    {
        if (! Schema::hasColumn('atlas_ledger_events', $column)
            || Schema::hasIndex('atlas_ledger_events', $index)) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table) use ($column, $index): void {
            $table->index($column, $index);
        });
    }
};
