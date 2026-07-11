<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final readonly class OutcomeObservation
{
    private function __construct(
        public string $schemaVersion,
        public string $runId,
        public string $deliveryId,
        public string $releaseHash,
        public string $window,
        public string $observedAt,
        public array $metrics,
        public array $provenance,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $schema = CanonicalKernelPayload::requireString($data, 'schema_version');
        if ($schema !== 'atlas.outcome_observation.v1') {
            throw new InvalidArgumentException('schema_version_invalid');
        }
        $observedAt = CanonicalKernelPayload::requireString($data, 'observed_at');
        if (date_create_immutable($observedAt) === false) {
            throw new InvalidArgumentException('observed_at_invalid');
        }

        return new self(
            schemaVersion: $schema,
            runId: CanonicalKernelPayload::requireString($data, 'run_id'),
            deliveryId: CanonicalKernelPayload::requireString($data, 'delivery_id'),
            releaseHash: CanonicalKernelPayload::requireHash($data, 'release_hash'),
            window: CanonicalKernelPayload::requireEnum($data, 'window', EngineeringOutcome::WINDOWS),
            observedAt: $observedAt,
            metrics: CanonicalKernelPayload::requireArray($data, 'metrics'),
            provenance: CanonicalKernelPayload::requireArray($data, 'provenance'),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'run_id' => $this->runId,
            'delivery_id' => $this->deliveryId,
            'release_hash' => $this->releaseHash,
            'window' => $this->window,
            'observed_at' => $this->observedAt,
            'metrics' => $this->metrics,
            'provenance' => $this->provenance,
        ];
    }

    public function canonicalHash(): string
    {
        return CanonicalKernelPayload::hash($this->toArray());
    }
}
