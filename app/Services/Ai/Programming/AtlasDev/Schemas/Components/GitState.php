<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class GitState implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.git_state.v1';

    public function __construct(
        public readonly ?string $headSha,
        public readonly bool $dirty,
        public readonly int $untrackedCount,
        public readonly int $pendingChangesCount,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            headSha: AtlasDevSchemaArray::nullableString($payload, 'head_sha'),
            dirty: AtlasDevSchemaArray::bool($payload, 'dirty'),
            untrackedCount: AtlasDevSchemaArray::int($payload, 'untracked_count'),
            pendingChangesCount: AtlasDevSchemaArray::int($payload, 'pending_changes_count'),
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
            'dirty' => $this->dirty,
            'head_sha' => $this->headSha,
            'pending_changes_count' => $this->pendingChangesCount,
            'untracked_count' => $this->untrackedCount,
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
