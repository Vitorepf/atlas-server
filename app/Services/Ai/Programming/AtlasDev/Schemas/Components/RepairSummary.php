<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class RepairSummary implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.repair_summary.v1';

    /**
     * @param  list<string>  $failureCapsuleRefs
     */
    public function __construct(
        public readonly int $attemptCount,
        public readonly array $failureCapsuleRefs,
        public readonly bool $convertedToGreen,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->attemptCount < 0) {
            throw new InvalidArgumentException('RepairSummary.attempt_count must be non-negative.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            attemptCount: AtlasDevSchemaArray::int($payload, 'attempt_count'),
            failureCapsuleRefs: AtlasDevSchemaArray::stringList($payload, 'failure_capsule_refs'),
            convertedToGreen: AtlasDevSchemaArray::bool($payload, 'converted_to_green'),
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
            'attempt_count' => $this->attemptCount,
            'converted_to_green' => $this->convertedToGreen,
            'failure_capsule_refs' => array_values($this->failureCapsuleRefs),
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
