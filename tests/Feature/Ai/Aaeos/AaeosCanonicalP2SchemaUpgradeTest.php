<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AaeosCanonicalP2SchemaUpgradeTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_pre_p2_bytes_remain_legacy_unverified_while_new_rows_use_v2(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_12_011200_repair_atlas_ledger_events_hash_and_scope_columns.php'))->up();
        (require database_path('migrations/2026_07_12_021500_add_hash_chain_to_atlas_ledger_events.php'))->up();

        $legacy = app(AtlasEvidenceLedger::class)->record(
            LedgerEventType::ContextComposed,
            ['historical' => true],
            [
                'tenant_id' => 'tenant-upgrade',
                'operator_id' => 'operator-upgrade',
                'envelope_id' => 'legacy-envelope',
                'correlation_id' => 'legacy-correlation',
            ],
        );
        self::assertNotNull($legacy);
        $legacyHash = (string) $legacy->event_hash;

        $migration = require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php');
        $migration->up();
        $migration->up();

        $legacy->refresh();
        self::assertSame(AtlasEvidenceLedger::SCHEMA_VERSION, $legacy->schema_version);
        self::assertSame($legacyHash, $legacy->event_hash);
        self::assertNull($legacy->chain_key_hash);
        self::assertNull($legacy->chain_position);
        self::assertSame(
            AtlasEvidenceLedger::INTEGRITY_LEGACY_UNVERIFIED,
            app(AtlasEvidenceLedger::class)->eventIntegrityStatus($legacy),
        );

        $v2 = app(AtlasEvidenceLedger::class)->record(
            LedgerEventType::ContextComposed,
            ['current' => true],
            [
                'tenant_id' => 'tenant-upgrade',
                'operator_id' => 'operator-upgrade',
                'envelope_id' => 'v2-envelope',
                'correlation_id' => 'v2-correlation',
            ],
        );
        self::assertNotNull($v2);
        self::assertSame(AtlasEvidenceLedger::SCHEMA_VERSION_V2, $v2->schema_version);
        self::assertSame('verified', app(AtlasEvidenceLedger::class)->eventIntegrityStatus($v2));

        $duplicate = $v2->getAttributes();
        $duplicate['event_id'] = '01JAAEOSDUPLICATEPOSITION001';
        $duplicate['payload'] = json_encode($v2->payload, JSON_THROW_ON_ERROR);
        $duplicate['created_at'] = now();
        $duplicate['updated_at'] = now();
        try {
            DB::table('atlas_ledger_events')->insert($duplicate);
            self::fail('Duplicate tenant/chain position must be refused by the canonical schema.');
        } catch (QueryException) {
            self::assertDatabaseCount('atlas_ledger_events', 2);
        }

        $migration->down();
        self::assertTrue(Schema::hasColumn('atlas_ledger_events', 'chain_key_hash'));
        self::assertTrue(Schema::hasColumn('atlas_ledger_events', 'chain_position'));
        self::assertSame(2, AtlasLedgerEvent::query()->count());
    }
}
