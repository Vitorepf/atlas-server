<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class MissingRef implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.missing_ref.v1';

    public function __construct(
        public readonly string $what,
        public readonly string $whyMissing,
    ) {
        if ($what === '' || $whyMissing === '') {
            throw new InvalidArgumentException('MissingRef requires non-empty what and why_missing.');
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'what' => $this->what,
            'why_missing' => $this->whyMissing,
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
        return true;
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            what: AtlasDevSchemaArray::string($payload, 'what'),
            whyMissing: AtlasDevSchemaArray::string($payload, 'why_missing'),
        );
    }
}
