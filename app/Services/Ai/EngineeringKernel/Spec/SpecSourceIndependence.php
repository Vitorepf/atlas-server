<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel value: the honest form of "author != spec-source".
 *
 * Owns: naming WHO, independent of the composer, witnessed that the spec is the right spec — a human
 * in the loop, a distinct provider family, or nobody (self-composed). This is a PROVENANCE binding,
 * not a correctness claim: it records exactly the independence that exists, and lets the LANE decide
 * whether that independence is enough to freeze.
 * Must never own: the acceptance floors. It only reports the witness; the deterministic floor decides.
 */
enum SpecSourceIndependence: string
{
    case HumanWitnessed = 'human_witnessed';
    case CrossFamilyWitnessed = 'cross_family_witnessed';
    case SelfComposedUnwitnessed = 'self_composed_unwitnessed';

    /**
     * A self-composed spec has no independent source, so it may freeze ONLY where a human is in the
     * loop (Dev/Forge). In the autonomous lane there is no independent witness — it must HOLD. This
     * is the `loop-cannot-deliver-unwitnessed` truth, made a gate. Witnessed states freeze anywhere.
     */
    public function mayFreezeIn(TrustLevel $lane): bool
    {
        return match ($this) {
            self::HumanWitnessed, self::CrossFamilyWitnessed => true,
            self::SelfComposedUnwitnessed => $lane !== TrustLevel::Autonomos,
        };
    }
}
