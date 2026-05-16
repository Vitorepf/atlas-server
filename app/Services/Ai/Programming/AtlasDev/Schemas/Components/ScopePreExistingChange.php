<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class ScopePreExistingChange implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.scope_pre_existing_change.v1';

    public function __construct(
        public readonly string $path,
        public readonly bool $preserved,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->path === '') {
            throw new InvalidArgumentException('ScopePreExistingChange.path must not be empty.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            path: AtlasDevSchemaArray::string($payload, 'path'),
            preserved: AtlasDevSchemaArray::bool($payload, 'preserved'),
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
            'path' => $this->path,
            'preserved' => $this->preserved,
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
