<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S163 - L10 R4 operator intent -> validated engineering outcome latency scorer.
 *
 * The L10 R4 fusion criterion is "measured latency collapse with sovereignty
 * intact": the gap between the operator expressing intent and a VALIDATED
 * engineering outcome must be measured to have collapsed to near-zero, while
 * the operator stays the irreducible source of engineering ends. This scorer
 * measures that latency from a sequence of intent->outcome events and reports
 * the near-zero verdict only when both percentiles are inside the near-zero
 * band AND sovereignty is intact AND the sample is trustworthy.
 *
 * Each event carries:
 *   - latency_ms:        the measured operator-intent -> validated-outcome
 *                        latency for that event (numeric; negatives clamp to 0)
 *   - outcome_validated: whether the engineering outcome was actually validated
 *                        (a missing validated outcome cannot be measured and
 *                        blocks the score)
 *   - sovereignty_intact / sovereignty_violation: whether the operator retained
 *                        sovereignty of ends for that event (a violation blocks)
 *   - evidence_ref:      an append-only reference proving the event
 *
 * Ordered gates mirror the slice row exactly: missing validated outcome blocks
 * first, then a sovereignty violation blocks, then a low sample size returns
 * the insufficient_evidence verdict.
 *
 * Pure: every returned field is COMPUTED from the method input through real
 * arithmetic and ordering. No I/O, DB, Eloquent, facade, provider call, clock,
 * filesystem or randomness. Identical input always yields identical output.
 */
