<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P1 frame-change "measured-or-reverted" decision.
 *
 * A structural frame change is governed exactly like a code change: it is
 * proposed, replayed across real Obras, then KEPT, REVERTED or ARCHIVED based
 * on measured outcomes. This service reads the replay result together with the
 * composite-lift signal (dm_dt) and the P5 self-deception (Goodhart) divergence
 * signal and emits the keep/revert/archive decision. It is read-only: it never
 * applies a revert, never writes config or state and never calls a provider.
 *
 * Decision rules, applied in this fixed order (fail-closed first):
 *   1. Missing measured evidence            -> unknown_blocked (cannot prove)
 *   2. regression_count > 0                  -> revert_required (measured break)
 *   3. P5 divergence / gaming detected       -> revert_required (self-deception)
 *   4. positive dm_dt, no P5 divergence      -> keep_candidate
 *   5. evidence present but no measured lift  -> archive_required (governed learning)
 */
final class L8FrameMeasuredOrRevertedDecisionService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.frame_measured_or_reverted_decision.v1';

    private const DECISION_KEEP_CANDIDATE = 'keep_candidate';
    private const DECISION_REVERT_REQUIRED = 'revert_required';
    private const DECISION_ARCHIVE_REQUIRED = 'archive_required';
    private const DECISION_UNKNOWN_BLOCKED = 'unknown_blocked';

    private const REASON_MISSING_EVIDENCE = 'missing_evidence';
    private const REASON_REGRESSION_OBSERVED = 'regression_observed';
    private const REASON_P5_DIVERGENCE_DETECTED = 'p5_divergence_detected';
    private const REASON_COMPOSITE_LIFT_POSITIVE = 'composite_lift_positive';
    private const REASON_NO_MEASURED_LIFT = 'no_measured_lift';

    /**
     * Decide keep/revert/archive for a frame change from its replay result.
     *
     * @param array<string, mixed> $result
     *
     * @return array{
     *     schema_version: string,
     *     decision: string,
     *     keep: bool,
     *     revert_required: bool,
     *     archive_required: bool,
     *     dm_dt: float,
     *     regression_count: int,
     *     p5_divergence_detected: bool,
     *     reasons: list<string>,
     *     evidence_refs: list<string>
     * }
     */
    public function decide(array $result): array
    {
        $evidenceRefs = $this->evidenceRefs($result);
        $regressionCount = $this->regressionCount($result);
        $dmDt = $this->floatValue($result, 'dm_dt', 0.0);
        $p5Divergence = $this->p5DivergenceDetected($result);

        $reasons = [];

        if ($evidenceRefs === []) {
            $decision = self::DECISION_UNKNOWN_BLOCKED;
            $reasons[] = self::REASON_MISSING_EVIDENCE;
        } elseif ($regressionCount > 0) {
            $decision = self::DECISION_REVERT_REQUIRED;
            $reasons[] = self::REASON_REGRESSION_OBSERVED;
        } elseif ($p5Divergence) {
            $decision = self::DECISION_REVERT_REQUIRED;
            $reasons[] = self::REASON_P5_DIVERGENCE_DETECTED;
        } elseif ($dmDt > 0.0) {
            $decision = self::DECISION_KEEP_CANDIDATE;
            $reasons[] = self::REASON_COMPOSITE_LIFT_POSITIVE;
        } else {
            $decision = self::DECISION_ARCHIVE_REQUIRED;
            $reasons[] = self::REASON_NO_MEASURED_LIFT;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'keep' => $decision === self::DECISION_KEEP_CANDIDATE,
            'revert_required' => $decision === self::DECISION_REVERT_REQUIRED,
            'archive_required' => $decision === self::DECISION_ARCHIVE_REQUIRED,
            'dm_dt' => $dmDt,
            'regression_count' => $regressionCount,
            'p5_divergence_detected' => $p5Divergence,
            'reasons' => $reasons,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * Normalised string evidence references proving the replay actually ran.
     * Empty list means the change is unproven and the decision is fail-closed.
     *
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private function evidenceRefs(array $result): array
    {
        return $this->stringList($result['evidence_refs'] ?? []);
    }

    /**
     * Measured regression count from the replay. Accepts an explicit
     * regression_count or a regression_metrics.regression_count nesting; any
     * negative value is clamped to zero.
     *
     * @param array<string, mixed> $result
     */
    private function regressionCount(array $result): int
    {
        if (array_key_exists('regression_count', $result)) {
            return max(0, $this->intValue($result, 'regression_count', 0));
        }

        $metrics = $result['regression_metrics'] ?? null;
        if (is_array($metrics)) {
            return max(0, $this->intValue($metrics, 'regression_count', 0));
        }

        return 0;
    }

    /**
     * P5 self-deception (Goodhart) divergence signal. True when the immunity
     * layer flagged that reported metrics moved but independent reality anchors
     * did not, mirroring the S103/S104/S105 divergence vocabulary.
     *
     * @param array<string, mixed> $result
     */
    private function p5DivergenceDetected(array $result): bool
    {
        if (($result['p5_divergence_detected'] ?? false) === true) {
            return true;
        }

        if (($result['divergence_detected'] ?? false) === true) {
            return true;
        }

        if (($result['gaming_detected'] ?? false) === true) {
            return true;
        }

        $signal = $result['p5_divergence'] ?? null;
        if (is_array($signal)) {
            return ($signal['divergence_detected'] ?? false) === true
                || ($signal['gaming_detected'] ?? false) === true;
        }

        return false;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intValue(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function floatValue(array $payload, string $key, float $default): float
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }
}
