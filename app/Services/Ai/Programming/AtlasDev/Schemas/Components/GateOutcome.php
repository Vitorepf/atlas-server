<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class GateOutcome implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.gate_outcome.v1';

    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_WAIVED = 'waived';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_SKIPPED,
        self::STATUS_WAIVED,
    ];

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly bool $required,
        public readonly ?string $evidenceRef,
        public readonly bool $fresh,
        public readonly ?string $waiverReason,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException('GateOutcome.name must not be empty.');
        }
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "GateOutcome.status must be one of [".implode(',', self::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        if ($this->status === self::STATUS_WAIVED && ($this->waiverReason === null || $this->waiverReason === '')) {
            throw new InvalidArgumentException('GateOutcome.waiver_reason is required when status=waived.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            name: AtlasDevSchemaArray::string($payload, 'name'),
            status: AtlasDevSchemaArray::string($payload, 'status'),
            required: AtlasDevSchemaArray::bool($payload, 'required'),
            evidenceRef: AtlasDevSchemaArray::nullableString($payload, 'evidence_ref'),
            fresh: AtlasDevSchemaArray::bool($payload, 'fresh'),
            waiverReason: AtlasDevSchemaArray::nullableString($payload, 'waiver_reason'),
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
            'evidence_ref' => $this->evidenceRef,
            'fresh' => $this->fresh,
            'name' => $this->name,
            'required' => $this->required,
            'status' => $this->status,
            'waiver_reason' => $this->waiverReason,
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
