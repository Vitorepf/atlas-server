<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,string> */
    private array $targets = [
        'atlas_memory_entries' => 'idx_atlas_memory_entries_embedding',
        'atlas_verbatim_memories' => 'idx_atlas_verbatim_memories_embedding',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $dimension = $this->embeddingDimension();
        foreach ($this->targets as $table => $index) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'embedding')) {
                DB::statement("ALTER TABLE {$table} ADD COLUMN embedding vector({$dimension});");
            }
            if (! $this->indexExists($index)) {
                DB::statement(
                    "CREATE INDEX {$index} ON {$table} USING ivfflat (embedding vector_cosine_ops);",
                );
            }
        }
    }

    /**
     * Repair migrations never remove a canonical semantic index on rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible.
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

    private function indexExists(string $index): bool
    {
        return (bool) DB::scalar(
            'SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?)',
            [$index],
        );
    }
};
