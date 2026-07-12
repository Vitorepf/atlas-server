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
        public string $orderHash,
        public string $outcomeHash,
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
        $parsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $observedAt);
        if ($parsed === false || $parsed->format(DATE_ATOM) !== $observedAt) {
            throw new InvalidArgumentException('observed_at_invalid');
        }

        $metrics = CanonicalKernelPayload::requireArray($data, 'metrics');
        if (! is_string($metrics['status'] ?? null) || trim($metrics['status']) === '') {
            throw new InvalidArgumentException('outcome_observation_status_required');
        }
        $provenance = $data['provenance'] ?? null;
        if (! is_array($provenance) || $provenance === []) {
            throw new InvalidArgumentException('outcome_observation_source_required');
        }
        if (! is_string($provenance['source'] ?? null) || trim($provenance['source']) === '') {
            throw new InvalidArgumentException('outcome_observation_source_required');
        }
        $releaseAt = $provenance['release_at'] ?? null;
        if (! is_string($releaseAt) || trim($releaseAt) === '') {
            throw new InvalidArgumentException('outcome_observation_release_at_required');
        }
        $releaseAtParsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $releaseAt);
        if ($releaseAtParsed === false || $releaseAtParsed->format(DATE_ATOM) !== $releaseAt) {
            throw new InvalidArgumentException('outcome_observation_release_at_invalid');
        }
        $observedParsed = $parsed;
        if ($releaseAtParsed > $observedParsed) {
            throw new InvalidArgumentException('outcome_observation_window_not_elapsed');
        }
        $window = CanonicalKernelPayload::requireEnum($data, 'window', EngineeringOutcome::WINDOWS);
        $minimumObservedAt = $releaseAtParsed->modify('+'.self::windowHours($window).' hours');
        if ($minimumObservedAt === false || $observedParsed < $minimumObservedAt) {
            throw new InvalidArgumentException('outcome_observation_window_not_elapsed');
        }
        foreach (['spec_hash', 'world_hash'] as $hashName) {
            if (! is_string($provenance[$hashName] ?? null) || preg_match('/^[a-f0-9]{64}$/', $provenance[$hashName]) !== 1) {
                throw new InvalidArgumentException('outcome_observation_'.$hashName.'_required');
            }
        }
        if (! is_array($provenance['uncertainty'] ?? null)) {
            throw new InvalidArgumentException('outcome_observation_uncertainty_required');
        }

        return new self(
            schemaVersion: $schema,
            runId: CanonicalKernelPayload::requireString($data, 'run_id'),
            deliveryId: CanonicalKernelPayload::requireString($data, 'delivery_id'),
            releaseHash: CanonicalKernelPayload::requireHash($data, 'release_hash'),
            orderHash: CanonicalKernelPayload::requireHash($data, 'order_hash'),
            outcomeHash: CanonicalKernelPayload::requireHash($data, 'outcome_hash'),
            window: $window,
            observedAt: $observedAt,
            metrics: $metrics,
            provenance: $provenance,
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
            'order_hash' => $this->orderHash,
            'outcome_hash' => $this->outcomeHash,
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

    private static function windowHours(string $window): int
    {
        return match ($window) {
            '0h' => 0,
            '24h' => 24,
            '7d' => 7 * 24,
            '30d' => 30 * 24,
            '90d' => 90 * 24,
            '150d' => 150 * 24,
        };
    }
}
