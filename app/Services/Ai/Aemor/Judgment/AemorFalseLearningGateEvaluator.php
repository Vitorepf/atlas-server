<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor\Judgment;

/**
 * Pure projection of the AtlasAemorJudgmentService false-learning gate kernel.
 *
 * Mirrors AtlasAemorJudgmentService::falseLearningGate byte-for-byte for the
 * equivalent primitive inputs: it decides whether an outcome may be promoted
 * into learning, or must be blocked because the evidence/test/attribution
 * preconditions are not met.
 *
 * Zero constructor dependencies. No I/O, DB, Eloquent, facades, clock or
 * randomness. Every returned field is computed from the method inputs.
 */
final class AemorFalseLearningGateEvaluator
{
    private const SCHEMA_VERSION = 'atlas.aemor.false_learning_gate.v1';

    private const STATUS_PASS = 'pass';

    private const STATUS_BLOCKED = 'blocked_for_learning';

    private const BLOCKER_MISSING_EVIDENCE_REFS = 'missing_evidence_refs';

    private const BLOCKER_SUCCESS_WITHOUT_EVIDENCE = 'success_without_test_or_gate_evidence';

    private const BLOCKER_ALTERNATIVES_NOT_REVIEWED = 'alternative_explanations_not_reviewed';

    private const OUTCOME_SUCCEEDED = 'succeeded';

    /**
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     learning_allowed: bool,
     *     blockers: list<string>,
     * }
     */
    public function evaluate(
        bool $hasEvidenceRefs,
        string $outcomeStatus,
        ?bool $testsPassed,
        int $alternativeExplanationCount,
        bool $attributionReviewed
    ): array {
        $blockers = [];

        // R1: an outcome with no evidence refs cannot feed learning.
        if (! $hasEvidenceRefs) {
            $blockers[] = self::BLOCKER_MISSING_EVIDENCE_REFS;
        }

        // R2: a claimed success without a passing test/gate proof is suspect.
        if ($outcomeStatus === self::OUTCOME_SUCCEEDED && $testsPassed !== true) {
            $blockers[] = self::BLOCKER_SUCCESS_WITHOUT_EVIDENCE;
        }

        // R3: unreviewed competing explanations mean attribution is unproven.
        if ($alternativeExplanationCount > 0 && $attributionReviewed !== true) {
            $blockers[] = self::BLOCKER_ALTERNATIVES_NOT_REVIEWED;
        }

        $clean = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            // R4: status is pass only when no blocker fired.
            'status' => $clean ? self::STATUS_PASS : self::STATUS_BLOCKED,
            // R5: learning is allowed only when no blocker fired.
            'learning_allowed' => $clean,
            'blockers' => $blockers,
        ];
    }
}
