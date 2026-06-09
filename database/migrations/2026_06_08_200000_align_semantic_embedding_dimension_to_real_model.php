<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align the pgvector embedding columns to the REAL Python embedding model's
 * dimension. The columns were VECTOR(1536) sized for the (defaulted) crc32 hash
 * fake / OpenAI; the sovereign local model (fastembed BAAI/bge-small) is 384-d.
 *
 * The existing stored vectors were the crc32-hash FAKE (provider=local_hash) —
 * worthless, so dropping them loses no real signal. Rows are re-embedded with
 * real vectors by atlas:semantic:reembed right after this runs.
 *
 * Postgres/pgvector cannot cast VECTOR(1536)->VECTOR(384), so the column is
 * dropped and re-added at the configured dimension and the ivfflat index rebuilt.
 */
return new class extends Migration
{
    /** @var array<string,string> table => cosine index name */
    private array $targets = [
        'semantic_notes' => 'idx_semantic_notes_embedding',
        'ai_attachment_index_entries' => 'idx_ai_attachment_index_embedding',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // pgvector-specific; sqlite test DB has no vector type
        }
        $dim = (int) config('atlas.semantic_memory.embedding_dimensions', 384);

        foreach ($this->targets as $table => $index) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement("DROP INDEX IF EXISTS {$index};");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS embedding;");
            DB::statement("ALTER TABLE {$table} ADD COLUMN embedding vector({$dim});");
            DB::statement("CREATE INDEX {$index} ON {$table} USING ivfflat (embedding vector_cosine_ops);");
        }
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
            DB::statement("ALTER TABLE {$table} ADD COLUMN embedding vector(1536);");
            DB::statement("CREATE INDEX {$index} ON {$table} USING ivfflat (embedding vector_cosine_ops);");
        }
    }
};
