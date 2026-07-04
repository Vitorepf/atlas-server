<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\CriteriaCanonicalizer;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 1 — proof-of-binding. The frozen_hash is computed over the REAL criteria (order-independent,
 * volatile-id-free), and Obra #1's floor RECOMPUTES it — closing the "any two identical hashes pass"
 * hole in the already-shipped AcceptanceGate. Wiper-safe: pure logic, no DB.
 */
final class CriteriaHashBindingTest extends TestCase
{
    private const CRITERIA = [
        ['id' => 'ac_behavior_add', 'description' => 'rejects an invalid  email', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
        ['id' => 'ac_behavior_err', 'description' => 'throws on null input', 'verification' => 'test', 'verification_ref' => 't2', 'case_class' => 'error'],
    ];

    // --- the canonicalizer golden (change breaks every freeze) ---

    public function test_equivalent_criteria_hash_identically_regardless_of_order_and_volatile_ids(): void
    {
        $reordered = [
            ['id' => 'DIFFERENT_ID', 'description' => 'throws on null input', 'verification' => 'test', 'verification_ref' => 't2', 'case_class' => 'error'],
            ['id' => 'ALSO_DIFFERENT', 'description' => 'rejects an invalid email', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
        ];

        // same semantic content, different order, different (volatile) ids, collapsed whitespace => same hash
        self::assertSame(CriteriaCanonicalizer::hash(self::CRITERIA), CriteriaCanonicalizer::hash($reordered));
    }

    public function test_a_drifted_criterion_hashes_differently(): void
    {
        $drifted = self::CRITERIA;
        $drifted[0]['description'] = 'rejects a VALID email'; // semantic change

        self::assertNotSame(CriteriaCanonicalizer::hash(self::CRITERIA), CriteriaCanonicalizer::hash($drifted));
    }

    // --- the Obra #1 retro-fix: proof of binding ---

    public function test_bundle_whose_frozen_hash_binds_the_real_criteria_promotes(): void
    {
        $hash = CriteriaCanonicalizer::hash(self::CRITERIA);
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);

        $verdict = $floor->certify(
            AcceptanceBundleFactory::honest(['criteria' => self::CRITERIA, 'criteria_hash' => $hash, 'frozen_hash' => $hash]),
            TrustLevel::Dev,
        );

        self::assertSame(CertVerdict::PROMOTE, $verdict->status, 'blockers: '.implode(',', $verdict->blockers));
        self::assertSame('frozen_hash_provably_binds_the_certified_criteria', $verdict->invariants['criteria_hash_frozen']['detail']);
    }

    public function test_two_identical_but_fabricated_hashes_no_longer_pass_when_criteria_are_present(): void
    {
        // THE HOLE: criteria_hash === frozen_hash (both a fabricated constant) but neither is the
        // real hash of the criteria. Before Obra #2 this PROMOTED. Now it must REFUSE.
        $fabricated = str_repeat('a', 64);
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);

        $verdict = $floor->certify(
            AcceptanceBundleFactory::honest(['criteria' => self::CRITERIA, 'criteria_hash' => $fabricated, 'frozen_hash' => $fabricated]),
            TrustLevel::Dev,
        );

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('criteria_hash_frozen', $verdict->blockers);
        self::assertSame('frozen_hash_not_bound_to_criteria', $verdict->invariants['criteria_hash_frozen']['detail']);
    }

    public function test_legacy_bundle_without_raw_criteria_still_uses_the_weak_equality_path(): void
    {
        // backward-compat: existing callers that carry no raw criteria keep the old hash-equality check
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);

        $verdict = $floor->certify(AcceptanceBundleFactory::honest(), TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status);
        self::assertSame('certified_suite_is_the_frozen_suite', $verdict->invariants['criteria_hash_frozen']['detail']);
    }
}
