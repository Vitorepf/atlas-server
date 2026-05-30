<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry;

use App\Services\Ai\Foundry\FoundryEvidenceHarvesterService;
use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * P2-VERIFY-DEADCODE-REMOVAL — Finding 17 (primary, real fix).
 *
 * Proves a harvested ledger_event anchor now carries the anchor_meta identity
 * keys (scope_type/scope_id/correlation_id/envelope_id) the STRONG verifier
 * (FoundryEvidenceVerifierService) needs to resolve the real DB row and
 * recompute event_hash — so real rows confirm instead of false-refuting as
 * missing. Preserves: anchor never self-supplies event_hash (real-or-blocked);
 * additive anchor_meta leaves anchor_id/anchor_hash deterministic (I1/determinism).
 */
final class FoundryEvidenceHarvesterSingleSourceTest extends TestCase
{
    private function harvester(): FoundryEvidenceHarvesterService
    {
        // Owners that EXPLODE on any read: with cycles + ledger_events injected
        // the harvester touches zero owner I/O. A green run proves read-only.
        $recorder = new class extends AreaFocusCycleRecorderService
        {
            public function __construct() {}

            public function listCycles(string $areaId): array
            {
                throw new RuntimeException('listCycles must not be called when cycles injected');
            }

            public function replay(string $cycleId): ?array
            {
                throw new RuntimeException('replay must not be called when cycles injected');
            }
        };

        $ledger = new class extends AtlasEvidenceLedger
        {
            public function __construct() {}

            public function eventsForScope(string $scopeType, string $scopeId, int $limit = 100): array
            {
                throw new RuntimeException('eventsForScope must not be called when ledger injected');
            }
        };

        return new FoundryEvidenceHarvesterService(
            $recorder,
            $ledger,
            new AreaFocusEvidencePackService,
            new AutonomousLoopReceiptIntegrityService,
            (new ReflectionClass(PlanCompletionTrackerService::class))->newInstanceWithoutConstructor(),
        );
    }

    private function strongVerifier(): FoundryEvidenceVerifierService
    {
        return new FoundryEvidenceVerifierService(
            new class extends AreaFocusCycleRecorderService
            {
                public function __construct() {}
            },
            new class extends AtlasEvidenceLedger
            {
                public function __construct() {}
            },
            new AutonomousLoopReceiptIntegrityService,
        );
    }

    /**
     * A REAL blocker ledger row + its computed event_hash. The harvester only
     * reads the identity keys; the strong verifier recomputes event_hash from
     * this row at verify time.
     *
     * @return array<string,mixed>
     */
    private function realBlockerRow(): array
    {
        $occurredAt = CarbonImmutable::parse('2026-05-29T10:00:00Z');
        $envelope = [
            'event_id' => 'evt_block_real',
            'event_type' => 'OPERATION_BLOCKED',
            'envelope_id' => 'env-block-1',
            'correlation_id' => 'corr-block-1',
            'causation_id' => null,
            'scope_type' => 'area_focus_loop',
            'scope_id' => 'agentic_engineering_os',
            'payload_hash' => hash('sha256', 'payload'),
            'occurred_at' => $occurredAt->toISOString(),
        ];

        return [
            'event_id' => 'evt_block_real',
            'event_type' => 'OPERATION_BLOCKED',
            'envelope_id' => 'env-block-1',
            'correlation_id' => 'corr-block-1',
            'causation_id' => null,
            'scope_type' => 'area_focus_loop',
            'scope_id' => 'agentic_engineering_os',
            'payload_hash' => $envelope['payload_hash'],
            'occurred_at' => $occurredAt->toIso8601String(),
            'event_hash' => AtlasEvidenceLedger::computeEventHash($envelope),
        ];
    }

    /** @return array<string,mixed> the harvested ledger_event anchor */
    private function harvestLedgerAnchor(): array
    {
        $dossier = $this->harvester()->harvest([
            'area_id' => 'agentic_engineering_os',
            'cycles' => [],
            'ledger_events' => [$this->realBlockerRow()],
        ]);

        foreach ($dossier['anchors'] as $anchor) {
            if (($anchor['anchor_type'] ?? '') === 'ledger_event') {
                return $anchor;
            }
        }

        $this->fail('expected a ledger_event anchor in the dossier');
    }

