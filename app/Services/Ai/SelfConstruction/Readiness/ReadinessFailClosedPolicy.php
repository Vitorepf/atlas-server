<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * The single named fail-closed status policy for the Readiness aggregate.
 *
 * ARCH BLUEPRINT SelfConstructionReadiness §2.1 (resolves A1-SC-0004/0011/0028):
 * a payload is only as ready as it can PROVE — a missing required field, an
 * authority that is not strictly true, absent evidence, or any blocker demotes
 * the outer status to `blocked` with a typed violation. Pure: no I/O, no clock.
 */
final class ReadinessFailClosedPolicy
{
    public const STATUS_SCHEMA_READY = 'schema_ready';

    public const STATUS_EVIDENCE_PENDING = 'evidence_pending';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_EXECUTABLE = 'executable';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const CANONICAL_STATUSES = [
        self::STATUS_SCHEMA_READY,
        self::STATUS_EVIDENCE_PENDING,
        self::STATUS_AUTHORIZED,
        self::STATUS_EXECUTABLE,
        self::STATUS_COMPLETED,
        self::STATUS_BLOCKED,
    ];

    public const VIOLATION_MISSING_REQUIRED_FIELD = 'missing_required_field';

    public const VIOLATION_AUTHORITY_NOT_GRANTED = 'authority_not_granted';

    public const VIOLATION_EVIDENCE_MISSING = 'evidence_missing';

    public const VIOLATION_BLOCKERS_PRESENT = 'blockers_present';

    public const VIOLATION_UNKNOWN_INNER_STATUS = 'unknown_inner_status';

    /**
     * Outer status ⊇ inner status: the envelope is never more ready than the
     * payload it wraps. Any violation ⇒ `blocked` (never a permissive default).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $requiredFields  dot-paths that must exist (non-null)
     * @param  list<string>  $requiredAuthorities  dot-paths that must be strictly true
     * @param  list<string>  $requiredEvidenceKeys  dot-paths that must be non-empty
     */
    public function decideOuterStatus(
        array $payload,
        array $requiredFields = [],
        array $requiredAuthorities = [],
        array $requiredEvidenceKeys = [],
        string $statusKey = 'status',
    ): ReadinessStatusDecision {
        $violations = [];

        foreach ($requiredFields as $field) {
            if (data_get($payload, $field) === null) {
                $violations[] = ['type' => self::VIOLATION_MISSING_REQUIRED_FIELD, 'key' => $field];
            }
        }

        foreach ($requiredAuthorities as $authority) {
            if (data_get($payload, $authority) !== true) {
                $violations[] = ['type' => self::VIOLATION_AUTHORITY_NOT_GRANTED, 'key' => $authority];
            }
        }

        foreach ($requiredEvidenceKeys as $evidenceKey) {
            $evidence = data_get($payload, $evidenceKey);
            if ($evidence === null || $evidence === '' || $evidence === []) {
                $violations[] = ['type' => self::VIOLATION_EVIDENCE_MISSING, 'key' => $evidenceKey];
            }
        }

        foreach (['blockers', 'blocking_reasons'] as $blockerKey) {
            if ((array) data_get($payload, $blockerKey, []) !== []) {
                $violations[] = ['type' => self::VIOLATION_BLOCKERS_PRESENT, 'key' => $blockerKey];
            }
        }

        $inner = (string) data_get($payload, $statusKey, '');
        if (! in_array($inner, self::CANONICAL_STATUSES, true)) {
            $violations[] = ['type' => self::VIOLATION_UNKNOWN_INNER_STATUS, 'key' => $statusKey];
        }

        if ($violations !== [] || $inner === self::STATUS_BLOCKED) {
            return new ReadinessStatusDecision(self::STATUS_BLOCKED, $violations);
        }

        return new ReadinessStatusDecision($inner);
    }

    /**
     * The single derivation of the non-execution guarantees block
     * (kills the 110+149+50 duplicated literal farms — A1-SC-0013).
     *
     * @return list<string>
     */
    public function nonExecutionGuarantees(string $subject): array
    {
        return [
            "{$subject}_does_not_start_codex",
            "{$subject}_does_not_advance_pointer",
            "{$subject}_does_not_dispatch_work",
            "{$subject}_does_not_execute_adapter",
            "{$subject}_does_not_enable_self_programming",
        ];
    }
}
