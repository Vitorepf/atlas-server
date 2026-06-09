<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AP-815 · W-1 (keystone) — Workspace keying for the code-intelligence read-model.
 *
 * Adds a NOT-NULL `workspace_id` (default 'atlas-server', so existing rows backfill on
 * add) to the code-graph read-model tables, and SWAPS the global unique indexes for
 * workspace-scoped composite ones — so a second project no longer collides with
 * atlas-server on slug / (symbol_type,source_hash) / link_hash / file_path.
 *
 * Cross-driver: pgsql (prod) — where Laravel `->unique()` created CONSTRAINTS for
 * modules/doc_links/file_snapshots but the symbols unique is a raw INDEX (repair
 * migration) — and sqlite (tests). Additive + idempotent (guards on hasTable/hasColumn,
 * IF EXISTS / IF NOT EXISTS), so it is safe to re-run and never breaks the single-workspace
 * behaviour (the default keeps results byte-identical for atlas-server).
 */
return new class extends Migration
{
    private const DEFAULT_WORKSPACE = 'atlas-server';

    /** @var array<int,array{table:string,old:string,new:string,cols:array<int,string>}> */
    private function swaps(): array
    {
        return [
            ['table' => 'atlas_engineering_code_modules', 'old' => 'atlas_engineering_code_modules_slug_unique', 'new' => 'uniq_atlas_eng_modules_ws_slug', 'cols' => ['workspace_id', 'slug']],
            ['table' => 'atlas_engineering_code_symbols', 'old' => 'uniq_atlas_eng_code_symbol_source', 'new' => 'uniq_atlas_eng_symbols_ws_source', 'cols' => ['workspace_id', 'symbol_type', 'source_hash']],
            ['table' => 'atlas_engineering_doc_links', 'old' => 'atlas_engineering_doc_links_link_hash_unique', 'new' => 'uniq_atlas_eng_doc_links_ws_hash', 'cols' => ['workspace_id', 'link_hash']],
            ['table' => 'atlas_engineering_code_file_snapshots', 'old' => 'atlas_engineering_code_file_snapshots_file_path_unique', 'new' => 'uniq_atlas_eng_file_snap_ws_path', 'cols' => ['workspace_id', 'file_path']],
        ];
    }

    public function up(): void
    {
        foreach ($this->swaps() as $swap) {
            $this->addWorkspaceColumn($swap['table']);
        }
        foreach ($this->swaps() as $swap) {
            $this->swapUnique($swap['table'], $swap['old'], $swap['new'], $swap['cols']);
        }
    }

    public function down(): void
    {
        foreach ($this->swaps() as $swap) {
            // Restore the original single-column unique (best-effort) before dropping the column.
            $original = array_values(array_filter($swap['cols'], static fn (string $c): bool => $c !== 'workspace_id'));
            $this->swapUnique($swap['table'], $swap['new'], $swap['old'], $original);
        }
        foreach ($this->swaps() as $swap) {
            if (Schema::hasTable($swap['table']) && Schema::hasColumn($swap['table'], 'workspace_id')) {
                Schema::table($swap['table'], static function (Blueprint $table): void {
                    $table->dropColumn('workspace_id');
                });
            }
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

        // Defensive backfill (the column default already fills existing rows on pgsql/sqlite,
        // but this guarantees no NULLs ever participate in the composite unique).
        DB::table($table)->whereNull('workspace_id')->update(['workspace_id' => self::DEFAULT_WORKSPACE]);
    }

    /**
     * @param  array<int,string>  $cols
     */
    private function swapUnique(string $table, string $oldName, string $newName, array $cols): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $colList = implode(', ', $cols);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // pgsql: Laravel ->unique() makes a CONSTRAINT (modules/doc_links/file_snapshots);
            // the symbols one is a raw INDEX. DROP CONSTRAINT first (removes its index), then
            // DROP INDEX IF EXISTS covers the raw-index case. Both IF EXISTS = safe + idempotent.
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$oldName}");
            DB::statement("DROP INDEX IF EXISTS {$oldName}");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$newName} ON {$table} ({$colList})");

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$oldName}");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$newName} ON {$table} ({$colList})");

            return;
        }

        // Generic fallback (mysql, etc.) via the schema builder.
        try {
            Schema::table($table, static function (Blueprint $t) use ($oldName): void {
                $t->dropUnique($oldName);
            });
        } catch (\Throwable) {
            // index may not exist under this name — continue.
        }
        Schema::table($table, static function (Blueprint $t) use ($cols, $newName): void {
            $t->unique($cols, $newName);
        });
    }
};