    public function test_ledger_event_anchor_carries_anchor_meta_identity_keys(): void
    {
        $anchor = $this->harvestLedgerAnchor();

        $this->assertArrayHasKey('anchor_meta', $anchor, 'Finding 17: ledger_event anchor must carry anchor_meta');
        foreach (['scope_type', 'scope_id', 'correlation_id', 'envelope_id'] as $key) {
            $this->assertArrayHasKey($key, $anchor['anchor_meta']);
        }
        $this->assertSame('area_focus_loop', $anchor['anchor_meta']['scope_type']);
        $this->assertSame('agentic_engineering_os', $anchor['anchor_meta']['scope_id']);
        $this->assertSame('corr-block-1', $anchor['anchor_meta']['correlation_id']);
        $this->assertSame('env-block-1', $anchor['anchor_meta']['envelope_id']);
    }

    public function test_anchor_never_self_supplies_event_hash(): void
    {
        $anchor = $this->harvestLedgerAnchor();

        // real-or-blocked: the anchor carries row-locator identity ONLY, never
        // the event_hash. The verifier MUST recompute from the resolved DB row.
        $this->assertArrayNotHasKey('event_hash', $anchor);
        $this->assertArrayNotHasKey('event_hash', $anchor['anchor_meta']);
    }

    public function test_harvested_anchor_resolves_real_row_in_strong_verifier(): void
    {
        $anchor = $this->harvestLedgerAnchor();

        // Feed the harvested anchor to the STRONG verifier with the real row
        // set. The verifier resolves the row and recomputes event_hash itself.
        $verdict = $this->strongVerifier()->verifyAnchor(
            $anchor,
            ['ledger_events' => [$this->realBlockerRow()]],
        );

        $this->assertSame('confirmed', $verdict['verdict']);
        $this->assertNull($verdict['drop_reason']);
        $this->assertSame(['evt_block_real'], $verdict['matched_event_ids']);
    }

    public function test_tampered_row_still_refuted_by_strong_verifier(): void
    {
        // Gate is no weaker: a tampered row (event_hash no longer matches the
        // recomputed envelope) is still refuted with event_hash_mismatch.
        $anchor = $this->harvestLedgerAnchor();
        $row = $this->realBlockerRow();
        $row['payload_hash'] = hash('sha256', 'tampered');

        $verifier = $this->strongVerifier();
        // Append-only rejection ledger redirected to a scoped temp dir (I1
        // audit-write preserved without a booted app).
        $verifier->setRejectionStorageDirForTesting(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_foundry_p2_'.bin2hex(random_bytes(4)),
        );
        $verdict = $verifier->verifyAnchor(
            $anchor,
            ['ledger_events' => [$row]],
        );

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('event_hash_mismatch', $verdict['drop_reason']);
    }

    public function test_anchor_meta_is_additive_and_deterministic(): void
    {
        $a = $this->harvestLedgerAnchor();
        $b = $this->harvestLedgerAnchor();

        // additive identity: anchor_id/anchor_hash are unchanged by anchor_meta
        // (meta is excluded from the stable hash) and stable across runs.
        $this->assertSame($a['anchor_id'], $b['anchor_id']);
        $this->assertSame($a['anchor_hash'], $b['anchor_hash']);
        $this->assertStringStartsWith('fanchor_', $a['anchor_id']);
        $this->assertStringStartsWith('sha256:', $a['anchor_hash']);
    }

    public function test_event_without_identity_keys_emits_no_anchor_meta(): void
    {
        // Back-compat: a blocker row lacking scope/correlation/envelope keys
        // produces an anchor with NO anchor_meta (byte-identical to before).
        $dossier = $this->harvester()->harvest([
            'area_id' => 'agentic_engineering_os',
            'cycles' => [],
            'ledger_events' => [
                ['event_id' => 'evt_bare', 'event_type' => 'GATE_BLOCKED'],
            ],
        ]);

        $ledgerAnchors = array_values(array_filter(
            $dossier['anchors'],
            static fn (array $a): bool => ($a['anchor_type'] ?? '') === 'ledger_event',
        ));
        $this->assertCount(1, $ledgerAnchors);
        $this->assertArrayNotHasKey('anchor_meta', $ledgerAnchors[0]);
    }
}
