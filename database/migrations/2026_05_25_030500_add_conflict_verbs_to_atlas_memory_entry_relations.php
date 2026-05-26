<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Cognition Operating System — Absorcao 2 (Conflict Verbs).
 *
 * Estende `atlas_memory_entry_relations` com colunas canonicas para suportar
 * os seis verbos de conflito vindos da dissecacao Engram:
 *   - related | compatible | scoped | conflicts_with | supersedes | not_conflict
 *
 * Schema canon: `atlas.memory.relation_verdict.v1`.
 * Doc canon mae: `atlas-cognition-operating-system.md`.
 * Doc absorcao: `atlas-external-memory-pattern-absorptions-v1.md` (Absorcao 2).
 *
 * PHASE 1 (esta migration):
 *  - Adiciona `marked_by_actor` (agent|engram|atlas|human|unknown).
 *  - Adiciona `marked_by_model` (string opcional, identifica modelo do agent).
 *  - Adiciona `judgment_status` (pending|judged|orphaned|ignored).
 *  - Adiciona `evidence_refs` (JSON, refs para Evidence Ledger).
 *  - Mantem UNIQUE(source, target, relation_type) por back-compat.
 *  - relation_type continua VARCHAR(32) — service AtlasMemoryConflictResolutionService
 *    valida em PHP os seis verbos canonicos antes de inserir.
 *
 * PHASE 2 (proxima AP):
 *  - Dropar UNIQUE(source, target, relation_type) para permitir multi-actor
 *    disagreement (agente + humano podem ter verdicts divergentes auditavelmente).
 *  - Add CHECK constraint em relation_type para os seis verbos + status pending.
 *  - Backfill semantica historica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_memory_entry_relations', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_memory_entry_relations', 'marked_by_actor')) {
                $table->string('marked_by_actor', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('atlas_memory_entry_relations', 'marked_by_model')) {
                $table->string('marked_by_model', 128)->nullable();
            }
            if (! Schema::hasColumn('atlas_memory_entry_relations', 'judgment_status')) {
                $table->string('judgment_status', 24)->default('pending')->index();
            }
            if (! Schema::hasColumn('atlas_memory_entry_relations', 'evidence_refs')) {
                $table->json('evidence_refs')->nullable();
            }
            if (! Schema::hasColumn('atlas_memory_entry_relations', 'verdict_schema_version')) {
                $table->string('verdict_schema_version', 64)->default('atlas.memory.relation_verdict.v1');
            }
        });

        // Indice especifico para queries de conflict surface.
        if (! $this->indexExists('atlas_memory_entry_relations', 'idx_atlas_memory_relation_verdict')) {
            Schema::table('atlas_memory_entry_relations', function (Blueprint $table): void {
                $table->index(
                    ['relation_type', 'judgment_status', 'marked_by_actor'],
                    'idx_atlas_memory_relation_verdict',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('atlas_memory_entry_relations', function (Blueprint $table): void {
            if ($this->indexExists('atlas_memory_entry_relations', 'idx_atlas_memory_relation_verdict')) {
                $table->dropIndex('idx_atlas_memory_relation_verdict');
            }
            foreach (['marked_by_actor', 'marked_by_model', 'judgment_status', 'evidence_refs', 'verdict_schema_version'] as $col) {
                if (Schema::hasColumn('atlas_memory_entry_relations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $schema = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $schema->listTableIndexes($table);

            return array_key_exists($index, $indexes);
        } catch (\Throwable) {
            return false;
        }
    }
};
