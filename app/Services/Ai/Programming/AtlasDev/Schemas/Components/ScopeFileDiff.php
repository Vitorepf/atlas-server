<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class ScopeFileDiff implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.scope_file_diff.v1';

    public function __construct(
        public readonly string $path,
        public readonly int $added,
        public readonly int $removed,
        public readonly string $fileHashAfter,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->path === '') {
            throw new InvalidArgumentException('ScopeFileDiff.path must not be empty.');
        }
        if ($this->added < 0 || $this->removed < 0) {
            throw new InvalidArgumentException('ScopeFileDiff added/removed must be non-negative.');
        }
        if ($this->fileHashAfter === '') {
            throw new InvalidArgumentException('ScopeFileDiff.file_hash_after must not be empty.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            path: AtlasDevSchemaArray::string($payload, 'path'),
            added: AtlasDevSchemaArray::int($payload, 'added'),
            removed: AtlasDevSchemaArray::int($payload, 'removed'),
            fileHashAfter: AtlasDevSchemaArray::string($payload, 'file_hash_after'),
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
            'added' => $this->added,
            'file_hash_after' => $this->fileHashAfter,
            'path' => $this->path,
            'removed' => $this->removed,
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
