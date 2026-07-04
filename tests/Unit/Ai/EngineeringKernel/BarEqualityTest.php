<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 5 — bar(dev)=bar(forge)=bar(autonomos). The SAME bundle certifies identically across the
 * three trust levels; only the witness-set varies. This is the proof that trust_level parameterizes
 * WHO witnesses, never WHAT is required. Wiper-safe: pure logic.
 */
final class BarEqualityTest extends TestCase
{
    public function test_the_same_honest_bundle_promotes_identically_across_all_trust_levels(): void
    {
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);
        $bundle = AcceptanceBundleFactory::honest();

        $verdicts = [];
        foreach (TrustLevel::cases() as $trust) {
            $verdicts[$trust->value] = $floor->certify($bundle, $trust);
        }

        // identical decision + identical invariant breakdown for all three
        foreach ($verdicts as $level => $verdict) {
            self::assertSame(CertVerdict::PROMOTE, $verdict->status, "level {$level}");
            self::assertSame(
                $verdicts['dev']->invariants,
                $verdict->invariants,
                "invariants must be identical across trust levels; {$level} diverged",
            );
        }

        // the ONLY thing that varies is the witness-set
        self::assertNotSame($verdicts['dev']->witnessSet, $verdicts['autonomos']->witnessSet);
        self::assertNotSame($verdicts['dev']->witnessSet, $verdicts['forge']->witnessSet);
    }

    public function test_the_same_fake_bundle_is_refused_identically_across_all_trust_levels(): void
    {
        $floor = new SovereignHonestyFloor(configMutationFloor: 0.0);
        $bundle = AcceptanceBundleFactory::fakeGreenStub();

        $blockersByLevel = [];
        foreach (TrustLevel::cases() as $trust) {
            $verdict = $floor->certify($bundle, $trust);
            self::assertSame(CertVerdict::REFUSE, $verdict->status, "level {$trust->value}");
            $blockers = $verdict->blockers;
            sort($blockers);
            $blockersByLevel[$trust->value] = $blockers;
        }

        // no trust level gets a softer bar: identical blocker sets
        self::assertSame($blockersByLevel['dev'], $blockersByLevel['forge']);
        self::assertSame($blockersByLevel['dev'], $blockersByLevel['autonomos']);
    }
}
