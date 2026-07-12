<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int,string> */
    private array $tables = [
        'semantic_notes',
        'atlas_memory_entries',
        'atlas_verbatim_memories',
        'ai_attachment_index_entries',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $addModel = ! Schema::hasColumn($table, 'embedding_model');
            $addHash = ! Schema::hasColumn($table, 'embedded_content_hash');
            if (! $addModel && ! $addHash) {
                $this->ensureModelIndex($table);

                continue;
            }

            Schema::table($table, function (Blueprint $schema) use ($addModel, $addHash): void {
                if ($addModel) {
                    $schema->string('embedding_model', 255)->nullable()->after('embedding');
                }
                if ($addHash) {
                    $schema->string('embedded_content_hash', 64)->nullable()->after('embedding_model');
                }
            });

            $this->ensureModelIndex($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('DROP INDEX IF EXISTS '.$this->indexName($table));
            }

            Schema::table($table, function (Blueprint $schema) use ($table): void {
                if (Schema::hasColumn($table, 'embedded_content_hash')) {
                    $schema->dropColumn('embedded_content_hash');
                }
                if (Schema::hasColumn($table, 'embedding_model')) {
                    $schema->dropColumn('embedding_model');
                }
            });
        }
    }

    private function ensureModelIndex(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn($table, 'embedding_model')) {
            return;
        }

        $index = $this->indexName($table);
        $exists = (bool) DB::scalar(
            'SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?)',
            [$index],
        );
        if (! $exists) {
            DB::statement("CREATE INDEX {$index} ON {$table} (embedding_model) WHERE embedding IS NOT NULL;");
        }
    }

    private function indexName(string $table): string
    {
        return 'idx_'.$table.'_embedding_model';
    }
};
