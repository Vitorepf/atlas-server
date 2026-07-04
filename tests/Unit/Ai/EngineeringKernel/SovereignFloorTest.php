<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\SovereignReceipt;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slices 1 + 2 — the sovereign floor. Wiper-safe: pure logic, zero DB, zero Laravel bootstrap.
 */
final class SovereignFloorTest extends TestCase
{
    // --- Slice 1: the honesty floor is non-overridable-downward (config só APERTA) ---

    public function test_config_can_tighten_but_never_loosen_the_mutation_floor(): void
    {
        // config 0.0 (the disease default) -> effective stays at the sovereign 0.6
        self::assertSame(0.6, (new SovereignHonestyFloor(configMutationFloor: 0.0))->effectiveMutationFloor());
        // config 0.5 (the live value today) -> still clamped up to 0.6
        self::assertSame(0.6, (new SovereignHonestyFloor(configMutationFloor: 0.5))->effectiveMutationFloor());
        // config 0.9 -> config may TIGHTEN above the piso
        self::assertSame(0.9, (new SovereignHonestyFloor(configMutationFloor: 0.9))->effectiveMutationFloor());
    }

    public function test_config_zero_cannot_promote_a_below_piso_mutation_ratio(): void
    {
        // Even with the operator's config floor at 0.0, a 0.55 kill-ratio (below the 0.6 piso) is refused.
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);
        $bundle = AcceptanceBundleFactory::honest([
            'mutation_report' => ['kill_ratio' => 0.55, 'mutants_generated' => 12, 'decision_surface_added' => true],
        ]);

        $verdict = $floor->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('mutation_kill_ratio', $verdict->blockers);
    }

    // --- happy path (needed as the contrast for the anti-fake-green proof) ---

    public function test_a_fully_honest_bundle_is_promoted(): void
    {
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);

        $verdict = $floor->certify(AcceptanceBundleFactory::honest(), TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status, 'blockers: '.implode(',', $verdict->blockers));
        self::assertSame([], $verdict->blockers);
    }

    // --- Slice 2: the anti-fake-green proof — the disease that motivated the obra ---

    public function test_fake_green_stub_evidence_is_refused_with_false_claim_blocked(): void
    {
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);

        $verdict = $floor->certify(AcceptanceBundleFactory::fakeGreenStub(), TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('false_claim_blocked', $verdict->blockers);
    }

    public function test_php_dash_l_presented_as_a_suite_is_a_false_claim(): void
    {
        // Surgically: take an otherwise-honest bundle and swap only the execution for a php -l.
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);
        $bundle = AcceptanceBundleFactory::honest([
            'execution' => [
                'commands' => ['php -l app/Services/Ai/EngineeringKernel/SovereignHonestyFloor.php'],
                'claimed_status' => 'passed',
                'tests_run' => 0,
                'assertions_executed' => 0,
                'selected_tests' => ['tests/Unit/Ai/EngineeringKernel/SovereignFloorTest.php'],
                'artifacts' => [],
            ],
        ]);

        $verdict = $floor->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('false_claim_blocked', $verdict->blockers);
        self::assertSame('fail', $verdict->invariants['false_claim_blocked']['status']);
    }

    // --- Slice 7: every verdict carries a sealed, auditable, replayable receipt ---

    public function test_every_verdict_carries_a_sealed_receipt_hash(): void
    {
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);

        $verdict = $floor->certify(AcceptanceBundleFactory::honest(), TrustLevel::Dev);

        self::assertNotNull($verdict->receiptRef);
        self::assertSame(64, strlen((string) $verdict->receiptRef), 'receipt ref is a sha256');
    }

    public function test_receipt_records_witness_set_floor_version_and_hashes_and_is_deterministic(): void
    {
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);
        $bundle = AcceptanceBundleFactory::honest();

        $receipt = $floor->receiptFor($bundle, TrustLevel::Autonomos);

        self::assertSame(SovereignReceipt::SCHEMA, $receipt['schema_version']);
        self::assertSame(SovereignHonestyFloor::FLOOR_VERSION, $receipt['floor_version']);
        self::assertSame('autonomos', $receipt['trust_level']);
        self::assertSame('frozen-hash-001', $receipt['criteria_hash']);
        self::assertSame(0.6, $receipt['effective_mutation_floor']);
        self::assertArrayHasKey('receipt_hash', $receipt);

        // deterministic: same inputs → same receipt_hash (replayable provenance)
        self::assertSame($receipt['receipt_hash'], $floor->receiptFor($bundle, TrustLevel::Autonomos)['receipt_hash']);
        // trust level is part of provenance: a different witness-set → a different receipt
        self::assertNotSame($receipt['receipt_hash'], $floor->receiptFor($bundle, TrustLevel::Dev)['receipt_hash']);
    }
}
