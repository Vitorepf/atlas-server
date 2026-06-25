<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

final readonly class ProviderBid
{
    /**
     * @param  list<string>  $ineligibilityReasons
     */
    public function __construct(
        public string $providerId,
        public int $capabilityScore,
        public bool $eligibilityBool,
        public array $ineligibilityReasons,
        public int $declaredCostUnits,
        public int $declaredEtaMs,
        public string $bidHash,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'capability_score' => $this->capabilityScore,
            'eligibility_bool' => $this->eligibilityBool,
            'ineligibility_reasons' => array_values($this->ineligibilityReasons),
            'declared_cost_units' => $this->declaredCostUnits,
            'declared_eta_ms' => $this->declaredEtaMs,
            'bid_hash' => $this->bidHash,
        ];
    }
}
