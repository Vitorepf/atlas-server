<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugRepairCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugSuspectedCause;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Canonical `atlas.dev.debug_receipt.v1` contract.
 *
 * Atlas Dev Debug Intelligence emits ONE receipt per debug request. The
 * receipt is honest about what Atlas KNOWS (failure fingerprint, classification,
 * suspected causes, repair candidates, evidence backing) and what it DOES NOT
 * know (status `blocked_insufficient_context` with explicit `blocker_reasons`).
 *
 * Atlas Dev does NOT execute repair candidates here — the receipt is the
 * structured output the operator or downstream loop (RepairOrchestrator,
 * Forge handoff) consumes. The contract aligns with the canonical
 * AtlasDev/Schemas pattern: value object + `issue()` factory + canonical
 * hash + provider-safe by default.
 *
 * Stop conditions are explicit (not implicit): `same_signature_twice`,
 * `diff_growth_without_progress`, `attempt_budget_exceeded`,
 * `human_review_requested` — when ANY of these fires the receipt is sealed
 * with the proper status and the caller must escalate, not retry.
 */
final class DebugReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.debug_receipt.v1';

    public const STATUS_DIAGNOSED = 'diagnosed';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public const STATUS_BLOCKED_INSUFFICIENT_CONTEXT = 'blocked_insufficient_context';

    public const STATUS_ESCALATE = 'escalate';

    public const ALLOWED_STATUSES = [
        self::STATUS_DIAGNOSED,
        self::STATUS_INCONCLUSIVE,
        self::STATUS_BLOCKED_INSUFFICIENT_CONTEXT,
        self::STATUS_ESCALATE,
    ];

    /** Canonical stop conditions taxonomy. */
    public const STOP_SAME_SIGNATURE_TWICE = 'same_signature_twice';

    public const STOP_DIFF_GROWTH_WITHOUT_PROGRESS = 'diff_growth_without_progress';

    public const STOP_ATTEMPT_BUDGET_EXCEEDED = 'attempt_budget_exceeded';

    public const STOP_HUMAN_REVIEW_REQUESTED = 'human_review_requested';

    public const STOP_INSUFFICIENT_CONTEXT = 'insufficient_context';

    public const STOP_AMBIGUOUS_CAUSES = 'ambiguous_causes';

    public const ALLOWED_STOP_CONDITIONS = [
        self::STOP_SAME_SIGNATURE_TWICE,
        self::STOP_DIFF_GROWTH_WITHOUT_PROGRESS,
        self::STOP_ATTEMPT_BUDGET_EXCEEDED,
        self::STOP_HUMAN_REVIEW_REQUESTED,
        self::STOP_INSUFFICIENT_CONTEXT,
        self::STOP_AMBIGUOUS_CAUSES,
    ];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<string>  $reproductionPlan  ordered commands the operator can run
     * @param  list<DebugSuspectedCause>  $suspectedCauses
     * @param  list<DebugRepairCandidate>  $repairCandidates
     * @param  list<string>  $stopConditions
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockerReasons
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $runId,
        public readonly string $status,
        public readonly string $failureFingerprint,
        public readonly string $failureClassification,
        public readonly string $primaryErrorExcerpt,
        public readonly array $reproductionPlan,
        public readonly array $suspectedCauses,
        public readonly array $repairCandidates,
        public readonly float $confidence,
        public readonly array $stopConditions,
        public readonly array $evidenceRefs,
        public readonly array $blockerReasons,
        public readonly string $createdAt,
        public readonly string $receiptHash,
    ) {
        if (trim($this->receiptId) === '') {
            throw new InvalidArgumentException('DebugReceipt.receipt_id must not be empty.');
        }
        if (trim($this->runId) === '') {
            throw new InvalidArgumentException('DebugReceipt.run_id must not be empty.');
        }
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'DebugReceipt.status must be one of ['.implode(',', self::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', $this->failureFingerprint)) {
            throw new InvalidArgumentException(
                "DebugReceipt.failure_fingerprint must be 'sha256:<64 hex>', got '{$this->failureFingerprint}'."
            );
        }
        if (trim($this->failureClassification) === '') {
            throw new InvalidArgumentException('DebugReceipt.failure_classification must not be empty.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "DebugReceipt.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        foreach ($this->reproductionPlan as $i => $step) {
            if (! is_string($step) || trim($step) === '') {
                throw new InvalidArgumentException("DebugReceipt.reproduction_plan[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->suspectedCauses as $i => $c) {
            if (! $c instanceof DebugSuspectedCause) {
                throw new InvalidArgumentException("DebugReceipt.suspected_causes[{$i}] must be a DebugSuspectedCause.");
            }
        }
        foreach ($this->repairCandidates as $i => $c) {
            if (! $c instanceof DebugRepairCandidate) {
                throw new InvalidArgumentException("DebugReceipt.repair_candidates[{$i}] must be a DebugRepairCandidate.");
            }
        }
        foreach ($this->stopConditions as $i => $s) {
            if (! is_string($s) || ! in_array($s, self::ALLOWED_STOP_CONDITIONS, true)) {
                throw new InvalidArgumentException(
                    "DebugReceipt.stop_conditions[{$i}] must be one of [".implode(',', self::ALLOWED_STOP_CONDITIONS)."], got '".(is_scalar($s) ? (string) $s : gettype($s))."'."
                );
            }
        }
        foreach ($this->evidenceRefs as $i => $r) {
            if (! is_string($r) || trim($r) === '') {
                throw new InvalidArgumentException("DebugReceipt.evidence_refs[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->blockerReasons as $i => $r) {
            if (! is_string($r) || trim($r) === '') {
                throw new InvalidArgumentException("DebugReceipt.blocker_reasons[{$i}] must be a non-empty string.");
            }
        }
        if (trim($this->createdAt) === '') {
            throw new InvalidArgumentException('DebugReceipt.created_at must not be empty.');
        }
        if (trim($this->receiptHash) === '') {
            throw new InvalidArgumentException('DebugReceipt.receipt_hash must not be empty.');
        }

        // Invariants -------------------------------------------------------
        // 1. blocked_insufficient_context requires at least one blocker reason
        //    AND insufficient_context stop. Anything else would be a silent miss.
        if ($this->status === self::STATUS_BLOCKED_INSUFFICIENT_CONTEXT) {
            if ($this->blockerReasons === []) {
                throw new InvalidArgumentException(
                    'DebugReceipt invariant: status=blocked_insufficient_context requires blocker_reasons[] non-empty.'
                );
            }
            if (! in_array(self::STOP_INSUFFICIENT_CONTEXT, $this->stopConditions, true)) {
                throw new InvalidArgumentException(
                    'DebugReceipt invariant: status=blocked_insufficient_context requires stop_conditions to include insufficient_context.'
                );
            }
        }
        // 2. status=diagnosed requires at least one suspected_cause AND at least
        //    one repair_candidate, otherwise it is dishonest.
        if ($this->status === self::STATUS_DIAGNOSED) {
            if ($this->suspectedCauses === []) {
                throw new InvalidArgumentException(
                    'DebugReceipt invariant: status=diagnosed requires suspected_causes[] non-empty.'
                );
            }
            if ($this->repairCandidates === []) {
                throw new InvalidArgumentException(
                    'DebugReceipt invariant: status=diagnosed requires repair_candidates[] non-empty.'
                );
            }
        }
        // 3. status=escalate requires at least one stop_condition explaining why.
        if ($this->status === self::STATUS_ESCALATE && $this->stopConditions === []) {
            throw new InvalidArgumentException(
                'DebugReceipt invariant: status=escalate requires at least one stop_condition.'
            );
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
            'failure_classification' => $this->failureClassification,
            'failure_fingerprint' => $this->failureFingerprint,
            'primary_error_excerpt' => $this->primaryErrorExcerpt,
            'provider_safe' => true,
            'receipt_hash' => $this->receiptHash,
            'receipt_id' => $this->receiptId,
            'repair_candidates' => array_map(
                static fn (DebugRepairCandidate $c): array => $c->toCanonicalArray(),
                $this->repairCandidates,
            ),
            'reproduction_plan' => array_values($this->reproductionPlan),
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status,
            'stop_conditions' => array_values($this->stopConditions),
            'suspected_causes' => array_map(
                static fn (DebugSuspectedCause $c): array => $c->toCanonicalArray(),
                $this->suspectedCauses,
            ),
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        // JSON_PRESERVE_ZERO_FRACTION keeps `confidence: 0.0` as `0.0` in the
        // serialised payload so the round-trip (`json_decode(toJson())`)
        // matches `toCanonicalArray()` byte-for-byte. Without it, PHP
        // emits `0` for `0.0` and the canonical contract surface fails on
        // assertSame.
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
     * Deterministic fingerprint over the failure shape. Mirrors
     * `FailureCapsule::signatureOf` so debug receipts and repair capsules
     * share the same identity for the same failure surface.
     */
    public static function fingerprintOf(string $gate, string $primaryErrorExcerpt): string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $primaryErrorExcerpt)));

        return 'sha256:'.hash('sha256', $gate.'::'.$normalized);
    }

    /**
     * Static factory with deterministic hash. Mirrors EscalationDecision::issue.
     *
     * @param  list<string>  $reproductionPlan
     * @param  list<DebugSuspectedCause>  $suspectedCauses
     * @param  list<DebugRepairCandidate>  $repairCandidates
     * @param  list<string>  $stopConditions
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockerReasons
     */
    public static function issue(
        string $receiptId,
        string $runId,
        string $status,
        string $failureFingerprint,
        string $failureClassification,
        string $primaryErrorExcerpt,
        array $reproductionPlan,
        array $suspectedCauses,
        array $repairCandidates,
        float $confidence,
        array $stopConditions,
        array $evidenceRefs,
        array $blockerReasons,
        string $createdAt,
    ): self {
        $skeleton = new self(
            receiptId: $receiptId,
            runId: $runId,
            status: $status,
            failureFingerprint: $failureFingerprint,
            failureClassification: $failureClassification,
            primaryErrorExcerpt: $primaryErrorExcerpt,
            reproductionPlan: $reproductionPlan,
            suspectedCauses: $suspectedCauses,
            repairCandidates: $repairCandidates,
            confidence: $confidence,
            stopConditions: $stopConditions,
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
            failureFingerprint: $failureFingerprint,
            failureClassification: $failureClassification,
            primaryErrorExcerpt: $primaryErrorExcerpt,
            reproductionPlan: $reproductionPlan,
            suspectedCauses: $suspectedCauses,
            repairCandidates: $repairCandidates,
            confidence: $confidence,
            stopConditions: $stopConditions,
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
        $causes = [];
        foreach ((array) ($payload['suspected_causes'] ?? []) as $c) {
            if (! is_array($c)) {
                throw new InvalidArgumentException('DebugReceipt.suspected_causes entries must be arrays.');
            }
            $causes[] = DebugSuspectedCause::fromArray($c);
        }
        $candidates = [];
        foreach ((array) ($payload['repair_candidates'] ?? []) as $c) {
            if (! is_array($c)) {
                throw new InvalidArgumentException('DebugReceipt.repair_candidates entries must be arrays.');
            }
            $candidates[] = DebugRepairCandidate::fromArray($c);
        }

        return new self(
            receiptId: AtlasDevSchemaArray::string($payload, 'receipt_id'),
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            status: AtlasDevSchemaArray::string($payload, 'status'),
            failureFingerprint: AtlasDevSchemaArray::string($payload, 'failure_fingerprint'),
            failureClassification: AtlasDevSchemaArray::string($payload, 'failure_classification'),
            primaryErrorExcerpt: AtlasDevSchemaArray::string($payload, 'primary_error_excerpt'),
            reproductionPlan: AtlasDevSchemaArray::stringList($payload, 'reproduction_plan'),
            suspectedCauses: $causes,
            repairCandidates: $candidates,
            confidence: (float) $payload['confidence'],
            stopConditions: AtlasDevSchemaArray::stringList($payload, 'stop_conditions'),
            evidenceRefs: AtlasDevSchemaArray::stringList($payload, 'evidence_refs'),
            blockerReasons: AtlasDevSchemaArray::stringList($payload, 'blocker_reasons'),
            createdAt: AtlasDevSchemaArray::string($payload, 'created_at'),
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }
}
