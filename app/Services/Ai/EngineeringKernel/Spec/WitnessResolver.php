<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel port: resolve who witnessed a spec's independence and what the advisory
 * cross-family shadow said.
 *
 * Owns: the seam through which the (possibly provider-bound, async) witness resolution is obtained,
 * so the floor stays pure. A missing 2nd family MUST surface as DivergenceStatus::Unavailable +
 * SelfComposedUnwitnessed — never a fabricated cross-family witness.
 * Must never own: freeze authority. It reports the witness; the deterministic floor decides.
 */
interface WitnessResolver
{
    public function resolve(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): WitnessContext;
}
