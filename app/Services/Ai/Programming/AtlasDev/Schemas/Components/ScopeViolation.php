<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class ScopeViolation implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.scope_violation.v1';

    public const KIND_FORBIDDEN_TOUCH = 'forbidden_touch';
    public const KIND_WATCHED_TOUCH = 'watched_touch';
    public const KIND_UNEXPECTED_TOUCH = 'unexpected_touch';
    public const KIND_EXCEEDED_MAX_FILES = 'exceeded_max_files';
    public const KIND_PRE_EXISTING_CHANGE = 'pre_existing_change';

    public const ALLOWED_KINDS = [
        self::KIND_FORBIDDEN_TOUCH,
        self::KIND_WATCHED_TOUCH,
        self::KIND_UNEXPECTED_TOUCH,
        self::KIND_EXCEEDED_MAX_FILES,
        self::KIND_PRE_EXISTING_CHANGE,
    ];

    public const FAILING_KINDS = [
        self::KIND_FORBIDDEN_TOUCH,
        self::KIND_EXCEEDED_MAX_FILES,
    ];

    public const NEEDS_REVIEW_KINDS = [
        self::KIND_UNEXPECTED_TOUCH,
        self::KIND_WATCHED_TOUCH,
        self::KIND_PRE_EXISTING_CHANGE,
    ];

    public function __construct(
        public readonly string $kind,
        public readonly ?string $path,
        public readonly string $detail,
        public readonly bool $providerSafe = true,
    ) {
        if (! in_array($this->kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException(
                "ScopeViolation.kind must be one of [".implode(',', self::ALLOWED_KINDS)."], got '{$this->kind}'."
            );
        }
        if ($this->detail === '') {
            throw new InvalidArgumentException('ScopeViolation.detail must not be empty.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            kind: AtlasDevSchemaArray::string($payload, 'kind'),
            path: AtlasDevSchemaArray::nullableString($payload, 'path'),
            detail: AtlasDevSchemaArray::string($payload, 'detail'),
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
        );
    }

    public function isFailing(): bool
    {
        return in_array($this->kind, self::FAILING_KINDS, true);
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'detail' => $this->detail,
            'kind' => $this->kind,
            'path' => $this->path,
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
