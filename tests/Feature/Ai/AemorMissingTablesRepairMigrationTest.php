<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AemorMissingTablesRepairMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_07_09_155500_repair_missing_atlas_aemor_tables.php';

    private const TABLES = [
        'atlas_aemor_judgment_reports',
        'atlas_aemor_memory_candidates',
        'atlas_aemor_learning_signals',
        'atlas_aemor_outcomes',
        'atlas_aemor_execution_events',
        'atlas_aemor_execution_episodes',
    ];

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_it_repairs_all_tables_idempotently(): void
    {
        $before = $this->migrationLedger();
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }
        foreach ([
            'outcome_attribution',
            'negative_knowledge',
            'provider_skill_reliability',
            'counterfactual_replay',
            'memory_budget',
            'operational_doctrine',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('atlas_aemor_judgment_reports', $column));
        }
        $this->assertSame($before, $this->migrationLedger());
    }

    public function test_repeated_repair_preserves_existing_episode(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        DB::table('atlas_aemor_execution_episodes')->insert([
            'id' => '11111111-1111-1111-1111-111111111111',
            'status' => 'open',
            'scope_type' => 'workspace',
            'objective_hash' => str_repeat('a', 64),
            'objective' => 'Preserve this episode.',
            'episode_hash' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration->up();

        $this->assertSame(1, DB::table('atlas_aemor_execution_episodes')->count());
    }

    private function migrationLedger(): array
    {
        return Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
    }
}
