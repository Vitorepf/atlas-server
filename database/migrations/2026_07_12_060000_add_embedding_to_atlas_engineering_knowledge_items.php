<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAXA-06 (fase 1) — dense-search coverage on atlas_engineering_knowledge_items.
 *
 * Adds MAXA-03 provenance columns (`embedding_model`, `embedded_content_hash`)
 * on ALL drivers so the coverage ruler works on sqlite too (missing/stale
 * detection is provenance-based, not vector-based). The `embedding` halfvec
 * column + HNSW cosine index are pgvector-specific and only created on pgsql;
 * sqlite is a clean no-op and recall honestly degrades to lexical.
 *
 * Ordering with MAXA-07: HNSW-by-construction; matches the pattern the memory
 * vector tables use (m=16, ef_construction=64).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return;
        }

        Schema::table('atlas_engineering_knowledge_items', function ($table): void {
            if (! Schema::hasColumn('atlas_engineering_knowledge_items', 'embedding_model')) {
                $table->string('embedding_model', 120)->nullable()->index();
            }
            if (! Schema::hasColumn('atlas_engineering_knowledge_items', 'embedded_content_hash')) {
                $table->string('embedded_content_hash', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('atlas_engineering_knowledge_items', 'embedded_at')) {
                $table->timestamp('embedded_at')->nullable()->index();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('atlas_engineering_knowledge_items', 'embedding')) {
            $dim = $this->embeddingDimension();
            DB::statement("ALTER TABLE atlas_engineering_knowledge_items ADD COLUMN embedding vector({$dim});");
            DB::statement('CREATE INDEX IF NOT EXISTS idx_atlas_engineering_knowledge_items_embedding ON atlas_engineering_knowledge_items USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64);');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_atlas_engineering_knowledge_items_embedding;');
            if (Schema::hasColumn('atlas_engineering_knowledge_items', 'embedding')) {
                DB::statement('ALTER TABLE atlas_engineering_knowledge_items DROP COLUMN IF EXISTS embedding;');
            }
        }

        Schema::table('atlas_engineering_knowledge_items', function ($table): void {
            foreach (['embedded_at', 'embedded_content_hash', 'embedding_model'] as $col) {
                if (Schema::hasColumn('atlas_engineering_knowledge_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

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
};
