<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasLedgerEventsRepairMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_repair_migration_recreates_missing_ledger_table_idempotently(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        $migration = require database_path('migrations/2026_05_13_010000_repair_missing_atlas_ledger_events_table.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('atlas_ledger_events'));

        foreach ([
            'event_id',
            'schema_version',
            'tenant_id',
            'operator_id',
            'envelope_id',
            'receipt_id',
            'trace_id',
            'correlation_id',
            'causation_id',
            'event_type',
            'emitter_stage',
            'emitter_version',
            'payload',
            'payload_hash',
            'occurred_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('atlas_ledger_events', $column), "Missing {$column}");
        }
    }
}
