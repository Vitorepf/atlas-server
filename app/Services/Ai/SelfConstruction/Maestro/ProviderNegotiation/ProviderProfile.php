<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

final readonly class ProviderProfile
{
    /**
     * @param  list<string>  $declaredCapabilities
     * @param  list<string>  $sensitivityAllowed
     * @param  array<string,mixed>  $extras
     */
    public function __construct(
        public string $providerId,
        public array $declaredCapabilities,
        public float $observedCostPerTokenIn,
        public float $observedCostPerTokenOut,
        public int $observedP50LatencyMs,
        public int $currentLoadPct,
        public string $locality,
        public array $sensitivityAllowed,
        public array $extras = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'declared_capabilities' => array_values($this->declaredCapabilities),
            'observed_cost_per_token_in' => $this->observedCostPerTokenIn,
            'observed_cost_per_token_out' => $this->observedCostPerTokenOut,
            'observed_p50_latency_ms' => $this->observedP50LatencyMs,
            'current_load_pct' => $this->currentLoadPct,
            'locality' => $this->locality,
            'sensitivity_allowed' => array_values($this->sensitivityAllowed),
            'extras' => $this->extras,
        ];
    }
}
