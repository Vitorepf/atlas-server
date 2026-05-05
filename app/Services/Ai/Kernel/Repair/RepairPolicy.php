<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Decision\DecisionRepairPolicy;

final readonly class RepairPolicy
{
    /**
     * @param  array<int,string>  $allowedStrategies
     * @param  array<int,string>  $heavyStrategies
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public bool $enabled,
        public int $maxAttempts,
        public array $allowedStrategies,
        public bool $requiresEvidenceForHeavyRepair = true,
        public array $heavyStrategies = [
            RepairStrategy::RerunTool->value,
            RepairStrategy::RerunHarness->value,
        ],
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            enabled: array_key_exists('enabled', $payload)
                ? RepairPayloadNormalizer::boolean($payload['enabled'], false)
                : true,
            maxAttempts: max(0, (int) ($payload['max_attempts'] ?? 1)),
            allowedStrategies: self::normalizeStrategies($payload['allowed_strategies'] ?? RepairStrategy::values()),
            requiresEvidenceForHeavyRepair: RepairPayloadNormalizer::boolean($payload['requires_evidence_for_heavy_repair'] ?? true),
            heavyStrategies: self::normalizeStrategies($payload['heavy_strategies'] ?? [
                RepairStrategy::RerunTool->value,
                RepairStrategy::RerunHarness->value,
            ]),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    public static function fromDecisionRepairPolicy(DecisionRepairPolicy $policy): self
    {
        return self::fromArray($policy->toArray());
    }

    public function allows(string $strategy): bool
    {
        return in_array($strategy, $this->allowedStrategies, true);
    }

    public function isHeavy(string $strategy): bool
    {
        return in_array($strategy, $this->heavyStrategies, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_attempts' => $this->maxAttempts,
            'allowed_strategies' => $this->allowedStrategies,
            'requires_evidence_for_heavy_repair' => $this->requiresEvidenceForHeavyRepair,
            'heavy_strategies' => $this->heavyStrategies,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeStrategies(mixed $strategies): array
    {
        if (! is_array($strategies)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                fn (mixed $strategy): ?string => is_string($strategy) ? trim($strategy) : null,
                $strategies,
            ),
            fn (?string $strategy): bool => $strategy !== null && in_array($strategy, RepairStrategy::values(), true),
        )));
    }
}
