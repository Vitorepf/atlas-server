<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Canonical `atlas.dev.review_receipt.v1` contract.
 *
 * Atlas Dev Review Intelligence emits ONE receipt per review request. The
 * receipt is bug-focused (not style-focused): findings are prioritised by
 * severity descending, missing tests are flagged with `test_gap=true`, and
 * regression/security/performance risks are surfaced as distinct
 * `risk_type` taxonomies.
 *
 * The receipt does NOT auto-apply remediation. It is the structured artifact
 * the operator (or a downstream loop) consumes. The contract enforces:
 *
 *   - status reflects honest readiness (`reviewed` only when findings exist OR
 *     `scope` allowed empty review e.g. trivial diff);
 *   - blocker_insufficient_context when diff/owners/risk_rules missing;
 *   - findings sorted by `severityRank` ascending at emit time so the most
 *     critical risks are always position 0.
 */
final class ReviewReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.review_receipt.v1';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_BLOCKED_INSUFFICIENT_CONTEXT = 'blocked_insufficient_context';

    public const STATUS_NO_CONCERNS = 'no_concerns';

    public const STATUS_ESCALATE = 'escalate';

    public const ALLOWED_STATUSES = [
        self::STATUS_REVIEWED,
        self::STATUS_BLOCKED_INSUFFICIENT_CONTEXT,
        self::STATUS_NO_CONCERNS,
        self::STATUS_ESCALATE,
    ];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<string>  $reviewedFiles
     * @param  list<ReviewFinding>  $findings
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockerReasons
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $runId,
        public readonly string $status,
        public readonly array $reviewedFiles,
        public readonly array $findings,
        public readonly int $missingTestsCount,
        public readonly float $confidence,
        public readonly array $evidenceRefs,
        public readonly array $blockerReasons,
        public readonly string $createdAt,
        public readonly string $receiptHash,
    ) {
        if (trim($this->receiptId) === '') {
            throw new InvalidArgumentException('ReviewReceipt.receipt_id must not be empty.');
        }
        if (trim($this->runId) === '') {
            throw new InvalidArgumentException('ReviewReceipt.run_id must not be empty.');
        }
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'ReviewReceipt.status must be one of ['.implode(',', self::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "ReviewReceipt.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        if ($this->missingTestsCount < 0) {
            throw new InvalidArgumentException('ReviewReceipt.missing_tests_count must be >= 0.');
        }
        foreach ($this->reviewedFiles as $i => $f) {
            if (! is_string($f) || trim($f) === '') {
                throw new InvalidArgumentException("ReviewReceipt.reviewed_files[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->findings as $i => $f) {
            if (! $f instanceof ReviewFinding) {
                throw new InvalidArgumentException("ReviewReceipt.findings[{$i}] must be a ReviewFinding.");
            }
        }
        foreach ($this->evidenceRefs as $i => $r) {
            if (! is_string($r) || trim($r) === '') {
                throw new InvalidArgumentException("ReviewReceipt.evidence_refs[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->blockerReasons as $i => $r) {
            if (! is_string($r) || trim($r) === '') {
                throw new InvalidArgumentException("ReviewReceipt.blocker_reasons[{$i}] must be a non-empty string.");
            }
        }
        if (trim($this->createdAt) === '') {
            throw new InvalidArgumentException('ReviewReceipt.created_at must not be empty.');
        }
        if (trim($this->receiptHash) === '') {
            throw new InvalidArgumentException('ReviewReceipt.receipt_hash must not be empty.');
        }

        // Invariants -------------------------------------------------------
        // 1. blocked_insufficient_context requires blocker_reasons[] non-empty.
        if ($this->status === self::STATUS_BLOCKED_INSUFFICIENT_CONTEXT && $this->blockerReasons === []) {
            throw new InvalidArgumentException(
                'ReviewReceipt invariant: status=blocked_insufficient_context requires blocker_reasons[] non-empty.'
            );
        }
        // 2. status=reviewed requires findings[] non-empty (use no_concerns if
        //    diff was reviewed and nothing surfaced).
        if ($this->status === self::STATUS_REVIEWED && $this->findings === []) {
            throw new InvalidArgumentException(
                'ReviewReceipt invariant: status=reviewed requires findings[] non-empty (use no_concerns for empty review).'
            );
        }
        // 3. findings must be pre-sorted by severityRank ascending; emitters
        //    that skip the sort would let "info" mask a "blocker" at the top.
        $prev = -1;
        foreach ($this->findings as $i => $f) {
            $rank = $f->severityRank();
            if ($rank < $prev) {
                throw new InvalidArgumentException(
                    "ReviewReceipt invariant: findings[{$i}] severityRank ({$rank}) regresses from previous ({$prev}); sort by severity descending before emit."
                );
            }
            $prev = $rank;
        }
        // 4. status=escalate requires at least one blocker/critical finding OR
        //    a blocker_reason — otherwise escalation is unjustified.
        if ($this->status === self::STATUS_ESCALATE) {
            $highest = collect($this->findings)->min(fn (ReviewFinding $f): int => $f->severityRank());
            $hasHighFinding = $highest !== null && $highest <= ReviewFinding::SEVERITY_RANK[ReviewFinding::SEVERITY_CRITICAL];
            if (! $hasHighFinding && $this->blockerReasons === []) {
                throw new InvalidArgumentException(
                    'ReviewReceipt invariant: status=escalate requires at least one critical/blocker finding OR a blocker_reason.'
                );
            }
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'blocker_reasons' => array_values($this->blockerReasons),
            'confidence' => $this->confidence,
            'created_at' => $this->createdAt,
            'evidence_refs' => array_values($this->evidenceRefs),
            'findings' => array_map(
                static fn (ReviewFinding $f): array => $f->toCanonicalArray(),
                $this->findings,
            ),
            'findings_count' => count($this->findings),
            'missing_tests_count' => $this->missingTestsCount,
            'provider_safe' => true,
            'receipt_hash' => $this->receiptHash,
            'receipt_id' => $this->receiptId,
            'reviewed_files' => array_values($this->reviewedFiles),
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        // JSON_PRESERVE_ZERO_FRACTION keeps `confidence: 0.0` as `0.0` so
        // the round-trip matches `toCanonicalArray()` byte-for-byte.
        return (string) json_encode(
            $this->toCanonicalArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function hash(): string
    {
        $payload = $this->toCanonicalArray();
        unset($payload[self::HASH_FIELD]);

        return CanonicalHasher::hash($payload);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * Static factory with deterministic hash.
     *
     * @param  list<string>  $reviewedFiles
     * @param  list<ReviewFinding>  $findings  pre-sorted by severityRank ascending
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockerReasons
     */
    public static function issue(
        string $receiptId,
        string $runId,
        string $status,
        array $reviewedFiles,
        array $findings,
        int $missingTestsCount,
        float $confidence,
        array $evidenceRefs,
        array $blockerReasons,
        string $createdAt,
    ): self {
        $skeleton = new self(
            receiptId: $receiptId,
            runId: $runId,
            status: $status,
            reviewedFiles: $reviewedFiles,
            findings: $findings,
            missingTestsCount: $missingTestsCount,
            confidence: $confidence,
            evidenceRefs: $evidenceRefs,
            blockerReasons: $blockerReasons,
            createdAt: $createdAt,
            receiptHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            receiptId: $receiptId,
            runId: $runId,
            status: $status,
            reviewedFiles: $reviewedFiles,
            findings: $findings,
            missingTestsCount: $missingTestsCount,
            confidence: $confidence,
            evidenceRefs: $evidenceRefs,
            blockerReasons: $blockerReasons,
            createdAt: $createdAt,
            receiptHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new InvalidArgumentException("Field 'confidence' must be numeric.");
        }
        $findings = [];
        foreach ((array) ($payload['findings'] ?? []) as $f) {
            if (! is_array($f)) {
                throw new InvalidArgumentException('ReviewReceipt.findings entries must be arrays.');
            }
            $findings[] = ReviewFinding::fromArray($f);
        }

        return new self(
            receiptId: AtlasDevSchemaArray::string($payload, 'receipt_id'),
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            status: AtlasDevSchemaArray::string($payload, 'status'),
            reviewedFiles: AtlasDevSchemaArray::stringList($payload, 'reviewed_files'),
            findings: $findings,
            missingTestsCount: AtlasDevSchemaArray::int($payload, 'missing_tests_count'),
            confidence: (float) $payload['confidence'],
            evidenceRefs: AtlasDevSchemaArray::stringList($payload, 'evidence_refs'),
            blockerReasons: AtlasDevSchemaArray::stringList($payload, 'blocker_reasons'),
            createdAt: AtlasDevSchemaArray::string($payload, 'created_at'),
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }
}
