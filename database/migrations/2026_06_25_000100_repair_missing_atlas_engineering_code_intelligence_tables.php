<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent repair migration: re-creates Code Intelligence read-model tables that exist in
 * the migration ledger but are physically missing from the database, and back-fills
 * workspace_id + workspace-scoped unique indexes when the base tables exist without them.
 *
 * Never writes to the `migrations` table — the ledger is owned by the Laravel migrator.
 */
return new class extends Migration
{
    private const DEFAULT_WORKSPACE = 'atlas-server';

    public function up(): void
    {
        $this->ensureModulesTable();
        $this->ensureSymbolsTable();
        $this->ensureDocLinksTable();
        $this->ensureFileSnapshotsTable();

        $this->repairWorkspaceKeying();
    }

    public function down(): void
    {
        // Repair-only migration: no destructive rollback.
    }

    private function ensureModulesTable(): void
    {
        if (Schema::hasTable('atlas_engineering_code_modules')) {
            return;
        }
        Schema::create('atlas_engineering_code_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 160)->default(self::DEFAULT_WORKSPACE)->index();
            $table->string('slug', 160);
            $table->string('name', 220);
            $table->string('layer', 80)->index();
            $table->string('root_path', 500)->nullable()->index();
            $table->string('primary_language', 40)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('owner', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('symbol_count')->default(0);
            $table->unsignedInteger('route_count')->default(0);
            $table->unsignedInteger('command_count')->default(0);
            $table->unsignedInteger('migration_count')->default(0);
            $table->unsignedInteger('test_count')->default(0);
            $table->string('source_hash', 64)->index();
            $table->string('docs_hash', 64)->nullable()->index();
            $table->json('tags_json')->default('[]');
            $table->json('related_docs_json')->default('[]');
            $table->json('related_tests_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug'], 'uniq_atlas_eng_modules_ws_slug');
            $table->index(['layer', 'status', 'docs_status'], 'idx_atlas_eng_code_modules_layer_status');
        });
    }

    private function ensureSymbolsTable(): void
    {
        if (Schema::hasTable('atlas_engineering_code_symbols')) {
            return;
        }
        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 160)->default(self::DEFAULT_WORKSPACE)->index();
            $table->uuid('module_id')->nullable()->index();
            $table->string('symbol_type', 60)->index();
            $table->string('symbol_name', 300)->index();
            $table->string('file_path', 500)->index();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->string('language', 40)->nullable()->index();
            $table->text('signature')->nullable();
            $table->string('namespace', 220)->nullable();
            $table->string('parent_symbol', 300)->nullable()->index();
            $table->string('visibility', 40)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->string('source_hash', 64)->index();
            $table->json('related_doc_ids_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['workspace_id', 'symbol_type', 'source_hash'], 'uniq_atlas_eng_symbols_ws_source');
            $table->index(['symbol_type', 'status', 'docs_status'], 'idx_atlas_eng_code_symbols_type_status');
        });
    }

    private function ensureDocLinksTable(): void
    {
        if (Schema::hasTable('atlas_engineering_doc_links')) {
            return;
        }
        Schema::create('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 160)->default(self::DEFAULT_WORKSPACE)->index();
            $table->uuid('knowledge_item_id')->nullable()->index();
            $table->uuid('module_id')->nullable()->index();
            $table->uuid('symbol_id')->nullable()->index();
            $table->string('link_type', 60)->index();
            $table->string('status', 40)->default('current')->index();
            $table->string('canonical_path', 500)->index();
            $table->string('target_path', 500)->nullable()->index();
            $table->string('doc_hash', 64)->nullable()->index();
            $table->string('target_hash', 64)->nullable()->index();
            $table->string('link_hash', 64);
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['workspace_id', 'link_hash'], 'uniq_atlas_eng_doc_links_ws_hash');
            $table->index(['link_type', 'status'], 'idx_atlas_eng_doc_links_type_status');
        });
    }

    private function ensureFileSnapshotsTable(): void
    {
        if (Schema::hasTable('atlas_engineering_code_file_snapshots')) {
            return;
        }
        Schema::create('atlas_engineering_code_file_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 160)->default(self::DEFAULT_WORKSPACE)->index();
            $table->string('file_path', 500);
            $table->string('module_slug', 160)->index();
            $table->string('language', 40)->nullable()->index();
            $table->string('source_hash', 64)->index();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('symbols_json')->default('[]');
            $table->json('relations_json')->default('{}');
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['workspace_id', 'file_path'], 'uniq_atlas_eng_file_snap_ws_path');
            $table->index(['status', 'archived_at', 'source_hash'], 'idx_atlas_eng_file_snapshots_active_hash');
            $table->index(['status', 'archived_at', 'module_slug'], 'idx_atlas_eng_file_snapshots_active_module');
        });
    }

    /** @return list<array{table:string,new:string,cols:array<int,string>}> */
    private function workspaceKeyingPlan(): array
    {
        return [
            ['table' => 'atlas_engineering_code_modules', 'new' => 'uniq_atlas_eng_modules_ws_slug', 'cols' => ['workspace_id', 'slug']],
            ['table' => 'atlas_engineering_code_symbols', 'new' => 'uniq_atlas_eng_symbols_ws_source', 'cols' => ['workspace_id', 'symbol_type', 'source_hash']],
            ['table' => 'atlas_engineering_doc_links', 'new' => 'uniq_atlas_eng_doc_links_ws_hash', 'cols' => ['workspace_id', 'link_hash']],
            ['table' => 'atlas_engineering_code_file_snapshots', 'new' => 'uniq_atlas_eng_file_snap_ws_path', 'cols' => ['workspace_id', 'file_path']],
        ];
    }

    private function repairWorkspaceKeying(): void
    {
        foreach ($this->workspaceKeyingPlan() as $entry) {
            $this->addWorkspaceColumn($entry['table']);
        }
        foreach ($this->workspaceKeyingPlan() as $entry) {
            $this->ensureCompositeUniqueIndex($entry['table'], $entry['new'], $entry['cols']);
        }
    }

    private function addWorkspaceColumn(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'workspace_id')) {
            return;
        }
        Schema::table($table, static function (Blueprint $t): void {
            $t->string('workspace_id', 160)->default(self::DEFAULT_WORKSPACE)->index();
        });
        DB::table($table)->whereNull('workspace_id')->update(['workspace_id' => self::DEFAULT_WORKSPACE]);
    }

    /** @param array<int,string> $cols */
    private function ensureCompositeUniqueIndex(string $table, string $name, array $cols): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($cols as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return;
            }
        }

        $driver = DB::connection()->getDriverName();
        $colList = implode(', ', $cols);

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$table} ({$colList})");

            return;
        }

        try {
            Schema::table($table, static function (Blueprint $t) use ($cols, $name): void {
                $t->unique($cols, $name);
            });
        } catch (\Throwable) {
            // already exists under this name — safe to skip.
        }
    }
};
