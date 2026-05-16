<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class ScopeBaseline implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.scope_baseline.v1';

    public function __construct(
        public readonly string $gitStatusBefore,
        public readonly ?string $gitDiffBeforeHash,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            gitStatusBefore: AtlasDevSchemaArray::string($payload, 'git_status_before'),
            gitDiffBeforeHash: AtlasDevSchemaArray::nullableString($payload, 'git_diff_before_hash'),
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
            'git_diff_before_hash' => $this->gitDiffBeforeHash,
            'git_status_before' => $this->gitStatusBefore,
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
