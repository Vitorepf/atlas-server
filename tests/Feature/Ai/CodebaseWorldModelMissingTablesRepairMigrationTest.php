<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CodebaseWorldModelMissingTablesRepairMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_07_09_155000_repair_missing_codebase_world_model_tables.php';

    private const TABLES = [
        'ai_codebase_world_model_edges',
        'ai_codebase_world_model_nodes',
        'ai_codebase_world_models',
    ];

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_recreates_the_final_world_model_schema_idempotently(): void
    {
        $before = $this->migrationLedger();
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }
        foreach ([
            'valid_from',
            'valid_until',
            'observed_at',
            'verified_at',
            'stale_after',
            'source_hash',
            'superseded_by',
            'authority_level',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_codebase_world_model_edges', $column),
                "Missing temporal edge column {$column}",
            );
        }
        $this->assertSame($before, $this->migrationLedger());
    }

    public function test_repeated_repair_preserves_existing_world_model_rows(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        DB::table('ai_codebase_world_models')->insert([
            'id' => '11111111-1111-1111-1111-111111111111',
            'model_id' => 'atlas-server-current',
            'scope' => 'atlas-server',
            'status' => 'built',
            'model_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(1, DB::table('ai_codebase_world_models')->count());
    }

    /**
     * @return list<string>
     */
    private function migrationLedger(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    }
}
