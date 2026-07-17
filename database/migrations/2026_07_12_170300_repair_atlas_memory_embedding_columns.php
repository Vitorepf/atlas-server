<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        $dimension = max(1, (int) config('atlas.semantic_memory.embedding_dimensions', 384));
        if (! Schema::hasColumn('atlas_memory_entries', 'embedding')) {
            DB::statement("ALTER TABLE atlas_memory_entries ADD COLUMN embedding vector({$dimension});");
        }

        $index = 'idx_atlas_memory_entries_embedding';
        $exists = (bool) DB::scalar(
            'SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?)',
            [$index],
        );
        if (! $exists) {
            DB::statement("CREATE INDEX {$index} ON atlas_memory_entries USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64);");
        }
    }

    public function down(): void
    {
        // Repair migrations are intentionally non-destructive.
    }
};
