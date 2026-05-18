<?php

use App\Support\TemporalTruth\HasTemporalTruth;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEOS-I1 / M2 — Temporal Truth Fields on `atlas_memory_entries`.
 *
 * 7 NEW nullable columns. The legacy `superseded_by_id` column predates TEOS
 * and remains the authoritative pointer for memory supersession — the
 * {@see HasTemporalTruth} trait treats it as
 * canonical via `legacySupersededColumn()`. We DO NOT add a duplicate
 * `superseded_by` column on this table to avoid two-way drift.
 *
 * Aditive only — runtime behaviour unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_memory_entries', 'valid_from')) {
                $table->timestamp('valid_from')->nullable()->after('recorded_at');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'valid_until')) {
                $table->timestamp('valid_until')->nullable()->after('valid_from');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'observed_at')) {
                $table->timestamp('observed_at')->nullable()->after('valid_until');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('observed_at');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'stale_after')) {
                $table->timestamp('stale_after')->nullable()->after('verified_at');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'source_hash')) {
                $table->string('source_hash', 64)->nullable()->after('stale_after');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'authority_level')) {
                $table->string('authority_level', 40)->nullable()->after('source_hash');
            }
        });

        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            $existingIndexes = $this->indexNames('atlas_memory_entries');
            if (! in_array('atlas_memory_entries_stale_after_index', $existingIndexes, true)) {
                $table->index('stale_after');
            }
            if (! in_array('atlas_memory_entries_authority_level_index', $existingIndexes, true)) {
                $table->index('authority_level');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            foreach (['stale_after', 'authority_level'] as $column) {
                try {
                    $table->dropIndex(['atlas_memory_entries_'.$column.'_index']);
                } catch (Throwable) {
                    // best-effort rollback
                }
            }
            foreach ([
                'valid_from',
                'valid_until',
                'observed_at',
                'verified_at',
                'stale_after',
                'source_hash',
                'authority_level',
            ] as $column) {
                if (Schema::hasColumn('atlas_memory_entries', $column)) {
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
            $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $schemaManager->listTableIndexes($table);

            return array_keys($indexes);
        } catch (Throwable) {
            return [];
        }
    }
};
