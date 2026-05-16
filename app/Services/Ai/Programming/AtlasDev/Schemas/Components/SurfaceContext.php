<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class SurfaceContext implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.surface_context.v1';

    public function __construct(
        public readonly string $productSurface,
        public readonly ?string $threadId = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $composerMode = null,
        public readonly ?string $composerTask = null,
        public readonly ?string $providerChoice = null,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            productSurface: AtlasDevSchemaArray::string($payload, 'product_surface'),
            threadId: AtlasDevSchemaArray::nullableString($payload, 'thread_id'),
            conversationId: AtlasDevSchemaArray::nullableString($payload, 'conversation_id'),
            composerMode: AtlasDevSchemaArray::nullableString($payload, 'composer_mode'),
            composerTask: AtlasDevSchemaArray::nullableString($payload, 'composer_task'),
            providerChoice: AtlasDevSchemaArray::nullableString($payload, 'provider_choice'),
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
            'composer_mode' => $this->composerMode,
            'composer_task' => $this->composerTask,
            'conversation_id' => $this->conversationId,
            'product_surface' => $this->productSurface,
            'provider_choice' => $this->providerChoice,
            'thread_id' => $this->threadId,
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
