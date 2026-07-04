<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel port: the cross-family shadow-spec advisor. A DIFFERENT provider family
 * independently re-derives the intent and this reports whether it DIVERGED from the composed spec.
 *
 * Owns: the seam to the (provider-bound, async, budget-capped) shadow derivation. It is a divergence
 * DETECTOR, never a certifier: it returns Diverged | Agreed | Unavailable.
 * Must never own: freeze authority. A down 2nd family MUST return Unavailable — never a fabricated
 * "Agreed" (which would launder a spec no independent family ever saw = fake-green one layer up).
 */
interface SpecShadowProvider
{
    public function compare(SpecDraft $draft, IntentEnvelope $intent): DivergenceStatus;
}
