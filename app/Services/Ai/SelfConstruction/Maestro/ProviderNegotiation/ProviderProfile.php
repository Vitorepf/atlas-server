<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

final readonly class ProviderProfile
{
    public const SAFETY_STANDARD = 'standard';

    public const COST_CLASS_UNKNOWN = 'unknown';

    /** Key-name substrings (case-insensitive) that mark an `extras` entry as a raw secret/credential — never serialized. */
    private const SENSITIVE_KEY_MARKERS = ['secret', 'token', 'api_key', 'apikey', 'credential', 'password', 'bearer', 'auth_key'];

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
        public string $safetyLevel = self::SAFETY_STANDARD,
        public string $costClass = self::COST_CLASS_UNKNOWN,
        public float $proofStrength = 0.0,
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
            'extras' => $this->safeExtras(),
            'safety_level' => $this->safetyLevel,
            'cost_class' => $this->costClass,
            'proof_strength' => $this->proofStrength,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safeExtras(): array
    {
        $safe = [];
        foreach ($this->extras as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEY_MARKERS as $marker) {
                if (str_contains($lowerKey, $marker)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                continue;
            }
            $safe[$key] = $value;
        }

        return $safe;
    }
}
