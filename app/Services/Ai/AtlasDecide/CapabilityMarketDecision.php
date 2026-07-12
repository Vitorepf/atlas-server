<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

final readonly class CapabilityMarketDecision
{
    public function __construct(public ?string $selectedRoute, public array $candidateSet, public array $rejected, public string $decisionHash, public bool $claimEligible = false) {}
}
