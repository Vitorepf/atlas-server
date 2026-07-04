<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel adapter: promotes the real AtlasDev spec machinery through the sovereign spec
 * floor. Strangler entry: the surface stops owning the freeze decision; the deterministic floor does.
 *
 * Owns: translating a surface's composed-spec evidence into a SpecDraft + IntentEnvelope and routing
 * it through the floor (with the real WorkcellSpecOracle + the resolved witness).
 * Must never own: the acceptance invariants (SovereignSpecFloor).
 */
final class AtlasSpecGateAdapter implements SpecAdversary
{
    private readonly SovereignSpecFloor $floor;

    public function __construct(
        SpecOracle $oracle,
        ?WitnessResolver $witnessResolver = null,
        int $configMinDiscriminating = 0,
    ) {
        $this->floor = new SovereignSpecFloor(
            $oracle,
            $witnessResolver ?? new SelfComposedWitnessResolver,
            $configMinDiscriminating,
        );
    }

    public function contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict
    {
        return $this->floor->contest($draft, $intent, $lane);
    }

    /**
     * Strangler entry: build a draft + intent from a Dev delivery's composed-spec evidence and contest it.
     *
     * @param  array<string,mixed>  $evidence
     */
    public function contestDevSpec(array $evidence, TrustLevel $lane = TrustLevel::Dev): SpecVerdict
    {
        return $this->contest(
            SpecDraft::fromArray((array) ($evidence['spec'] ?? $evidence)),
            IntentEnvelope::fromArray((array) ($evidence['intent'] ?? [])),
            $lane,
        );
    }
}
