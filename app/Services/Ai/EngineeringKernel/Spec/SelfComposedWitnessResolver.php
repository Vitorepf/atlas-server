<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel adapter: the HONEST default witness — no cross-family shadow has run, so the
 * spec is self-composed and unwitnessed, and divergence is not required. In Dev/Forge a human is the
 * independent source (freezes); in the autonomous lane there is none (holds). Never fabricates a
 * cross-family witness. The async advisor (Slice 5) replaces this when a 2nd family is reachable.
 */
final class SelfComposedWitnessResolver implements WitnessResolver
{
    public function resolve(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): WitnessContext
    {
        return new WitnessContext(
            SpecSourceIndependence::SelfComposedUnwitnessed,
            DivergenceStatus::NotRequired,
        );
    }
}
