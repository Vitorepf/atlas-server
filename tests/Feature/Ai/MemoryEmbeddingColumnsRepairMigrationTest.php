<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MemoryEmbeddingColumnsRepairMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_07_09_154000_repair_missing_atlas_memory_embedding_columns.php';

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_verbatim_memories');
        Schema::dropIfExists('atlas_memory_entries');

        parent::tearDown();
    }

    public function test_sqlite_is_an_idempotent_noop_and_never_touches_the_migration_ledger(): void
    {
        Schema::create('atlas_memory_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('atlas_verbatim_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        $before = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('atlas_memory_entries', 'embedding'));
        $this->assertFalse(Schema::hasColumn('atlas_verbatim_memories', 'embedding'));
        $after = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
        $this->assertSame($before, $after);
    }
}
