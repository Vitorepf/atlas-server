<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S151 — L10RecursiveDivergenceGamingDetector (L10 Generative Engineering Guard, R3).
 *
 * The R3 pillar (bounded recursive self-improvement) is permitted only up to the
 * proven convergence depth, and inherits P5 (immunity to self-deception) and Q2
 * (proven invariants). Its load-bearing safety rule is a HARD STOP and automatic
 * demote at the FIRST sign of divergence or metric gaming
 * (atlas-aaeos-l10-generative-engineering-map:152, atlas-aaeos-l8-transcendence-map:148).
 *
 * This detector is the pure, read-only sensor for that rule. It reads recursive
 * self-improvement evidence — the velocity of the optimized M metric (dm_dt),
 * the independent quality reading, and the sacred invariant records — and reports
 * whether the recursion has diverged, is gaming its own metric, or has drifted an
 * invariant. It is FAIL-CLOSED: empty evidence is never clean (you cannot prove
 * honesty from the absence of a reality check), and any single stop signal forces
 * hard_stop_required. It has zero dependencies and performs no IO, clock,
 * randomness or provider work; every field is derived from the method input.
 *
 * Doctrine: recursive improvement stops on divergence or gaming. The detector
 * never mutates runtime — it only raises the stop signal that R3 certification
 * (S152) and the depth gate (S150) act on.
 */
