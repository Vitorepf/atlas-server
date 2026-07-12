<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,string> table => cosine index name */
    private array $targets = [
        'semantic_notes' => 'idx_semantic_notes_embedding',
        'ai_attachment_index_entries' => 'idx_ai_attachment_index_embedding',
        'atlas_memory_entries' => 'idx_atlas_memory_entries_embedding',
        'atlas_verbatim_memories' => 'idx_atlas_verbatim_memories_embedding',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->targets as $table => $index) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'embedding')) {
                continue;
            }

            DB::statement("DROP INDEX IF EXISTS {$index};");
            DB::statement("CREATE INDEX {$index} ON {$table} USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64);");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->targets as $table => $index) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'embedding')) {
                continue;
            }

            DB::statement("DROP INDEX IF EXISTS {$index};");
            DB::statement("CREATE INDEX {$index} ON {$table} USING ivfflat (embedding vector_cosine_ops);");
        }
    }
};
