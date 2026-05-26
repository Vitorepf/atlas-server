<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Cognition Operating System — Absorcao 2 (Conflict Verbs).
 *
 * Espelha a tabela `atlas_memory_entry_relations` COM as colunas adicionadas
 * pela migration `2026_05_25_030500_add_conflict_verbs_to_atlas_memory_entry_relations`:
 *   - marked_by_actor, marked_by_model, judgment_status, evidence_refs, verdict_schema_version
 *
 * Substitui o trait base `CreatesAtlasMemoryEntryRelationsTable` em feature tests
 * que validam Absorcao 2.
 */
trait CreatesAtlasMemoryEntryRelationsConflictVerbsTable
{
    protected function createAtlasMemoryEntryRelationsConflictVerbsTable(): void
    {
        $this->dropAtlasMemoryEntryRelationsConflictVerbsTable();

        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 32)->index();
            $table->string('status', 24)->default('open')->index();
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');

            // Colunas da Absorcao 2 (Conflict Verbs).
            $table->string('marked_by_actor', 32)->nullable()->index();
            $table->string('marked_by_model', 128)->nullable();
            $table->string('judgment_status', 24)->default('pending')->index();
            $table->json('evidence_refs')->nullable();
            $table->string('verdict_schema_version', 64)->default('atlas.memory.relation_verdict.v1');

            $table->timestamps();

            $table->unique(
                ['source_memory_entry_id', 'target_memory_entry_id', 'relation_type'],
                'uniq_atlas_memory_relation_pair_type',
            );
            $table->index(['relation_type', 'status'], 'idx_atlas_memory_rel_type_status');
            $table->index(
                ['relation_type', 'judgment_status', 'marked_by_actor'],
                'idx_atlas_memory_relation_verdict',
            );
        });
    }

    protected function dropAtlasMemoryEntryRelationsConflictVerbsTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
    }
}
