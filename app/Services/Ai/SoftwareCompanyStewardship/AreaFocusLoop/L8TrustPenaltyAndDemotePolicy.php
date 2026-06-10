<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S105 — L8TrustPenaltyAndDemotePolicy (L8 Transcendence, phase P5).
 *
 * Converts the divergence signal produced by the metric-reality divergence
 * detector (S103) and the self-deception adversarial probe (S104) into Trust
 * Ledger penalties and a demote_required signal. It NEVER applies a promotion
 * and NEVER mutates ledger state — it only projects what the score becomes
 * once the penalty is appended, and whether that projected score crosses the
 * demote threshold.
 *
 * Doctrine (atlas-trust-ledger-canonical + atlas-aaeos-l8-transcendence-map P5):
 * Trust follows independent reality, not self-reported success. A gaming /
 * Goodhart signal lowers the projected Trust score automatically and forces a
 * demote without any signature once the score falls below the L7 gate; an
 * absence of divergence leaves the score untouched; and an absence of any
 * ledger evidence is fail-closed to unknown_blocked rather than a silent pass.
 *
 * The policy is pure: every returned field is computed from the two input
 * arrays via the canonical Trust Ledger weight magnitudes, with no IO, clock,
 * randomness, DB, Eloquent, facade or provider call.
 */
