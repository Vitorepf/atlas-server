<?php

declare(strict_types=1);

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
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
            if (! Schema::hasColumn('atlas_ledger_events', 'event_hash')) {
                $table->string('event_hash', 64)->nullable()->index('atlas_ledger_events_event_hash_index');
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_type')) {
                $table->string('scope_type', 40)->nullable()->index('atlas_ledger_events_scope_type_index');
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_id')) {
                $table->string('scope_id', 80)->nullable()->index('atlas_ledger_events_scope_id_index');
            }
        });

        $this->ensureIndex('event_hash', 'atlas_ledger_events_event_hash_index');
        $this->ensureIndex('scope_type', 'atlas_ledger_events_scope_type_index');
        $this->ensureIndex('scope_id', 'atlas_ledger_events_scope_id_index');

        if (Schema::hasColumn('atlas_ledger_events', 'scope_type')
            && Schema::hasColumn('atlas_ledger_events', 'scope_id')
            && Schema::hasColumn('atlas_ledger_events', 'occurred_at')
            && ! Schema::hasIndex('atlas_ledger_events', 'atlas_ledger_events_scope_occurred_index')) {
            Schema::table('atlas_ledger_events', function (Blueprint $table): void {
                $table->index(
                    ['scope_type', 'scope_id', 'occurred_at'],
                    'atlas_ledger_events_scope_occurred_index',
                );
            });
        }

        $this->backfillEventHashes();
    }

    public function down(): void
    {
        // Evidence is append-only; a repair rollback must not delete ledger bytes.
    }

    private function backfillEventHashes(): void
    {
        foreach ([
            'event_hash',
            'event_id',
            'event_type',
            'envelope_id',
            'correlation_id',
            'payload_hash',
            'occurred_at',
        ] as $column) {
            if (! Schema::hasColumn('atlas_ledger_events', $column)) {
                return;
            }
        }

        $hasCausationId = Schema::hasColumn('atlas_ledger_events', 'causation_id');
        $hasScopeType = Schema::hasColumn('atlas_ledger_events', 'scope_type');
        $hasScopeId = Schema::hasColumn('atlas_ledger_events', 'scope_id');
        $columns = array_values(array_filter([
            'event_id',
            'event_type',
            'envelope_id',
            'correlation_id',
            $hasCausationId ? 'causation_id' : null,
            $hasScopeType ? 'scope_type' : null,
            $hasScopeId ? 'scope_id' : null,
            'payload_hash',
            'occurred_at',
        ]));

        DB::table('atlas_ledger_events')
            ->select($columns)
            ->where(static function ($query): void {
                $query->whereNull('event_hash')->orWhere('event_hash', '');
            })
            ->chunkById(500, function ($rows) use ($hasCausationId, $hasScopeType, $hasScopeId): void {
                foreach ($rows as $row) {
                    DB::table('atlas_ledger_events')
                        ->where('event_id', (string) $row->event_id)
                        ->where(static function ($query): void {
                            $query->whereNull('event_hash')->orWhere('event_hash', '');
                        })
                        ->update([
                            'event_hash' => AtlasEvidenceLedger::computeEventHash([
                                'event_id' => (string) $row->event_id,
                                'event_type' => (string) $row->event_type,
                                'envelope_id' => (string) $row->envelope_id,
                                'correlation_id' => (string) $row->correlation_id,
                                'causation_id' => $hasCausationId ? $row->causation_id : null,
                                'scope_type' => $hasScopeType ? $row->scope_type : null,
                                'scope_id' => $hasScopeId ? $row->scope_id : null,
                                'payload_hash' => (string) $row->payload_hash,
                                'occurred_at' => (string) $row->occurred_at,
                            ]),
                        ]);
                }
            }, 'event_id');
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
