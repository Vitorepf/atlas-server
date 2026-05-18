<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon\Gate;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Structured result of {@see LongHorizonContextFreshnessGate}.
 *
 * Canon schema: `atlas.long_horizon.context_freshness_gate.v1`.
 *
 * The DTO is intentionally readonly and JSON-stable: callers serialise it
 * into receipts / control-plane snapshots / continuation packs without
 * re-shaping. `freshness_hash` is deterministic over the canonical payload
 * minus itself + `evaluated_at`, so the same finding set yields the same
 * hash on replay.
 */
final class LongHorizonContextFreshnessGateResult
{
    public const SCHEMA_VERSION = 'atlas.long_horizon.context_freshness_gate.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PASS,
        self::STATUS_WARN,
        self::STATUS_BLOCKED,
    ];

    /**
     * Canonical blocking-reason codes. Stable strings so downstream consumers
     * (Recovery Planner, Continuity Certification) can branch on them.
     */
    public const REASON_CONTINUATION_PACK_MISSING = 'continuation_pack_missing';

    public const REASON_SCOPE_MISMATCH = 'pack_scope_mismatch';

    public const REASON_PACK_STALE_AFTER_EXPIRED = 'pack_stale_after_expired';

    public const REASON_MISSING_REQUIRED_REFS = 'missing_required_refs';

    public const REASON_STALE_REFS = 'stale_refs_present';

    public const REASON_SUPERSEDED_DECISIONS = 'superseded_decisions_present';

    public const REASON_EXPIRED_MEMORY = 'expired_memory_refs_present';

    public const REASON_SOURCE_HASH_MISMATCH = 'source_hash_mismatch';

    public const REASON_COMPACTION_UNRESOLVED_LOSS = 'compaction_unresolved_loss';

    public const REASON_COMPACTION_COVERAGE_BELOW_ONE = 'compaction_must_keep_coverage_below_one';

    public const REASON_MISSING_CRITICAL_EVIDENCE = 'missing_critical_evidence';

    public const REASON_STRICT_MODE_ESCALATED_WARN = 'strict_mode_escalated_warning_to_block';

    /**
     * @param  list<string>  $blockingReasons
     * @param  list<string>  $warnings
     * @param  list<array<string,mixed>>  $staleRefs
     * @param  list<array<string,mixed>>  $supersededDecisions
     * @param  list<array<string,mixed>>  $expiredMemoryRefs
     * @param  list<array<string,mixed>>  $sourceHashMismatches
     * @param  list<string>  $missingRequiredRefs
     * @param  array<string,mixed>|null  $compactionLoss
     * @param  list<string>  $remediation
     */
    public function __construct(
        public readonly string $scopeType,
        public readonly ?string $scopeId,
        public readonly string $intendedMode,
        public readonly bool $strict,
        public readonly string $status,
        public readonly array $blockingReasons,
        public readonly array $warnings,
        public readonly array $staleRefs,
        public readonly array $supersededDecisions,
        public readonly array $expiredMemoryRefs,
        public readonly array $sourceHashMismatches,
        public readonly array $missingRequiredRefs,
        public readonly ?array $compactionLoss,
        public readonly ?float $mustKeepCoverage,
        public readonly string $recommendedSafeResumeMode,
        public readonly bool $writeAllowed,
        public readonly array $remediation,
        public readonly string $freshnessHash,
        public readonly string $evaluatedAt,
    ) {}

    /**
     * Stable canonical projection used by callers + tests + audit ledger.
     *
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'blocking_reasons' => array_values($this->blockingReasons),
            'compaction_loss' => $this->compactionLoss,
            'evaluated_at' => $this->evaluatedAt,
            'expired_memory_refs' => array_values($this->expiredMemoryRefs),
            'freshness_hash' => $this->freshnessHash,
            'intended_mode' => $this->intendedMode,
            'missing_required_refs' => array_values($this->missingRequiredRefs),
            'must_keep_coverage' => $this->mustKeepCoverage,
            'recommended_safe_resume_mode' => $this->recommendedSafeResumeMode,
            'remediation' => array_values($this->remediation),
            'schema_version' => self::SCHEMA_VERSION,
            'scope_id' => $this->scopeId,
            'scope_type' => $this->scopeType,
            'source_hash_mismatches' => array_values($this->sourceHashMismatches),
            'stale_refs' => array_values($this->staleRefs),
            'status' => $this->status,
            'strict' => $this->strict,
            'superseded_decisions' => array_values($this->supersededDecisions),
            'warnings' => array_values($this->warnings),
            'write_allowed' => $this->writeAllowed,
        ];
    }

    /**
     * Hash payload mirrors `toCanonicalArray()` minus volatile fields so the
     * same finding-set yields a stable identifier across replays.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function canonicalFreshnessHash(array $payload): string
    {
        unset($payload['freshness_hash'], $payload['evaluated_at']);

        return MissionCanonicalHash::sha256($payload);
    }
}
