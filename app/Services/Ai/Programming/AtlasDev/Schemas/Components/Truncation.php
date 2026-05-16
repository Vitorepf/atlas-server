<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class Truncation implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.truncation.v1';

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly bool $truncated,
        public readonly array $reasons,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            truncated: AtlasDevSchemaArray::bool($payload, 'truncated'),
            reasons: AtlasDevSchemaArray::stringList($payload, 'reasons'),
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
            'reasons' => array_values($this->reasons),
            'truncated' => $this->truncated,
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