final class L10RecursiveDivergenceGamingDetector
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.recursive_divergence_gaming.v1';

    /**
     * Minimum signed magnitude for a reading to count as a real move rather than
     * measurement noise. Mirrors L8MetricRealityDivergenceDetector::MOVEMENT_EPSILON
     * byte-for-byte so the R3 sensor and the P5 sensor share one noise floor.
     */
    private const MOVEMENT_EPSILON = 0.01;

    /**
     * Stop-signal names, emitted in this fixed priority order whenever raised.
     */
    private const SIGNAL_GAMING = 'metric_gaming';
    private const SIGNAL_DIVERGENCE = 'metric_reality_divergence';
    private const SIGNAL_INVARIANT_DRIFT = 'invariant_drift';

    /**
     * @param  array<string,mixed>  $evidence  recursive self-improvement evidence:
     *   {
     *     dm_dt:        float|{before,after},  // velocity of the optimized M metric
     *     quality:      float|{before,after},  // independent quality / reality reading
     *     invariants:   list<array{...}>,      // sacred invariant records (drift flagged)
     *     evidence_refs:list<string>           // append-only references to corroborate
     *   }
     * @return array{
     *     schema_version: string,
     *     divergence_detected: bool,
     *     gaming_detected: bool,
     *     hard_stop_required: bool,
     *     signals: list<string>,
     *     evidence_refs: list<string>,
     *     blockers: list<string>
     * }
     */
    public function detect(array $evidence): array
    {
        $blockers = [];

        if ($this->isEmptyEvidence($evidence)) {
            $blockers[] = 'evidence_empty';
        }

        $dmDt = $this->signedReading($evidence, 'dm_dt');
        $qualityDelta = $this->signedReading($evidence, 'quality');

        // Metric gaming (Goodhart): the optimized metric accelerates upward while
        // the independent quality reading really degrades — dm_dt up, quality down.
        $gamingDetected = $this->isRealUp($dmDt) && $this->isRealDown($qualityDelta);

        // Divergence: the metric makes any real move that the independent quality
        // reading fails to corroborate — quality flat against a moving metric, or
        // quality moving against the metric. Gaming is the canonical sub-case, so a
        // gaming signal is always also a divergence of metric from reality.
        $divergenceDetected = $gamingDetected
            || ($this->isRealMove($dmDt) && $this->qualityFailsToCorroborate($dmDt, $qualityDelta));

        $invariantDrift = $this->hasInvariantDrift($evidence);

        // Fail-closed hard stop: ANY single stop signal halts recursion. This is the
        // R3 rule "recursive improvement stops on divergence or gaming"; invariant
        // drift is itself a hard stop per the row's explicit clause.
        $hardStopRequired = $invariantDrift || $gamingDetected || $divergenceDetected;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'divergence_detected' => $divergenceDetected,
            'gaming_detected' => $gamingDetected,
            'hard_stop_required' => $hardStopRequired,
            'signals' => $this->signals($gamingDetected, $divergenceDetected, $invariantDrift),
            'evidence_refs' => $this->evidenceRefs($evidence),
            'blockers' => $blockers,
        ];
    }

    /**
     * Evidence is empty when it carries no usable recursion reading at all: no
     * dm_dt, no quality, and no invariant records. Such input cannot be declared
     * clean — absence of a reality check is not proof of honesty.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function isEmptyEvidence(array $evidence): bool
    {
        if ($evidence === []) {
            return true;
        }

        return ! $this->hasReading($evidence, 'dm_dt')
            && ! $this->hasReading($evidence, 'quality')
            && $this->invariantRecords($evidence) === [];
    }

    /**
     * Ordered, de-duplicated stop-signal names as a strict list<string>.
     *
     * @return list<string>
     */
    private function signals(bool $gaming, bool $divergence, bool $invariantDrift): array
    {
        $signals = [];

        if ($gaming) {
            $signals[] = self::SIGNAL_GAMING;
        }

        if ($divergence) {
            $signals[] = self::SIGNAL_DIVERGENCE;
        }

        if ($invariantDrift) {
            $signals[] = self::SIGNAL_INVARIANT_DRIFT;
        }

        return array_values(array_unique($signals));
    }

    /**
     * Quality fails to corroborate a moving metric when it stays flat (no real
     * reality move to back the claim) or moves in the opposite direction.
     */
    private function qualityFailsToCorroborate(float $dmDt, float $qualityDelta): bool
    {
        if (! $this->isRealMove($qualityDelta)) {
            return true;
        }

        return $this->sign($dmDt) !== $this->sign($qualityDelta);
    }

    /**
     * An invariant record drifts when it is explicitly flagged as drifted,
     * breached or failing. Mirrors the breach predicate used across the loop's
     * invariant monitors so one drift signal forces the R3 hard stop.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function hasInvariantDrift(array $evidence): bool
    {
        foreach ($this->invariantRecords($evidence) as $invariant) {
            if ($this->isDrifted($invariant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $invariant
     */
    private function isDrifted(array $invariant): bool
    {
        if (array_key_exists('drift', $invariant)) {
            return $invariant['drift'] === true;
        }

        if (array_key_exists('drifted', $invariant)) {
            return $invariant['drifted'] === true;
        }

        if (array_key_exists('breached', $invariant)) {
            return $invariant['breached'] === true;
        }

        if (array_key_exists('stable', $invariant)) {
            return $invariant['stable'] === false;
        }

        if (array_key_exists('status', $invariant)) {
            return in_array(
                strtolower(trim((string) $invariant['status'])),
                ['drift', 'drifted', 'breached', 'fail', 'failed', 'violation', 'violated'],
                true,
            );
        }

        return false;
    }

    /**
     * Extract invariant records as a list of arrays, ignoring non-array entries.
     *
     * @param  array<string,mixed>  $evidence
     * @return list<array<string,mixed>>
     */
    private function invariantRecords(array $evidence): array
    {
        $raw = $evidence['invariants'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $records = [];

        foreach ($raw as $invariant) {
            if (is_array($invariant)) {
                $records[] = $invariant;
            }
        }

        return $records;
    }

    /**
     * Carry the append-only evidence references through as a strict list<string>,
     * de-duplicated and re-indexed so the contract holds even for an associative
     * input map. Non-string scalars are coerced; empty entries are dropped.
     *
     * @param  array<string,mixed>  $evidence
     * @return list<string>
     */
    private function evidenceRefs(array $evidence): array
    {
        $raw = $evidence['evidence_refs'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $refs = [];

        foreach ($raw as $ref) {
            if (! is_string($ref) && ! is_int($ref)) {
                continue;
            }

            $value = trim((string) $ref);

            if ($value === '' || in_array($value, $refs, true)) {
                continue;
            }

            $refs[] = $value;
        }

        return array_values($refs);
    }

    /**
     * A reading is present when its key holds either a numeric scalar or an array
     * carrying at least one numeric before/after edge.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function hasReading(array $evidence, string $key): bool
    {
        if (! array_key_exists($key, $evidence)) {
            return false;
        }

        $value = $evidence[$key];

        if (is_numeric($value)) {
            return true;
        }

        if (is_array($value)) {
            return $this->hasNumeric($value, 'after') || $this->hasNumeric($value, 'before');
        }

        return false;
    }

    /**
     * Signed magnitude of a reading. A bare numeric scalar is taken as the move
     * itself (already a velocity / delta); a {before, after} array becomes
     * after - before. Anything else is flat (0.0).
     *
     * @param  array<string,mixed>  $evidence
     */
    private function signedReading(array $evidence, string $key): float
    {
        $value = $evidence[$key] ?? null;

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return $this->floatValue($value, 'after') - $this->floatValue($value, 'before');
        }

        return 0.0;
    }

    private function isRealMove(float $delta): bool
    {
        return abs($delta) > self::MOVEMENT_EPSILON;
    }

    private function isRealUp(float $delta): bool
    {
        return $delta > self::MOVEMENT_EPSILON;
    }

    private function isRealDown(float $delta): bool
    {
        return $delta < -self::MOVEMENT_EPSILON;
    }

    private function sign(float $delta): int
    {
        if ($delta > 0.0) {
            return 1;
        }

        if ($delta < 0.0) {
            return -1;
        }

        return 0;
    }

    /**
     * @param  array<array-key,mixed>  $reading
     */
    private function hasNumeric(array $reading, string $key): bool
    {
        return array_key_exists($key, $reading) && is_numeric($reading[$key]);
    }

    /**
     * @param  array<array-key,mixed>  $reading
     */
    private function floatValue(array $reading, string $key): float
    {
        $value = $reading[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
