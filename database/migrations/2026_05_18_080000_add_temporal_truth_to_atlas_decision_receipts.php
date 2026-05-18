<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEOS-I1 / M2 — Temporal Truth Fields on `atlas_decision_receipts`.
 *
 * 8 nullable columns; index on `stale_after` per the increment-1 plan.
 * Aditive only — runtime behaviour unchanged, callers that ignore the new
 * fields continue to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_decision_receipts')) {
            return;
        }

        Schema::table('atlas_decision_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_decision_receipts', 'valid_from')) {
                $table->timestamp('valid_from')->nullable()->after('revoked_reason');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'valid_until')) {
                $table->timestamp('valid_until')->nullable()->after('valid_from');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'observed_at')) {
                $table->timestamp('observed_at')->nullable()->after('valid_until');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('observed_at');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'stale_after')) {
                $table->timestamp('stale_after')->nullable()->after('verified_at');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'source_hash')) {
                $table->string('source_hash', 64)->nullable()->after('stale_after');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'superseded_by')) {
                $table->uuid('superseded_by')->nullable()->after('source_hash');
            }
            if (! Schema::hasColumn('atlas_decision_receipts', 'authority_level')) {
                $table->string('authority_level', 40)->nullable()->after('superseded_by');
            }
        });

        Schema::table('atlas_decision_receipts', function (Blueprint $table): void {
            $existingIndexes = $this->indexNames('atlas_decision_receipts');
            if (! in_array('atlas_decision_receipts_stale_after_index', $existingIndexes, true)) {
                $table->index('stale_after');
            }
            if (! in_array('atlas_decision_receipts_superseded_by_index', $existingIndexes, true)) {
                $table->index('superseded_by');
            }
            if (! in_array('atlas_decision_receipts_authority_level_index', $existingIndexes, true)) {
                $table->index('authority_level');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_decision_receipts')) {
            return;
        }

        Schema::table('atlas_decision_receipts', function (Blueprint $table): void {
            foreach (['stale_after', 'superseded_by', 'authority_level'] as $column) {
                try {
                    $table->dropIndex(['atlas_decision_receipts_'.$column.'_index']);
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
                'superseded_by',
                'authority_level',
            ] as $column) {
                if (Schema::hasColumn('atlas_decision_receipts', $column)) {
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
