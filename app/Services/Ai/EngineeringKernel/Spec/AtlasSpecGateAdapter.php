<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Product\AtlasProductTruthCompilerService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

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
        private readonly ?AtlasEvidenceLedger $ledger = null,
    ) {
        $this->floor = new SovereignSpecFloor(
            $oracle ?? new UnmeasuredSpecOracle, // fail-closed default until a real executional oracle is wired
            $witnessResolver ?? new SelfComposedWitnessResolver,
            $configMinDiscriminating,
        );
    }

    public function contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict
    {
        $verdict = $this->floor->contest($draft, $intent, $lane);
        if ($verdict->frozen() && $this->ledger instanceof AtlasEvidenceLedger) {
            $this->recordFrozenUnit($verdict, $intent);
        }

        return $verdict;
    }

    /** @return array<string,mixed> */
    public function adjudicateProductAuthority(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): array
    {
        $truth = (new AtlasProductTruthCompilerService)->compile(['human_request' => $intent->rawGoal]);
        $gaps = array_values(array_unique([...$intent->productAuthorityGaps(), ...$draft->authorityGaps(),
            'product_truth_resolver_unavailable', 'world_model_resolver_unavailable']));
        $verdict = $this->contest($draft, $intent, $lane);
        if (($truth['status'] ?? null) !== 'ready') {
            $gaps[] = 'product_truth_not_ready';
        }
        if (! $verdict->frozen()) {
            $gaps[] = 'spec_not_frozen:'.$verdict->status;
        }
        $specHash = hash('sha256', $intent->productAuthorityHash().$draft->authorityHash());

        return ['status' => $gaps === [] ? 'freeze' : 'hold', 'gaps' => array_values(array_unique($gaps)),
            'spec_hash' => $specHash, 'truth_hash' => $truth['truth_hash'] ?? null,
            'spec_receipt' => SpecReceipt::seal($verdict, $lane), 'product_truth' => $truth];
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

    private function recordFrozenUnit(SpecVerdict $verdict, IntentEnvelope $intent): void
    {
        $frozenHash = $verdict->provenance->frozenHash;
        $eventId = 'unit-frozen-spec-'.substr($frozenHash, 0, 20);
        if ($this->ledger?->eventById($eventId) !== null) {
            return;
        }
        $this->ledger?->record(LedgerEventType::UnitFrozen, [
            'event_name' => 'unit.frozen', 'unit_kind' => 'spec',
            'frozen_hash' => $frozenHash, 'intent_hash' => $intent->productAuthorityHash(),
            'world_observed_at' => $intent->worldObservedAt, 'status' => $verdict->status,
        ], [
            'event_id' => $eventId, 'correlation_id' => $frozenHash,
            'scope_type' => 'spec', 'scope_id' => $frozenHash,
            'emitter_stage' => 'atlas.spec.adversary',
        ]);
    }
}
