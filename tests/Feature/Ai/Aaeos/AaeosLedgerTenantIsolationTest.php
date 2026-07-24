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
        self::assertSame([], $ledger->eventsForEnvelope('envelope-shared'));

        $replay = app(AtlasLedgerReplayService::class);
        $cutoffB = $replay->authenticatedCutoffForTenantChain('tenant-b', (string) $tenantB->chain_key_hash);
        self::assertNotNull($cutoffB);
        self::assertTrue($replay->verifyTenantChain('tenant-b', (string) $tenantB->chain_key_hash, $cutoffB)['valid']);
        self::assertSame(
            'cutoff_scope_mismatch',
            $replay->verifyTenantChain('tenant-a', (string) $tenantB->chain_key_hash, $cutoffB)['failure_reason'],
        );
    }

    public function test_proof_reads_fail_closed_when_tenant_is_omitted_and_never_cross_tenant_boundaries(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $orderHash = str_repeat('a', 64);
        $outcomeHash = str_repeat('b', 64);
        $event = $ledger->record(
            LedgerEventType::AaeosCycleRecorded,
            [
                'event_name' => 'engineering.outcome.recorded',
                'order_hash' => $orderHash,
                'outcome' => ['outcome_hash' => $outcomeHash],
            ],
            [
                'tenant_id' => 'tenant-b',
                'operator_id' => 'operator-b',
                'envelope_id' => 'envelope-b',
                'correlation_id' => 'correlation-shared',
                'scope_type' => 'engineering_delivery',
                'scope_id' => 'delivery-shared',
            ],
        );
        self::assertNotNull($event);

        self::assertNull($ledger->eventById((string) $event->event_id));
        self::assertNull($ledger->eventById((string) $event->event_id, 'tenant-a'));
        self::assertNotNull($ledger->eventById((string) $event->event_id, 'tenant-b'));
        self::assertSame([], $ledger->eventsForCorrelation('correlation-shared'));
        self::assertSame([], $ledger->eventsForCorrelation('correlation-shared', 100, 'tenant-a'));
        self::assertCount(1, $ledger->eventsForCorrelation('correlation-shared', 100, 'tenant-b'));
        self::assertSame([], $ledger->eventsForScope('engineering_delivery', 'delivery-shared'));
        self::assertSame([], $ledger->eventsForScope('engineering_delivery', 'delivery-shared', 100, 'tenant-a'));
        self::assertCount(1, $ledger->eventsForScope('engineering_delivery', 'delivery-shared', 100, 'tenant-b'));
        self::assertNull($ledger->engineeringOutcomeEvent('delivery-shared', $orderHash, $outcomeHash));
        self::assertNull($ledger->engineeringOutcomeEvent('delivery-shared', $orderHash, $outcomeHash, 'tenant-a'));
        self::assertNotNull($ledger->engineeringOutcomeEvent('delivery-shared', $orderHash, $outcomeHash, 'tenant-b'));
    }
}
