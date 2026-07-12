<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAXA-06 fase 2 — code symbol dense-search substrate.
 *
 * Creates `atlas_code_symbol_embeddings` — a SEPARATE table (not a column on
 * `atlas_engineering_code_symbols`) so the 290k+ rows never carry a null halfvec
 * column on sqlite and so backfill/incremental re-embed cascades by
 * `symbol_source_hash` never require touching the primary code symbols table.
 *
 * Structure mirrors the pattern that MAXA-06 fase 1 used for KB items:
 *   - provenance columns (`embedding_model`, `embedded_content_hash`,
 *     `embedded_at`, `source_hash`) exist on ALL drivers so the ruler is
 *     sqlite-testable (missing/stale detection is provenance-based),
 *   - the `embedding` halfvec column + HNSW cosine index are pgvector-only
 *     (m=16, ef_construction=64 — the MAXA-07 pattern),
 *   - sqlite is a clean no-op past provenance; recall honestly degrades to
 *     lexical when the vector column is absent.
 *
 * The actual switch that promotes code_symbol embeddings into the retrieval
 * hot path stays default-OFF (the plan says fase 2 is a switch, not a
 * schema-only landing); this migration is the *substrate* — the switch and
 * the backfill live in follow-up work behind flag `atlas.acos_max.code_symbol_embeddings_enabled`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_code_symbol_embeddings')) {
            return;
        }

        Schema::create('atlas_code_symbol_embeddings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('symbol_id')->index();
            $table->string('symbol_source_hash', 64)->index();
            $table->string('embedding_model', 120)->index();
            $table->string('embedded_content_hash', 64)->index();
            $table->timestamp('embedded_at')->nullable()->index();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['symbol_id', 'embedding_model'], 'uniq_atlas_code_symbol_embeddings_symbol_model');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('atlas_code_symbol_embeddings', 'embedding')) {
            $dim = $this->embeddingDimension();
            DB::statement("ALTER TABLE atlas_code_symbol_embeddings ADD COLUMN embedding vector({$dim});");
            DB::statement('CREATE INDEX IF NOT EXISTS idx_atlas_code_symbol_embeddings_embedding ON atlas_code_symbol_embeddings USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64);');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_code_symbol_embeddings')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_atlas_code_symbol_embeddings_embedding;');
        }

        Schema::dropIfExists('atlas_code_symbol_embeddings');
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
