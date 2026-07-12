<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAXA-05 — persist ASEF chunks as a real pgvector index (leave manifest-only behind).
 *
 * Child table `asef_chunks` carries the fields already defined by
 * AtlasSemanticEmbeddingFoundationService::candidateSet() plus MAXA-03 provenance
 * and a pgsql-only embedding + HNSW index (MAXA-07 pattern: m=16, ef_construction=64).
 *
 * sqlite keeps provenance/metadata so chunk→doc + delete-cascade are testable without
 * pgvector; vector recall honestly degrades when the embedding column is absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asef_chunks')) {
            return;
        }

        Schema::create('asef_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('chunk_id', 64)->unique();
            $table->string('source_ref', 512)->index();
            $table->string('source_hash', 64)->index();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->string('chunk_hash', 64)->index();
            $table->string('title', 512)->default('');
            $table->string('section', 512)->default('');
            $table->text('chunk_text');
            $table->text('embedded_text');
            $table->string('privacy_class', 32)->default('normal')->index();
            $table->boolean('provider_safe')->default(true);
            $table->string('delete_cascade_key', 80)->index();
            $table->string('embedding_model', 120)->nullable()->index();
            $table->string('embedded_content_hash', 64)->nullable()->index();
            $table->timestamp('embedded_at')->nullable()->index();
            $table->string('embedding_status', 64)->default('pending');
            $table->timestamps();

            $table->unique(['source_ref', 'chunk_hash'], 'uniq_asef_chunks_source_chunk_hash');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('asef_chunks', 'embedding')) {
            $dim = $this->embeddingDimension();
            DB::statement("ALTER TABLE asef_chunks ADD COLUMN embedding vector({$dim});");
            DB::statement('CREATE INDEX IF NOT EXISTS idx_asef_chunks_embedding ON asef_chunks USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64);');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('asef_chunks')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_asef_chunks_embedding;');
        }

        Schema::dropIfExists('asef_chunks');
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
