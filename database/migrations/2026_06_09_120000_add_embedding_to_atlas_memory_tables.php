<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 — make the Atlas Memory Core SEMANTICALLY searchable.
 *
 * `atlas_memory_entries` + `atlas_verbatim_memories` were retrieved KEYWORD-only
 * (scope SQL filter + substring lexical score) and had NO embedding column —
 * real vector search existed only for `semantic_notes`. This adds a pgvector
 * `embedding` column + an ivfflat cosine index to both tables, sized to the REAL
 * local model dimension (fastembed BAAI/bge-small = 384-d by config), mirroring
 * the `semantic_notes` precedent (create_semantic_memory_tables +
 * align_semantic_embedding_dimension_to_real_model).
 *
 * pgvector-specific: the sqlite test DB has no vector type, so on sqlite this is
 * a clean no-op and recall HONESTLY degrades to the existing lexical path.
 * Rows are embedded on-write by the registry/verbatim services and existing
 * rows are backfilled by `atlas:memory:embed-backfill`.
 *
 * @see database/migrations/2026_04_28_050000_create_semantic_memory_tables.php
 * @see database/migrations/2026_06_08_200000_align_semantic_embedding_dimension_to_real_model.php
 */
return new class extends Migration
{
    /** @var array<string,string> table => cosine index name */
    private array $targets = [
        'atlas_memory_entries' => 'idx_atlas_memory_entries_embedding',
        'atlas_verbatim_memories' => 'idx_atlas_verbatim_memories_embedding',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // pgvector-specific; sqlite test DB has no vector type — lexical fallback stays canonical.
        }

        $dim = $this->embeddingDimension();

        foreach ($this->targets as $table => $index) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'embedding')) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} ADD COLUMN embedding vector({$dim});");
            DB::statement("CREATE INDEX {$index} ON {$table} USING ivfflat (embedding vector_cosine_ops);");
        }
    }

    /**
     * Size the column to the dimension the REAL embedding engine actually emits.
     *
     * `semantic_notes.embedding` is the canonical, model-aligned reference (it is
     * re-sized to the real model by align_semantic_embedding_dimension_to_real_model
     * and re-embedded with real vectors). Mirroring it keeps the memory columns
     * consistent with the live engine even when the
     * `ATLAS_SEMANTIC_EMBEDDING_DIMENSIONS` env has drifted (e.g. a stale 1536
     * left over from the OpenAI era). Falls back to config when that table or
     * column is absent.
     */
    private function embeddingDimension(): int
    {
        if (Schema::hasTable('semantic_notes') && Schema::hasColumn('semantic_notes', 'embedding')) {
            $typmod = DB::scalar(
                "SELECT atttypmod FROM pg_attribute WHERE attrelid = 'semantic_notes'::regclass AND attname = 'embedding'",
            );
            if (is_numeric($typmod) && (int) $typmod > 0) {
                return (int) $typmod;
            }
        }

        return max(1, (int) config('atlas.semantic_memory.embedding_dimensions', 384));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->targets as $table => $index) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("DROP INDEX IF EXISTS {$index};");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS embedding;");
        }
    }
};
