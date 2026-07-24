<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AaeosLedgerTenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_tenants_with_identical_chain_identifiers_cannot_cross_link(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $sharedContext = [
            'operator_id' => 'operator-shared',
            'envelope_id' => 'envelope-shared',
            'correlation_id' => 'correlation-shared',
            'scope_type' => 'aaeos_cycle',
            'scope_id' => 'cycle-shared',
            'occurred_at' => '2026-07-24T12:00:00.000000Z',
        ];

        $tenantA = $ledger->record(
            LedgerEventType::AaeosCycleRecorded,
            ['cycle_id' => 'cycle-shared', 'tenant_marker' => 'a'],
            ['tenant_id' => 'tenant-a', ...$sharedContext],
        );
        $tenantB = $ledger->record(
            LedgerEventType::AaeosCycleRecorded,
            ['cycle_id' => 'cycle-shared', 'tenant_marker' => 'b'],
            ['tenant_id' => 'tenant-b', ...$sharedContext],
        );
        $tenantASecond = $ledger->record(
            LedgerEventType::AaeosCycleRecorded,
            ['cycle_id' => 'cycle-shared', 'tenant_marker' => 'a-second'],
            ['tenant_id' => 'tenant-a', ...$sharedContext, 'occurred_at' => '2026-07-24T12:00:01Z'],
        );

        self::assertNotNull($tenantA);
        self::assertNotNull($tenantB);
        self::assertNull($tenantA->prev_event_hash);
        self::assertNull(
            $tenantB->prev_event_hash,
            'Tenant B must start an independent chain even when every caller-visible identifier collides.',
        );
        self::assertSame(1, $tenantB->chain_position);
        self::assertSame(2, $tenantASecond?->chain_position);
        self::assertSame($tenantA->event_hash, $tenantASecond?->prev_event_hash);
        self::assertCount(2, $ledger->eventsForEnvelope('envelope-shared', 'tenant-a'));
        self::assertCount(1, $ledger->eventsForEnvelope('envelope-shared', 'tenant-b'));

        $replay = app(AtlasLedgerReplayService::class);
        $cutoffB = $replay->authenticatedCutoffForTenantChain('tenant-b', (string) $tenantB->chain_key_hash);
        self::assertNotNull($cutoffB);
        self::assertTrue($replay->verifyTenantChain('tenant-b', (string) $tenantB->chain_key_hash, $cutoffB)['valid']);
        self::assertSame(
            'cutoff_scope_mismatch',
            $replay->verifyTenantChain('tenant-a', (string) $tenantB->chain_key_hash, $cutoffB)['failure_reason'],
        );
    }
}
