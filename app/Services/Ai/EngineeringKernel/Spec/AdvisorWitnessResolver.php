<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel adapter: resolves the witness from the cross-family shadow advisor — the honest
 * three-state form.
 *
 * The shadow is a divergence DETECTOR, never a certifier, so:
 *   - it can raise DIVERGED (=> the floor routes to REVISE);
 *   - AGREEMENT grants ZERO freeze credit — it never upgrades source-independence (two correlated
 *     models agreeing is not evidence of correctness). So source-independence stays SelfComposed:
 *     Dev/Forge freeze (a human is the independent source), the autonomous lane HOLDS;
 *   - UNAVAILABLE (no reachable 2nd family) is carried honestly — never a fabricated Agreed, never a
 *     silent fail-open freeze.
 *
 * Only the operator sealing an explicit cross-family review yields CrossFamilyWitnessed — model
 * agreement alone never does.
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class AdvisorWitnessResolver implements WitnessResolver
{
    public function __construct(
        private readonly SpecShadowProvider $shadow,
    ) {}

    public function resolve(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): WitnessContext
    {
        $divergence = $this->shadow->compare($draft, $intent);

        // Model output never grants source-independence (agreement = zero freeze credit).
        return new WitnessContext(SpecSourceIndependence::SelfComposedUnwitnessed, $divergence);
    }
}
