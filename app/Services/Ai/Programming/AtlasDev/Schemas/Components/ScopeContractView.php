<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class ScopeContractView implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.scope_contract_view.v1';

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $watchedFiles
     * @param  list<string>  $forbiddenFiles
     */
    public function __construct(
        public readonly array $allowedFiles,
        public readonly array $watchedFiles,
        public readonly array $forbiddenFiles,
        public readonly int $expectedMaxFiles,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            allowedFiles: AtlasDevSchemaArray::stringList($payload, 'allowed_files'),
            watchedFiles: AtlasDevSchemaArray::stringList($payload, 'watched_files'),
            forbiddenFiles: AtlasDevSchemaArray::stringList($payload, 'forbidden_files'),
            expectedMaxFiles: AtlasDevSchemaArray::int($payload, 'expected_max_files'),
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
            'allowed_files' => array_values($this->allowedFiles),
            'expected_max_files' => $this->expectedMaxFiles,
            'forbidden_files' => array_values($this->forbiddenFiles),
            'watched_files' => array_values($this->watchedFiles),
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
