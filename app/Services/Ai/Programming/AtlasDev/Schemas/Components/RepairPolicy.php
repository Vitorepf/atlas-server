<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class RepairPolicy implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.repair_policy.v1';

    public function __construct(
        public readonly int $maxAttempts,
        public readonly bool $sameProvider,
        public readonly bool $requiresFailedGateOutput,
        public readonly bool $abortOnSameSignatureTwice,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            maxAttempts: AtlasDevSchemaArray::int($payload, 'max_attempts'),
            sameProvider: array_key_exists('same_provider', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'same_provider')
                : true,
            requiresFailedGateOutput: array_key_exists('requires_failed_gate_output', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'requires_failed_gate_output')
                : true,
            abortOnSameSignatureTwice: array_key_exists('abort_on_same_signature_twice', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'abort_on_same_signature_twice')
                : true,
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'abort_on_same_signature_twice' => $this->abortOnSameSignatureTwice,
            'max_attempts' => $this->maxAttempts,
            'requires_failed_gate_output' => $this->requiresFailedGateOutput,
            'same_provider' => $this->sameProvider,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }
}
