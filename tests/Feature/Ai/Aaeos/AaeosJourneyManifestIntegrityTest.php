<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AaeosJourneyManifestIntegrityTest extends TestCase
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

    public function test_cycle_receipt_core_hash_is_non_circular_and_bound_by_the_ledger_event(): void
    {
        $receipt = app(AaeosCycleRuntime::class)->runCycle(
            'fix a bounded validation bug',
            ['source' => 'cli'],
            ['tenant_id' => 'tenant-core', 'operator_id' => 'operator-core'],
        );

        self::assertSame('recorded', $receipt['evidence_status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $receipt['receipt_core_hash']);
        self::assertArrayNotHasKey('evidence_event_id', $receipt['receipt_core']);
        self::assertArrayNotHasKey('evidence_event_hash', $receipt['receipt_core']);
        self::assertSame(
            $receipt['receipt_core_hash'],
            AaeosCycleRuntime::receiptCoreHash($receipt['receipt_core']),
        );

        $event = app(AtlasEvidenceLedger::class)->eventById(
            (string) $receipt['evidence_event_id'],
            'tenant-core',
        );
        self::assertNotNull($event);
        self::assertSame($receipt['evidence_event_hash'], $event->event_hash);
        self::assertEquals($receipt['receipt_core'], data_get($event->payload, 'receipt_core'));
        self::assertSame($receipt['receipt_core_hash'], data_get($event->payload, 'receipt_core_hash'));
        self::assertSame(
            $receipt['receipt_core_hash'],
            AaeosCycleRuntime::receiptCoreHash((array) data_get($event->payload, 'receipt_core')),
        );
        self::assertSame('verified', app(AtlasEvidenceLedger::class)->eventIntegrityStatus($event));
    }

    public function test_manifest_rejects_omission_and_reordering_even_when_the_attacker_rehashes_it(): void
    {
        $events = [$this->append(1), $this->append(2), $this->append(3)];
        $replay = app(AtlasLedgerReplayService::class);
        $chainKey = (string) $events[0]->chain_key_hash;
        $cutoff = $replay->authenticatedCutoffForTenantChain('tenant-manifest', $chainKey);
        self::assertNotNull($cutoff);

        $manifest = $replay->journeyManifestForTenantChain('tenant-manifest', $chainKey, $cutoff);
        self::assertTrue($replay->verifyJourneyManifest($manifest)['valid']);

        $reordered = $manifest;
        $reordered['events'] = array_reverse($reordered['events']);
        $reordered['journey_manifest_hash'] = $this->manifestHash($reordered);
        self::assertSame(
            'journey_manifest_order_mismatch',
            $replay->verifyJourneyManifest($reordered)['failure_reason'],
        );

        $omitted = $manifest;
        array_splice($omitted['events'], 1, 1);
        $omitted['event_count'] = count($omitted['events']);
        $omitted['journey_manifest_hash'] = $this->manifestHash($omitted);
        self::assertSame(
            'journey_manifest_order_mismatch',
            $replay->verifyJourneyManifest($omitted)['failure_reason'],
        );
    }

    private function append(int $position): AtlasLedgerEvent
    {
        $event = app(AtlasEvidenceLedger::class)->record(
            LedgerEventType::AaeosCycleRecorded,
            ['attempt' => $position],
            [
                'tenant_id' => 'tenant-manifest',
                'operator_id' => 'operator-manifest',
                'envelope_id' => 'manifest-envelope-'.$position,
                'correlation_id' => 'manifest-journey',
                'scope_type' => 'journey',
                'scope_id' => 'manifest-journey',
                'occurred_at' => sprintf('2026-07-24T13:00:%02dZ', $position),
            ],
        );
        self::assertNotNull($event);

        return $event;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function manifestHash(array $manifest): string
    {
        unset($manifest['journey_manifest_hash']);

        return AtlasEvidenceLedger::computeEventHash($manifest);
    }
}
