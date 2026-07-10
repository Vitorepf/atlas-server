<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLedgerEventsSecondRepairMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_repair_recreates_final_ledger_schema_without_touching_migration_history(): void
    {
        $before = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
        $migration = require database_path('migrations/2026_07_09_160000_repair_missing_atlas_ledger_events_table.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('atlas_ledger_events'));
        foreach (['event_id', 'event_type', 'payload', 'payload_hash', 'occurred_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('atlas_ledger_events', $column));
        }
        $after = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
        $this->assertSame($before, $after);
    }
}