final class L8TrustPenaltyAndDemotePolicy
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.trust_penalty_policy.v1';

    /**
     * Demote gate mirrored from the Trust Ledger canonical thresholds table
     * (score >= 0.95 is the L7-eligible floor). A projected score below this
     * after a gaming penalty forces an automatic demote.
     */
    private const DEMOTE_THRESHOLD = 0.95;

    /**
     * Maximum score delta a full-severity (severity == 1.0) gaming signal can
     * subtract from the current Trust score. Derived from the canonical
     * drift_detected / gate_fail weight magnitude (0.5) expressed as a 0..1
     * score delta: a fully-confirmed gaming event must be able to knock a
     * near-perfect score below the 0.95 demote gate, while a trivially small
     * divergence only nudges the score and stays above the gate.
     */
    private const MAX_PENALTY = 0.5;

    /**
     * Floor and ceiling for any Trust score (the canonical ledger score is a
     * sigmoid output bounded to 0..1).
     */
    private const TRUST_SCORE_FLOOR = 0.0;

    private const TRUST_SCORE_CEILING = 1.0;

    private const STATUS_UNKNOWN_BLOCKED = 'unknown_blocked';

    private const STATUS_TRUST_UNCHANGED = 'trust_unchanged';

    private const STATUS_TRUST_PENALIZED = 'trust_penalized';

    private const STATUS_DEMOTE_REQUIRED = 'demote_required';

    private const BLOCKER_NO_LEDGER_EVIDENCE = 'no_ledger_evidence';

    /**
     * @param  array<string, mixed>  $divergence  S103/S104-shaped divergence payload.
     * @param  array<string, mixed>  $trust  Trust Ledger state (current score + evidence flag).
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     gaming_detected: bool,
     *     ledger_evidence_present: bool,
     *     severity: float,
     *     current_trust_score: float,
     *     penalty_applied: float,
     *     projected_trust_score: float,
     *     demote_required: bool,
     *     blockers: list<string>
     * }
     */
    public function decide(array $divergence, array $trust): array
    {
        $currentScore = $this->clampScore(AreaFocusScalarNormalizer::payloadFloat($trust, 'current_trust_score', 0.0));
        $ledgerEvidencePresent = $this->ledgerEvidencePresent($trust);

        // Fail-closed: without ledger evidence the policy cannot project a score
        // movement, so it blocks instead of silently passing.
        if (! $ledgerEvidencePresent) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_UNKNOWN_BLOCKED,
                'gaming_detected' => false,
                'ledger_evidence_present' => false,
                'severity' => 0.0,
                'current_trust_score' => $currentScore,
                'penalty_applied' => 0.0,
                'projected_trust_score' => $currentScore,
                'demote_required' => false,
                'blockers' => [self::BLOCKER_NO_LEDGER_EVIDENCE],
            ];
        }

        $gamingDetected = $this->gamingDetected($divergence);

        // No divergence and no gaming: Trust is unchanged, no penalty, no demote.
        if (! $gamingDetected) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_TRUST_UNCHANGED,
                'gaming_detected' => false,
                'ledger_evidence_present' => true,
                'severity' => 0.0,
                'current_trust_score' => $currentScore,
                'penalty_applied' => 0.0,
                'projected_trust_score' => $currentScore,
                'demote_required' => false,
                'blockers' => [],
            ];
        }

        // Gaming confirmed: derive a 0..1 severity from the divergence payload,
        // turn it into a bounded penalty, lower the projected Trust score, and
        // force a demote only when the projected score crosses the L7 gate.
        $severity = $this->severity($divergence);
        $penalty = $this->roundScore($severity * self::MAX_PENALTY);
        $projectedScore = $this->clampScore($currentScore - $penalty);
        $demoteRequired = $projectedScore < self::DEMOTE_THRESHOLD;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $demoteRequired ? self::STATUS_DEMOTE_REQUIRED : self::STATUS_TRUST_PENALIZED,
            'gaming_detected' => true,
            'ledger_evidence_present' => true,
            'severity' => $severity,
            'current_trust_score' => $currentScore,
            'penalty_applied' => $penalty,
            'projected_trust_score' => $projectedScore,
            'demote_required' => $demoteRequired,
            'blockers' => [],
        ];
    }

    /**
     * Ledger evidence is present when the caller explicitly flags it OR carries
     * a usable score source (a numeric current score or a non-empty event list
     * / evidence-ref list). Absent any of these the policy is fail-closed.
     *
     * @param  array<string, mixed>  $trust
     */
    private function ledgerEvidencePresent(array $trust): bool
    {
        foreach (['ledger_evidence_present', 'has_ledger_evidence', 'ledger_present'] as $flag) {
            if (array_key_exists($flag, $trust)) {
                return $trust[$flag] === true;
            }
        }

        if ($this->nonEmptyList($trust, 'evidence_refs') || $this->nonEmptyList($trust, 'events')) {
            return true;
        }

        if (AreaFocusScalarNormalizer::payloadInt($trust, 'event_count', 0) > 0) {
            return true;
        }

        return AreaFocusScalarNormalizer::numericValue($trust['current_trust_score'] ?? null);
    }

    /**
     * Gaming is detected when the divergence payload (S103/S104-shaped) flags it
     * directly, reports a detected divergence, or enumerates at least one
     * adversarial / divergent case.
     *
     * @param  array<string, mixed>  $divergence
     */
    private function gamingDetected(array $divergence): bool
    {
        foreach (['gaming_detected', 'divergence_detected'] as $flag) {
            if (($divergence[$flag] ?? false) === true) {
                return true;
            }
        }

        if ($this->countList($divergence, 'divergent_metrics') > 0) {
            return true;
        }

        if ($this->countList($divergence, 'adversarial_cases') > 0) {
            return true;
        }

        return false;
    }

    /**
     * Derive a 0..1 severity for the confirmed gaming signal. An explicit
     * severity wins; otherwise it is the share of optimized metrics that
     * diverged from their independent anchors (count of divergent metrics over
     * the anchored-metric population). A confirmed gaming signal without any
     * resolvable magnitude defaults to full severity (1.0) — fail-closed toward
     * the strongest penalty.
     *
     * @param  array<string, mixed>  $divergence
     */
    private function severity(array $divergence): float
    {
        if (AreaFocusScalarNormalizer::numericValue($divergence['severity'] ?? null)) {
            return $this->clampUnit(AreaFocusScalarNormalizer::payloadFloat($divergence, 'severity', 1.0));
        }

        $divergentCount = $this->countList($divergence, 'divergent_metrics')
            + $this->countList($divergence, 'adversarial_cases');

        if ($divergentCount > 0) {
            $population = $this->population($divergence);

            return $this->clampUnit($this->roundScore($divergentCount / $population));
        }

        return self::TRUST_SCORE_CEILING;
    }

    /**
     * Anchored-metric population used to normalise the divergent-metric count.
     * Uses an explicit anchor/metric count when supplied, otherwise the number
     * of anchored metrics listed, and never less than the number of divergent
     * metrics themselves (so the share can never exceed 1.0) nor less than 1.
     *
     * @param  array<string, mixed>  $divergence
     */
    private function population(array $divergence): int
    {
        $divergentCount = $this->countList($divergence, 'divergent_metrics')
            + $this->countList($divergence, 'adversarial_cases');

        $explicit = 0;
        foreach (['anchor_count', 'optimized_metric_count', 'metric_count'] as $key) {
            $candidate = AreaFocusScalarNormalizer::payloadInt($divergence, $key, 0);
            if ($candidate > $explicit) {
                $explicit = $candidate;
            }
        }

        $listed = $this->countList($divergence, 'anchored_metrics')
            + $this->countList($divergence, 'optimized_metrics');

        $population = max($explicit, $listed, $divergentCount, 1);

        return $population;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nonEmptyList(array $payload, string $key): bool
    {
        return AreaFocusLoopPayloadNormalizer::payloadHasNonEmptyArray($payload, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countList(array $payload, string $key): int
    {
        return AreaFocusLoopPayloadNormalizer::payloadArrayCount($payload, $key);
    }

    private function clampScore(float $value): float
    {
        return $this->roundScore(min(self::TRUST_SCORE_CEILING, max(self::TRUST_SCORE_FLOOR, $value)));
    }

    private function clampUnit(float $value): float
    {
        return $this->roundScore(min(self::TRUST_SCORE_CEILING, max(self::TRUST_SCORE_FLOOR, $value)));
    }

    private function roundScore(float $value): float
    {
        return round($value, 6);
    }
}
