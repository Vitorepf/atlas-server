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
        ?SpecOracle $oracle = null,
        ?WitnessResolver $witnessResolver = null,
        int $configMinDiscriminating = 0,
        private readonly ?ClarificationSink $clarificationSink = null,
    ) {
        $this->floor = new SovereignSpecFloor(
            $oracle ?? new UnmeasuredSpecOracle, // fail-closed default until a real executional oracle is wired
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
        $intent = IntentEnvelope::fromArray((array) ($evidence['intent'] ?? []));
        $verdict = $this->contest(
            SpecDraft::fromArray((array) ($evidence['spec'] ?? $evidence)),
            $intent,
            $lane,
        );

        // Ship the producer: when the spec HOLDS on unresolved ambiguity, route the findings to the
        // operator clarification queue (side effect kept out of the pure floor / interface path).
        if ($verdict->status === SpecVerdict::HOLD
            && in_array('ambiguity_resolved', $verdict->gaps, true)
            && $this->clarificationSink !== null
            && $verdict->provenance->ambiguityFindings !== []) {
            $this->clarificationSink->enqueue($verdict->provenance->ambiguityFindings, $intent);
        }

        return $verdict;
    }
}
