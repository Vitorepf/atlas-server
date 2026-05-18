<?php

namespace App\Services\Ai\LongHorizon;

use RuntimeException;

/**
 * Thrown when a memory delta cannot be promoted into a TEOS-I1 long-horizon
 * scope (`obra` / `long_horizon`) without satisfying the canonical guard
 * rules.
 *
 * Distinct reason codes — surfaced to operators + tests so the failure mode
 * is unambiguous:
 *  - `missing_evidence_refs`   — promotion attempted with empty evidence.
 *  - `missing_operator_review` — no `operator_review_decision=approved`
 *                                and no `operator_review_proposal_id`.
 *  - `local_agent_raw_blocked` — LAMI candidate trying to skip the
 *                                proposal/review surface and land directly
 *                                in durable long-horizon memory.
 *  - `unknown_authority_level` — `authority_level` not in canon set OR
 *                                missing entirely.
 *  - `low_authority_requires_review` — `authority_level=inferred` is allowed
 *                                only when an operator review is attached.
 */
class LongHorizonMemoryPromotionRefusedException extends RuntimeException
{
    public const REASON_MISSING_EVIDENCE_REFS = 'missing_evidence_refs';

    public const REASON_MISSING_OPERATOR_REVIEW = 'missing_operator_review';

    public const REASON_LOCAL_AGENT_RAW_BLOCKED = 'local_agent_raw_blocked';

    public const REASON_UNKNOWN_AUTHORITY_LEVEL = 'unknown_authority_level';

    public const REASON_LOW_AUTHORITY_REQUIRES_REVIEW = 'low_authority_requires_review';

    /**
     * @param  array<int,string>  $reasons
     */
    public static function withReasons(string $scope, array $reasons): self
    {
        return new self(sprintf(
            'long_horizon_memory_promotion_refused scope=%s reasons=%s',
            $scope,
            implode(',', $reasons),
        ));
    }
}
