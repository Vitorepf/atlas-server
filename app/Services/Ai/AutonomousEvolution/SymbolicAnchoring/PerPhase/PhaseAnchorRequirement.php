<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

final readonly class PhaseAnchorRequirement
{
    /**
     * @param  list<string>  $mustAnchorKinds
     */
    public function __construct(
        public string $phase,
        public float $anchoredSymbolsPerKchar,
        public int $distinctAnchorFloor,
        public array $mustAnchorKinds,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'anchored_symbols_per_kchar' => $this->anchoredSymbolsPerKchar,
            'distinct_anchor_floor' => $this->distinctAnchorFloor,
            'must_anchor_kinds' => $this->mustAnchorKinds,
        ];
    }
}
