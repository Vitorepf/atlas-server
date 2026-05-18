<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEOS-I1 / M2 — Temporal Truth Fields on `ai_codebase_world_model_edges`.
 *
 * 8 nullable columns. The increment-1 plan specifies only `valid_until` +
 * `superseded_by` as strictly required here, but we add all 8 for symmetry
 * with the other two targets — every field is nullable, so consumers that
 * only need 2 ignore the rest. Aditive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            return;
        }

        Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'valid_from')) {
                $table->timestamp('valid_from')->nullable()->after('metadata');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'valid_until')) {
                $table->timestamp('valid_until')->nullable()->after('valid_from');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'observed_at')) {
                $table->timestamp('observed_at')->nullable()->after('valid_until');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('observed_at');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'stale_after')) {
                $table->timestamp('stale_after')->nullable()->after('verified_at');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'source_hash')) {
                $table->string('source_hash', 64)->nullable()->after('stale_after');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'superseded_by')) {
                $table->uuid('superseded_by')->nullable()->after('source_hash');
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'authority_level')) {
                $table->string('authority_level', 40)->nullable()->after('superseded_by');
            }
        });

        Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
            $existingIndexes = $this->indexNames('ai_codebase_world_model_edges');
            if (! in_array('ai_codebase_world_model_edges_stale_after_index', $existingIndexes, true)) {
                $table->index('stale_after');
            }
            if (! in_array('ai_codebase_world_model_edges_superseded_by_index', $existingIndexes, true)) {
                $table->index('superseded_by');
            }
            if (! in_array('ai_codebase_world_model_edges_authority_level_index', $existingIndexes, true)) {
                $table->index('authority_level');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            return;
        }

        Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
            foreach (['stale_after', 'superseded_by', 'authority_level'] as $column) {
                try {
                    $table->dropIndex(['ai_codebase_world_model_edges_'.$column.'_index']);
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
                if (Schema::hasColumn('ai_codebase_world_model_edges', $column)) {
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
