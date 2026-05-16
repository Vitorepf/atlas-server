<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class CompletionSummary implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.completion_summary.v1';

    public const STATUS_PASSED = 'passed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ESCALATE_FORGE = 'escalate_forge';
    public const STATUS_NO_PATCH_NEEDED = 'no_patch_needed';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_FAILED,
        self::STATUS_BLOCKED,
        self::STATUS_ESCALATE_FORGE,
        self::STATUS_NO_PATCH_NEEDED,
    ];

    /**
     * @param  list<string>  $honestyFlags
     * @param  list<string>  $residualRisks
     */
    public function __construct(
        public readonly string $status,
        public readonly array $honestyFlags,
        public readonly array $residualRisks,
        public readonly bool $providerSafe = true,
    ) {
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "CompletionSummary.status must be one of [".implode(',', self::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        if ($this->status === self::STATUS_PASSED && $this->honestyFlags !== []) {
            throw new InvalidArgumentException(
                'CompletionSummary invariant: status=passed forbids honesty_flags.'
            );
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            status: AtlasDevSchemaArray::string($payload, 'status'),
            honestyFlags: AtlasDevSchemaArray::stringList($payload, 'honesty_flags'),
            residualRisks: AtlasDevSchemaArray::stringList($payload, 'residual_risks'),
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
            'honesty_flags' => array_values($this->honestyFlags),
            'residual_risks' => array_values($this->residualRisks),
            'status' => $this->status,
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
