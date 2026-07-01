<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

final readonly class TaskEnvelope
{
    /** Conservative default when a caller omits risk_level: treat as high-risk until proven otherwise. */
    public const DEFAULT_RISK_LEVEL = 'high';

    /** Conservative default when a caller omits proof_floor: require the strictest evidence bar. */
    public const DEFAULT_PROOF_FLOOR = 'strict';

    /**
     * @param  list<string>  $requiredCapabilities
     * @param  list<string>  $providerIds
     */
    public function __construct(
        public string $taskId,
        public string $kind,
        public array $requiredCapabilities,
        public string $deadline,
        public bool $localOnly,
        public string $sensitivityClass,
        public array $providerIds = [],
        public ?string $riskLevel = null,
        public ?string $proofFloor = null,
        public string $lane = '',
        public float $expectedValue = 0.0,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'kind' => $this->kind,
            'required_capabilities' => array_values($this->requiredCapabilities),
            'required_capability' => array_values($this->requiredCapabilities),
            'deadline' => $this->deadline,
            'local_only_bool' => $this->localOnly,
            'sensitivity_class' => $this->sensitivityClass,
            'provider_ids' => array_values($this->providerIds),
            'risk_level' => $this->riskLevel ?? self::DEFAULT_RISK_LEVEL,
            'proof_floor' => $this->proofFloor ?? self::DEFAULT_PROOF_FLOOR,
            'lane' => $this->lane,
            'expected_value' => $this->expectedValue,
        ];
    }
}
