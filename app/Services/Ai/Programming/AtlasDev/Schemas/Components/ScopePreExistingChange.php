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
        public readonly ?string $beforeHash = null,
        public readonly ?string $afterHash = null,
    ) {
        if ($this->path === '') {
            throw new InvalidArgumentException('ScopePreExistingChange.path must not be empty.');
        }
        foreach (['beforeHash' => $this->beforeHash, 'afterHash' => $this->afterHash] as $name => $hash) {
            if ($hash !== null && preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException("ScopePreExistingChange.{$name} must be a sha256 hash.");
            }
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
            beforeHash: array_key_exists('before_hash', $payload) ? AtlasDevSchemaArray::nullableString($payload, 'before_hash') : null,
            afterHash: array_key_exists('after_hash', $payload) ? AtlasDevSchemaArray::nullableString($payload, 'after_hash') : null,
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        $payload = [
            'path' => $this->path,
            'preserved' => $this->preserved,
        ];
        if ($this->beforeHash !== null || $this->afterHash !== null) {
            $payload['before_hash'] = $this->beforeHash;
            $payload['after_hash'] = $this->afterHash;
        }

        return CanonicalJson::canonicalize($payload);
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

    public function contentPreserved(): ?bool
    {
        if ($this->beforeHash === null && $this->afterHash === null) {
            return null;
        }
        if ($this->beforeHash === null || $this->afterHash === null) {
            return false;
        }

        return hash_equals($this->beforeHash, $this->afterHash);
    }
}
