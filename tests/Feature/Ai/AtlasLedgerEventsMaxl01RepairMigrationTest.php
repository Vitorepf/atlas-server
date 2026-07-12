<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLedgerEventsMaxl01RepairMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_repair_restores_scope_columns_and_backfills_event_hash_from_stored_row_bytes(): void
    {
        $this->migrateBaseLedgerOnly();
        $occurredAt = '2026-07-12 01:02:03';
        $payload = ['event_name' => 'maxl01.legacy'];
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        DB::table('atlas_ledger_events')->insert([
            'event_id' => '01JMAXL01LEGACY000000000001',
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'legacy-envelope',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'legacy-correlation',
            'causation_id' => null,
            'event_type' => LedgerEventType::DecisionIssued->value,
            'emitter_stage' => 'test.legacy',
            'emitter_version' => 'v1',
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'payload_hash' => $payloadHash,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

        $migration = require database_path('migrations/2026_07_12_011200_repair_atlas_ledger_events_hash_and_scope_columns.php');
        $migration->up();
        $migration->up();

        foreach (['event_hash', 'scope_type', 'scope_id'] as $column) {
            self::assertTrue(Schema::hasColumn('atlas_ledger_events', $column), $column.' exists');
        }

        $row = DB::table('atlas_ledger_events')->where('event_id', '01JMAXL01LEGACY000000000001')->first();
        self::assertNotNull($row);
        self::assertSame($occurredAt, (string) $row->occurred_at);
        self::assertIsString($row->event_hash);
        self::assertNotSame('', $row->event_hash);

        $expected = AtlasEvidenceLedger::computeEventHash([
            'event_id' => '01JMAXL01LEGACY000000000001',
            'event_type' => LedgerEventType::DecisionIssued->value,
            'envelope_id' => 'legacy-envelope',
            'correlation_id' => 'legacy-correlation',
            'causation_id' => null,
            'scope_type' => null,
            'scope_id' => null,
            'payload_hash' => $payloadHash,
            'occurred_at' => $occurredAt,
        ]);
        self::assertTrue(hash_equals($expected, (string) $row->event_hash));
    }

    public function test_new_ledger_writes_persist_non_null_recomputable_event_hash(): void
    {
        $this->migrateBaseLedgerOnly();
        (require database_path('migrations/2026_07_12_011200_repair_atlas_ledger_events_hash_and_scope_columns.php'))->up();

        $occurredAt = CarbonImmutable::parse('2026-07-12T04:00:00+00:00');
        $event = app(AtlasEvidenceLedger::class)->record(
            LedgerEventType::DecisionIssued,
            ['event_name' => 'maxl01.new_write', 'decision_hash' => str_repeat('a', 64)],
            [
                'event_id' => '01JMAXL01NEWWRITE0000000001',
                'tenant_id' => 'default',
                'operator_id' => 'system',
                'envelope_id' => 'new-write-envelope',
                'correlation_id' => 'new-write-correlation',
                'causation_id' => 'new-write-cause',
                'scope_type' => 'maxl01_scope',
                'scope_id' => 'maxl01-scope-id',
                'emitter_stage' => 'test.maxl01',
                'emitter_version' => 'v1',
                'occurred_at' => $occurredAt,
            ],
        );

        self::assertInstanceOf(AtlasLedgerEvent::class, $event);
        self::assertIsString($event->event_hash);
        self::assertNotSame('', $event->event_hash);

        $expected = AtlasEvidenceLedger::computeEventHash([
            'event_id' => '01JMAXL01NEWWRITE0000000001',
            'event_type' => LedgerEventType::DecisionIssued->value,
            'envelope_id' => 'new-write-envelope',
            'correlation_id' => 'new-write-correlation',
            'causation_id' => 'new-write-cause',
            'scope_type' => 'maxl01_scope',
            'scope_id' => 'maxl01-scope-id',
            'payload_hash' => $event->payload_hash,
            'occurred_at' => $occurredAt->toISOString(),
        ]);
        self::assertTrue(hash_equals($expected, (string) $event->event_hash));
    }

    public function test_scope_queries_are_column_guarded_for_legacy_ledger_schema(): void
    {
        $this->migrateBaseLedgerOnly();

        $ledger = app(AtlasEvidenceLedger::class);

        self::assertNull($ledger->latestForScope('missing_scope', 'missing-id'));
        self::assertNull($ledger->engineeringOutcomeEvent('delivery-maxl01', str_repeat('b', 64), str_repeat('c', 64)));
    }

    private function migrateBaseLedgerOnly(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }
}
