<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class Preflight implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.preflight.v1';

    public function __construct(
        public readonly bool $workspaceResolved,
        public readonly string $permissionMode,
        public readonly bool $writeAllowed,
        public readonly bool $operatorExplicit,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            workspaceResolved: AtlasDevSchemaArray::bool($payload, 'workspace_resolved'),
            permissionMode: AtlasDevSchemaArray::string($payload, 'permission_mode'),
            writeAllowed: AtlasDevSchemaArray::bool($payload, 'write_allowed'),
            operatorExplicit: AtlasDevSchemaArray::bool($payload, 'operator_explicit'),
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
            'operator_explicit' => $this->operatorExplicit,
            'permission_mode' => $this->permissionMode,
            'workspace_resolved' => $this->workspaceResolved,
            'write_allowed' => $this->writeAllowed,
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
