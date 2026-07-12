<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

final readonly class CapabilityMarketDecision
{
    public function __construct(
        public ?string $selectedRoute,
        public array $candidateSet,
        public array $rejected,
        public string $decisionHash,
        public bool $claimEligible = false,
        public array $evidenceRefs = [],
        public array $availability = [],
        public ?int $estimatedTimeMs = null,
        public ?float $estimatedCost = null,
        public ?string $explorationRef = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'selected_route' => $this->selectedRoute,
            'candidate_set' => $this->candidateSet,
            'rejected' => $this->rejected,
            'decision_hash' => $this->decisionHash,
            'claim_eligible' => $this->claimEligible,
            'evidence_refs' => $this->evidenceRefs,
            'availability' => $this->availability,
            'estimated_time_ms' => $this->estimatedTimeMs,
            'estimated_cost' => $this->estimatedCost,
            'exploration_ref' => $this->explorationRef,
        ];
    }
}