final class L10IntentToValidatedOutcomeLatencyScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.intent_to_validated_outcome_latency.v1';

    /**
     * Minimum number of measurable (validated, sovereignty-intact) events before
     * the latency measurement is trusted at all. Below this the score returns
     * the insufficient_evidence verdict regardless of the raw latency.
     */
    private const MIN_SAMPLE_SIZE = 5;

    /**
     * The near-zero band, in milliseconds. The fusion claim ("latency collapsed
     * to near-zero") only holds when BOTH the p50 and the p95 latency fall at or
     * below this bound. This is the measured threshold the R4 criterion names.
     */
    private const NEAR_ZERO_THRESHOLD_MS = 1000;

    /** Verdict when every event is measurable, trusted and inside the band. */
    private const VERDICT_PASS = 'pass';

    /** Verdict when a gate (missing outcome or sovereignty violation) blocks. */
    private const VERDICT_BLOCK = 'block';

    /** Verdict when too few measurable events exist to measure latency honestly. */
    private const VERDICT_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    /**
     * @param  list<array<string, mixed>>|array<array-key, array<string, mixed>>  $events
     * @return array{
     *     schema_version: string,
     *     p50_latency_ms: int,
     *     p95_latency_ms: int,
     *     near_zero_threshold_met: bool,
     *     sovereignty_intact: bool,
     *     sample_size: int,
     *     insufficient_evidence: bool,
     *     verdict: string,
     *     evidence_refs: list<string>,
     *     blockers: list<string>
     * }
     */
    public function score(array $events): array
    {
        $rows = $this->normaliseEvents($events);

        $missingValidatedOutcome = false;
        $sovereigntyViolated = false;
        $latencies = [];

        foreach ($rows as $row) {
            if (! $this->outcomeValidated($row)) {
                $missingValidatedOutcome = true;

                continue;
            }

            if ($this->sovereigntyViolation($row)) {
                $sovereigntyViolated = true;

                continue;
            }

            $latencies[] = $this->latencyMs($row);
        }

        $blockers = [];

        // Ordered gates, exactly as the slice row enumerates them.
        if ($missingValidatedOutcome) {
            $blockers[] = 'missing_validated_outcome';
        }

        if ($sovereigntyViolated) {
            $blockers[] = 'sovereignty_violation';
        }

        $sampleSize = count($latencies);
        $insufficientEvidence = $blockers === [] && $sampleSize < self::MIN_SAMPLE_SIZE;

        $p50 = $this->percentile($latencies, 50);
        $p95 = $this->percentile($latencies, 95);

        $sovereigntyIntact = ! $sovereigntyViolated;

        // The fusion claim: near-zero latency holds only with a trusted, fully
        // measurable sample, both percentiles inside the band AND sovereignty
        // intact. Any blocker or insufficient evidence forecloses the claim.
        $nearZeroThresholdMet = $blockers === []
            && ! $insufficientEvidence
            && $sovereigntyIntact
            && $p50 <= self::NEAR_ZERO_THRESHOLD_MS
            && $p95 <= self::NEAR_ZERO_THRESHOLD_MS;

        if ($blockers !== []) {
            $verdict = self::VERDICT_BLOCK;
        } elseif ($insufficientEvidence) {
            $verdict = self::VERDICT_INSUFFICIENT_EVIDENCE;
        } else {
            $verdict = self::VERDICT_PASS;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'p50_latency_ms' => $p50,
            'p95_latency_ms' => $p95,
            'near_zero_threshold_met' => $nearZeroThresholdMet,
            'sovereignty_intact' => $sovereigntyIntact,
            'sample_size' => $sampleSize,
            'insufficient_evidence' => $insufficientEvidence,
            'verdict' => $verdict,
            'evidence_refs' => $this->evidenceRefs($rows),
            'blockers' => $blockers,
        ];
    }

    /**
     * Re-key the event sequence as a clean list of array rows; non-array entries
     * are dropped so int-key coercion never leaks into the measurement.
     *
     * @param  array<array-key, mixed>  $events
     * @return list<array<string, mixed>>
     */
    private function normaliseEvents(array $events): array
    {
        $rows = [];

        foreach ($events as $event) {
            if (is_array($event)) {
                $rows[] = $event;
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function outcomeValidated(array $row): bool
    {
        return ($row['outcome_validated'] ?? false) === true
            || ($row['validated_outcome'] ?? false) === true;
    }

    /**
     * A sovereignty violation is present when the event explicitly flags one or
     * when sovereignty is explicitly marked as not intact. Absence of any flag
     * is treated as sovereignty intact (the event simply carries no violation).
     *
     * @param  array<string, mixed>  $row
     */
    private function sovereigntyViolation(array $row): bool
    {
        if (($row['sovereignty_violation'] ?? false) === true) {
            return true;
        }

        return ($row['sovereignty_intact'] ?? true) === false;
    }

    /**
     * Measured latency for an event, in milliseconds, clamped to be non-negative
     * (a latency cannot be negative). Non-numeric latency is treated as zero.
     *
     * A numeric string (e.g. "1500", a shape a JSON-decoded event routinely
     * carries — the slice row only requires the value be "numeric") is coerced
     * through the SAME finite-magnitude path as a float, so a large measured
     * latency expressed as a string can never silently collapse to 0 and
     * masquerade as a near-zero outcome (a fail-open). This mirrors the numeric
     * coercion contract of the sibling L10 kernels
     * (L10GenerativeParadigmOutcomeValidator::intValue/floatValue).
     *
     * A non-finite (NAN/INF) or magnitude-overflowing float latency is NOT a
     * measurable near-zero collapse — it is the opposite. Casting it with (int)
     * would emit a runtime warning and wrap to a platform-dependent (often tiny
     * or negative) value, which would both break purity/determinism AND let an
     * infinite latency masquerade as near-zero (a fail-open). Such a magnitude is
     * therefore clamped to PHP_INT_MAX so it can never satisfy the near-zero band.
     *
     * @param  array<string, mixed>  $row
     */
    private function latencyMs(array $row): int
    {
        $raw = $row['latency_ms'] ?? 0;

        if (is_int($raw)) {
            return max($raw, 0);
        }

        // A numeric string carries the same measured magnitude as the equivalent
        // float; route it through the float clamp so "1500" measures 1500ms, not
        // 0ms. (float) of an out-of-double-range numeric string yields INF, which
        // the non-finite guard below clamps to PHP_INT_MAX — never a stray 0.
        if (is_string($raw) && is_numeric($raw)) {
            $raw = (float) $raw;
        }

        if (is_float($raw)) {
            // NAN cannot prove a collapse; INF is the antithesis of near-zero.
            // Both take the safe (largest) path, never the accidental 0.
            if (! is_finite($raw)) {
                return PHP_INT_MAX;
            }

            $rounded = round($raw);

            if ($rounded <= 0.0) {
                return 0;
            }

            // A float at or beyond 2^63 is not representable as an int: clamp the
            // magnitude to PHP_INT_MAX so the latency stays a deterministic, large
            // non-negative int instead of overflowing to a wrapped value.
            if ($rounded >= 9223372036854775808.0) {
                return PHP_INT_MAX;
            }

            return (int) $rounded;
        }

        return 0;
    }

    /**
     * Nearest-rank percentile of a list of non-negative integer latencies. The
     * list is sorted ascending; the rank is ceil(p/100 * n) clamped into range.
     * An empty list yields 0. The result is always one of the input values, so
     * it never exceeds the observed maximum.
     *
     * @param  list<int>  $latencies
     */
    private function percentile(array $latencies, int $percentile): int
    {
        if ($latencies === []) {
            return 0;
        }

        $sorted = $latencies;
        sort($sorted);

        $count = count($sorted);
        $rank = (int) ceil(($percentile / 100) * $count);

        if ($rank < 1) {
            $rank = 1;
        }

        if ($rank > $count) {
            $rank = $count;
        }

        return $sorted[$rank - 1];
    }

    /**
     * Append-only evidence references gathered across every event, de-duplicated
     * and re-keyed as a clean list<string> (no int-key coercion leaks through).
     * Each event may carry a scalar `evidence_ref` or an `evidence_refs` list.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function evidenceRefs(array $rows): array
    {
        $refs = [];

        foreach ($rows as $row) {
            foreach ($this->candidateRefs($row) as $candidate) {
                if (! is_string($candidate) && ! is_int($candidate)) {
                    continue;
                }

                $value = trim((string) $candidate);

                if ($value === '' || in_array($value, $refs, true)) {
                    continue;
                }

                $refs[] = $value;
            }
        }

        return array_values($refs);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function candidateRefs(array $row): array
    {
        $candidates = [];

        if (array_key_exists('evidence_ref', $row)) {
            $candidates[] = $row['evidence_ref'];
        }

        $list = $row['evidence_refs'] ?? null;
        if (is_array($list)) {
            foreach ($list as $entry) {
                $candidates[] = $entry;
            }
        }

        return $candidates;
    }
}
