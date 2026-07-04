<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel adapter: the honest default when no 2nd provider family is configured/reachable
 * (Hermes can't call Claude, GLM 429s, MiniMax self-host is future). Always reports Unavailable —
 * never fabricates an Agreed. This is what keeps provider-absence a first-class HOLD, not a fail-open.
 */
final class UnavailableSpecShadowProvider implements SpecShadowProvider
{
    public function compare(SpecDraft $draft, IntentEnvelope $intent): DivergenceStatus
    {
        return DivergenceStatus::Unavailable;
    }
}
