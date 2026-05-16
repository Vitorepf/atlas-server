<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class ProviderLock implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.provider_lock.v1';

    public function __construct(
        public readonly string $provider,
        public readonly string $modelFamily,
        public readonly bool $fallbackAllowed = false,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            provider: AtlasDevSchemaArray::string($payload, 'provider'),
            modelFamily: AtlasDevSchemaArray::string($payload, 'model_family'),
            fallbackAllowed: array_key_exists('fallback_allowed', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'fallback_allowed')
                : false,
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
            'fallback_allowed' => $this->fallbackAllowed,
            'model_family' => $this->modelFamily,
            'provider' => $this->provider,
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
