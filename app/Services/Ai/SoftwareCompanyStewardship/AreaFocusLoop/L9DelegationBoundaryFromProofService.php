<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S131 — L9 (Sovereign Engineering, Q2) delegation boundary derived from proof.
 *
 * Pure, deterministic computation of which engineering decision classes may be
 * delegated, bounded strictly by the risk level that verified proofs cover. The
 * boundary is set by proof, never by confidence alone: a requested class is only
 * allowed when its risk rank sits at or below the highest rank proven by a
 * verified proof. Classes whose risk class is unknown are blocked; classes above
 * the proven boundary are blocked and keep the operator override requirement.
 *
 * Risk lexicon mirrors the canonical engineering risk ranking already used by
 * sibling loop services (low/medium/high/critical), with rank 0 ("none")
 * reserved for "no proof has been verified".
 */
final class L9DelegationBoundaryFromProofService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.delegation_boundary_from_proof.v1';

    /**
     * Canonical engineering risk ranking, mirrored byte-for-byte from
     * AreaFocusSpecDraftBridge::RISK_RANK (low/medium/high/critical).
     */
    private const RISK_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
    ];

    private const NO_PROOF_RISK_LEVEL = 'none';

    /**
     * @param  list<array<string, mixed>>  $proofs  verified-proof envelopes; each may carry
     *                                               verified(bool), risk_level(string), proof_ref(string)
     * @param  list<array<string, mixed>>  $requestedDelegation  requested decision classes; each may carry
     *                                                            decision_class(string), risk_level(string)
     * @return array{
     *     schema_version: string,
     *     allowed_decision_classes: list<string>,
     *     blocked_decision_classes: list<string>,
     *     max_risk_level: string,
     *     proof_refs: list<string>,
     *     operator_override_required: bool
     * }
     */
    public function compute(array $proofs, array $requestedDelegation): array
    {
        $provenRank = 0;
        $proofRefs = [];

        foreach ($proofs as $proof) {
            if (! is_array($proof)) {
                continue;
            }

            if (($proof['verified'] ?? false) !== true) {
                continue;
            }

            $rank = $this->riskRank($proof['risk_level'] ?? null);
            if ($rank > $provenRank) {
                $provenRank = $rank;
            }

            $ref = $this->stringValue($proof['proof_ref'] ?? ($proof['proof_id'] ?? null));
            if ($ref !== '') {
                $proofRefs[$ref] = true;
            }
        }

        $allowed = [];
        $blocked = [];
        $overrideRequired = false;

        foreach ($requestedDelegation as $request) {
            if (! is_array($request)) {
                continue;
            }

            $class = $this->stringValue($request['decision_class'] ?? null);
            if ($class === '') {
                continue;
            }

            $rank = $this->riskRank($request['risk_level'] ?? null);

            // Unknown risk class blocks: it can never be inside a proven boundary.
            if ($rank === 0) {
                $blocked[$class] = true;
                $overrideRequired = true;

                continue;
            }

            if ($provenRank > 0 && $rank <= $provenRank) {
                $allowed[$class] = true;

                continue;
            }

            // Above the proven boundary (or nothing proven): delegation is blocked
            // and the operator override remains required above the boundary.
            $blocked[$class] = true;
            $overrideRequired = true;
        }

        // Block dominates: a class blocked by any request can never also be
        // reported as allowed. Delegation is bounded by proof, so a single
        // above-boundary (or unknown-risk) request poisons the whole class.
        foreach (array_keys($blocked) as $blockedClass) {
            unset($allowed[$blockedClass]);
        }

        // array_keys() coerces numeric-string keys (e.g. a decision_class of '100')
        // back to int, which would break the declared list<string> contract and let
        // SORT_REGULAR order them numerically. Re-cast to string and sort with
        // SORT_STRING so the output is a stable, lexicographic list<string>.
        $allowedClasses = array_map('strval', array_keys($allowed));
        sort($allowedClasses, SORT_STRING);

        $blockedClasses = array_map('strval', array_keys($blocked));
        sort($blockedClasses, SORT_STRING);

        $refs = array_map('strval', array_keys($proofRefs));
        sort($refs, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'allowed_decision_classes' => array_values($allowedClasses),
            'blocked_decision_classes' => array_values($blockedClasses),
            'max_risk_level' => $provenRank > 0 ? $this->riskLevelForRank($provenRank) : self::NO_PROOF_RISK_LEVEL,
            'proof_refs' => array_values($refs),
            'operator_override_required' => $overrideRequired,
        ];
    }

    private function riskRank(mixed $value): int
    {
        if (! is_string($value)) {
            return 0;
        }

        $normalized = strtolower(trim($value));

        return self::RISK_RANK[$normalized] ?? 0;
    }

    private function riskLevelForRank(int $rank): string
    {
        foreach (self::RISK_RANK as $level => $levelRank) {
            if ($levelRank === $rank) {
                return $level;
            }
        }

        return self::NO_PROOF_RISK_LEVEL;
    }

    private function stringValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }
}
