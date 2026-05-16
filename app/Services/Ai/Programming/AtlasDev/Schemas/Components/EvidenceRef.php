<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Dual reference: local filesystem path plus optional Governance ledger ref.
 *
 * Atlas Dev keeps receipts under storage/atlas-dev/receipts/<run_id>/. The same
 * artifact may also be mirrored in the Evidence Governance ledger, in which
 * case governance_ledger_ref points at the ledger row. The key is preserved
 * even when null so downstream auditors can tell "no ledger record" from
 * "field was dropped".
 */
final class EvidenceRef implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.evidence_ref.v1';

    public const ALLOWED_KINDS = [
        'diff',
        'test_log',
        'lint_log',
        'screenshot',
        'manual_review',
        'no_patch_reason',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly string $hash,
        public readonly ?string $governanceLedgerRef = null,
        public readonly bool $providerSafe = true,
    ) {
        if (! in_array($this->kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException(
                "EvidenceRef.kind must be one of [".implode(',', self::ALLOWED_KINDS)."], got '{$this->kind}'."
            );
        }
        if ($this->path === '') {
            throw new InvalidArgumentException('EvidenceRef.path must not be empty.');
        }
        if ($this->hash === '') {
            throw new InvalidArgumentException('EvidenceRef.hash must not be empty.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            kind: AtlasDevSchemaArray::string($payload, 'kind'),
            path: AtlasDevSchemaArray::string($payload, 'path'),
            hash: AtlasDevSchemaArray::string($payload, 'hash'),
            governanceLedgerRef: AtlasDevSchemaArray::nullableString($payload, 'governance_ledger_ref'),
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
            'governance_ledger_ref' => $this->governanceLedgerRef,
            'hash' => $this->hash,
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

    public function withGovernanceLedgerRef(?string $ref): self
    {
        return new self(
            kind: $this->kind,
            path: $this->path,
            hash: $this->hash,
            governanceLedgerRef: $ref,
            providerSafe: $this->providerSafe,
        );
    }
}
