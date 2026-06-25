<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CodeIntelligenceMissingTableRepairMigrationTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_code_modules',
        'atlas_engineering_code_symbols',
        'atlas_engineering_doc_links',
        'atlas_engineering_code_file_snapshots',
    ];

    private const MIGRATION_FILE = __DIR__.'/../../../database/migrations/2026_06_25_000100_repair_missing_atlas_engineering_code_intelligence_tables.php';

    private function loadMigration(): object
    {
        return require self::MIGRATION_FILE;
    }

    private function dropAll(): void
    {
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function migrationsLedgerSnapshot(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    }

    public function test_creates_all_tables_when_all_missing(): void
    {
        $this->dropAll();
        foreach (self::TABLES as $t) {
            $this->assertFalse(Schema::hasTable($t));
        }

        $before = $this->migrationsLedgerSnapshot();
        $this->loadMigration()->up();

        foreach (self::TABLES as $t) {
            $this->assertTrue(Schema::hasTable($t), "{$t} should be created");
            $this->assertTrue(Schema::hasColumn($t, 'workspace_id'), "{$t} should have workspace_id");
        }

        $this->assertSame($before, $this->migrationsLedgerSnapshot(), 'migration ledger must not be touched');
    }

    public function test_does_not_recreate_existing_tables(): void
    {
        $this->dropAll();
        $migration = $this->loadMigration();
        $migration->up();
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => '11111111-1111-1111-1111-111111111111',
            'workspace_id' => 'atlas-server',
            'slug' => 'sentinel',
            'name' => 'Sentinel',
            'layer' => 'core',
            'source_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(1, DB::table('atlas_engineering_code_modules')->where('slug', 'sentinel')->count(),
            'second up() must not drop/recreate existing table data');
    }

    public function test_partial_state_only_creates_missing_tables(): void
    {
        $this->dropAll();
        $this->loadMigration()->up();
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_doc_links');

        $this->loadMigration()->up();

        foreach (self::TABLES as $t) {
            $this->assertTrue(Schema::hasTable($t), "{$t} expected after partial repair");
        }
    }

    public function test_backfills_workspace_id_when_base_table_lacks_it(): void
    {
        $this->dropAll();

        Schema::create('atlas_engineering_code_modules', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160);
            $table->string('name', 220);
            $table->string('layer', 80);
            $table->string('source_hash', 64);
            $table->timestamps();
        });

        DB::table('atlas_engineering_code_modules')->insert([
            'id' => '22222222-2222-2222-2222-222222222222',
            'slug' => 'pre-existing',
            'name' => 'Pre',
            'layer' => 'core',
            'source_hash' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->loadMigration()->up();

        $this->assertTrue(Schema::hasColumn('atlas_engineering_code_modules', 'workspace_id'));
        $row = DB::table('atlas_engineering_code_modules')->where('slug', 'pre-existing')->first();
        $this->assertNotNull($row);
        $this->assertSame('atlas-server', $row->workspace_id);
    }

    public function test_repeated_up_is_idempotent_and_leaves_ledger_alone(): void
    {
        $this->dropAll();
        $migration = $this->loadMigration();

        $before = $this->migrationsLedgerSnapshot();
        $migration->up();
        $migration->up();
        $migration->up();

        foreach (self::TABLES as $t) {
            $this->assertTrue(Schema::hasTable($t));
        }
        $this->assertSame($before, $this->migrationsLedgerSnapshot(), 'migration ledger must remain untouched after repeated up()');
    }
}
