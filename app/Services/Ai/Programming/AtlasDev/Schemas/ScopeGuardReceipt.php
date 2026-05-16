<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeViolation;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class ScopeGuardReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.scope_guard_receipt.v1';

    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_REVIEW,
    ];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<ScopeViolation>  $violations
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly ScopeBaseline $baseline,
        public readonly ScopeObserved $observed,
        public readonly ScopeContractView $scopeContract,
        public readonly array $violations,
        public readonly string $status,
        public readonly string $statusReason,
        public readonly array $userPreExistingChanges,
        public readonly string $receiptHash,
    ) {
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "ScopeGuardReceipt.status must be one of [".implode(',', self::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        foreach ($this->violations as $i => $v) {
            if (! $v instanceof ScopeViolation) {
                throw new InvalidArgumentException("violations[{$i}] must be ScopeViolation.");
            }
        }
        foreach ($this->userPreExistingChanges as $i => $c) {
            if (! $c instanceof ScopePreExistingChange) {
                throw new InvalidArgumentException("user_pre_existing_changes[{$i}] must be ScopePreExistingChange.");
            }
        }
        $this->assertStatusInvariants();
    }

    public function isBlocking(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'baseline' => $this->baseline->toCanonicalArray(),
            'observed' => $this->observed->toCanonicalArray(),
            'provider_safe' => true,
            'receipt_hash' => $this->receiptHash,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'scope_contract' => $this->scopeContract->toCanonicalArray(),
            'status' => $this->status,
            'status_reason' => $this->statusReason,
            'task_contract_hash' => $this->taskContractHash,
            'user_pre_existing_changes' => array_map(
                static fn (ScopePreExistingChange $c): array => $c->toCanonicalArray(),
                array_values($this->userPreExistingChanges),
            ),
            'violations' => array_map(
                static fn (ScopeViolation $v): array => $v->toCanonicalArray(),
                array_values($this->violations),
            ),
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
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * Compute the canonical receipt_hash for the given payload pieces.
     * Callers build the DTO with the empty placeholder hash, call computeReceiptHash(),
     * then rebuild with the real hash. Or simpler: use ::issue().
     */
    public static function computeReceiptHash(array $payloadWithoutHash): string
    {
        return CanonicalHasher::hash($payloadWithoutHash);
    }

    /**
     * Construct + seal: returns a DTO whose receipt_hash is the canonical hash of
     * every other field. Use this in callers instead of manually hashing.
     *
     * @param  list<ScopeViolation>  $violations
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        ScopeBaseline $baseline,
        ScopeObserved $observed,
        ScopeContractView $scopeContract,
        array $violations,
        string $status,
        string $statusReason,
        array $userPreExistingChanges,
    ): self {
        $skeleton = new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            baseline: $baseline,
            observed: $observed,
            scopeContract: $scopeContract,
            violations: $violations,
            status: $status,
            statusReason: $statusReason,
            userPreExistingChanges: $userPreExistingChanges,
            receiptHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            baseline: $baseline,
            observed: $observed,
            scopeContract: $scopeContract,
            violations: $violations,
            status: $status,
            statusReason: $statusReason,
            userPreExistingChanges: $userPreExistingChanges,
            receiptHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        $violationsRaw = (array) ($payload['violations'] ?? []);
        $violations = [];
        foreach (array_values($violationsRaw) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("violations[{$i}] must be an array.");
            }
            $violations[] = ScopeViolation::fromArray($raw);
        }

        $changesRaw = (array) ($payload['user_pre_existing_changes'] ?? []);
        $changes = [];
        foreach (array_values($changesRaw) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("user_pre_existing_changes[{$i}] must be an array.");
            }
            $changes[] = ScopePreExistingChange::fromArray($raw);
        }

        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            taskContractHash: AtlasDevSchemaArray::string($payload, 'task_contract_hash'),
            baseline: ScopeBaseline::fromArray((array) $payload['baseline']),
            observed: ScopeObserved::fromArray((array) $payload['observed']),
            scopeContract: ScopeContractView::fromArray((array) $payload['scope_contract']),
            violations: $violations,
            status: AtlasDevSchemaArray::string($payload, 'status'),
            statusReason: AtlasDevSchemaArray::string($payload, 'status_reason'),
            userPreExistingChanges: $changes,
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }

    private function assertStatusInvariants(): void
    {
        $hasForbidden = false;
        $hasExceeded = false;
        $hasNeedsReview = false;
        foreach ($this->violations as $v) {
            if ($v->kind === ScopeViolation::KIND_FORBIDDEN_TOUCH) {
                $hasForbidden = true;
            }
            if ($v->kind === ScopeViolation::KIND_EXCEEDED_MAX_FILES) {
                $hasExceeded = true;
            }
            if (in_array($v->kind, ScopeViolation::NEEDS_REVIEW_KINDS, true)) {
                $hasNeedsReview = true;
            }
        }

        $hasUnpreserved = false;
        foreach ($this->userPreExistingChanges as $c) {
            if (! $c->preserved) {
                $hasUnpreserved = true;
                break;
            }
        }

        // Invariant 2 & 4 & 5: forbidden_touch / exceeded_max_files / unpreserved → failed
        $mustFail = $hasForbidden || $hasExceeded || $hasUnpreserved;
        if ($mustFail && $this->status !== self::STATUS_FAILED) {
            throw new InvalidArgumentException(
                'ScopeGuardReceipt invariant: forbidden_touch / exceeded_max_files / unpreserved pre-existing change force status=failed.'
            );
        }

        // Invariant 6: status=passed requires empty violations
        if ($this->status === self::STATUS_PASSED && $this->violations !== []) {
            throw new InvalidArgumentException(
                'ScopeGuardReceipt invariant: status=passed requires empty violations.'
            );
        }

        // Invariant 3: unexpected/watched_touch/pre_existing_change without a failing kind → needs_review
        if ($hasNeedsReview && ! $mustFail && $this->status === self::STATUS_PASSED) {
            throw new InvalidArgumentException(
                'ScopeGuardReceipt invariant: watched/unexpected/pre_existing_change violations require status=needs_review.'
            );
        }
    }
}
