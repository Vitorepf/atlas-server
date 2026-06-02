<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P5 anti-Goodhart divergence detector.
 *
 * Compares the movement of an optimized metric against the independent
 * ground-truth anchor declared for it (the anchor shape mirrors the
 * L8GroundTruthAnchorRegistry from S102: each anchor carries an
 * independent_source and is reality-measured, never self-reported).
 *
 * The detector is FAIL-CLOSED: when a metric has no usable independent
 * anchor it can never be declared clean. It returns unknown_blocked
 * instead of pass, because absence of a reality check is not proof of
 * honesty. A metric that climbs while its independent anchor stays flat
 * (or contradicts it) is flagged as divergence — the textbook Goodhart
 * signature of a self-reported number drifting away from reality.
 */
final class L8MetricRealityDivergenceDetector
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.metric_reality_divergence.v1';

    /**
     * Minimum signed delta for a movement to count as a real move rather
     * than measurement noise. Anything inside [-EPSILON, +EPSILON] is flat.
     */
    private const MOVEMENT_EPSILON = 0.01;

    private const STATUS_ALIGNED = 'aligned';
    private const STATUS_DIVERGENCE = 'divergence';
    private const STATUS_UNANCHORED = 'unanchored';

    private const VERDICT_PASS = 'pass';
    private const VERDICT_UNKNOWN_BLOCKED = 'unknown_blocked';
    private const VERDICT_DIVERGENCE_BLOCKED = 'divergence_blocked';

    /**
     * @param array<array-key, mixed> $metrics map of metric_key => {before, after}
     * @param array<array-key, mixed> $anchors map of metric_key => {before, after, independent_source}
     *
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     divergence_detected: bool,
     *     evaluated_metric_count: int,
     *     divergent_metrics: list<string>,
     *     unanchored_metrics: list<string>,
     *     aligned_metrics: list<string>,
     *     per_metric: array<string, array{
     *         status: string,
     *         metric_delta: float,
     *         anchor_delta: float,
     *         anchored: bool
     *     }>
     * }
     */
    public function detect(array $metrics, array $anchors): array
    {
        $perMetric = [];
        $divergent = [];
        $unanchored = [];
        $aligned = [];

        foreach ($metrics as $rawKey => $metricReading) {
            $metricKey = (string) $rawKey;

            $metricDelta = $this->signedDelta($metricReading);
            $anchorReading = $anchors[$rawKey] ?? null;
            $anchored = $this->isUsableAnchor($anchorReading);
            $anchorDelta = $anchored ? $this->signedDelta($anchorReading) : 0.0;

            $status = $this->classify($anchored, $metricDelta, $anchorDelta);

            $perMetric[$metricKey] = [
                'status' => $status,
                // Sanitise the emitted deltas to finite floats. classify() has
                // already used the raw (possibly non-finite) deltas for the
                // fail-closed verdict; the output struct must stay a finite,
                // JSON-encodable float (a leaked NAN/INF would make the whole
                // evidence/receipt payload un-encodable). Finite deltas pass
                // through byte-identical.
                'metric_delta' => $this->finiteDelta($metricDelta),
                'anchor_delta' => $this->finiteDelta($anchorDelta),
                'anchored' => $anchored,
            ];

            if ($status === self::STATUS_DIVERGENCE) {
                $divergent[] = $metricKey;
            } elseif ($status === self::STATUS_UNANCHORED) {
                $unanchored[] = $metricKey;
            } else {
                $aligned[] = $metricKey;
            }
        }

        $verdict = $this->resolveVerdict($divergent, $unanchored, count($metrics));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'divergence_detected' => $verdict === self::VERDICT_DIVERGENCE_BLOCKED,
            'evaluated_metric_count' => count($metrics),
            'divergent_metrics' => $divergent,
            'unanchored_metrics' => $unanchored,
            'aligned_metrics' => $aligned,
            'per_metric' => $perMetric,
        ];
    }

    /**
     * Fail-closed verdict resolution, in strict priority order:
     *  1. any divergent metric          -> divergence_blocked (real gaming signal wins)
     *  2. no metrics / any unanchored    -> unknown_blocked    (cannot confirm reality)
     *  3. every metric aligned to anchor -> pass
     *
     * @param list<string> $divergent
     * @param list<string> $unanchored
     */
    private function resolveVerdict(array $divergent, array $unanchored, int $metricCount): string
    {
        if ($divergent !== []) {
            return self::VERDICT_DIVERGENCE_BLOCKED;
        }

        if ($metricCount === 0 || $unanchored !== []) {
            return self::VERDICT_UNKNOWN_BLOCKED;
        }

        return self::VERDICT_PASS;
    }

    /**
     * A metric diverges when it makes a real move but its independent anchor
     * fails to corroborate that move — either staying flat or moving against
     * it. Symmetric: a metric that drops while the anchor holds is just as
     * much a reality mismatch as the canonical metric-up / anchor-flat case.
     */
    private function classify(bool $anchored, float $metricDelta, float $anchorDelta): string
    {
        if (! $anchored) {
            return self::STATUS_UNANCHORED;
        }

        if (! is_finite($metricDelta)) {
            // A non-finite metric delta (NAN from an upstream 0/0 rate, or an
            // overflowed INF reading) is a poisoned self-reported number that no
            // independent anchor can corroborate. Fail-closed: it can never be
            // declared clean, so it is divergence — never flattened to aligned.
            // (INF already reaches the divergence branch below via isRealMove;
            // NAN does not, because abs(NAN) > EPSILON is false, so it would
            // otherwise slip into the "metric flat -> aligned" pass path.)
            return self::STATUS_DIVERGENCE;
        }

        if (! $this->isRealMove($metricDelta)) {
            // Metric flat: aligned regardless of anchor noise; no claim to refute.
            return self::STATUS_ALIGNED;
        }

        if (! $this->isRealMove($anchorDelta)) {
            // Metric moved, independent reality did not. Goodhart divergence.
            return self::STATUS_DIVERGENCE;
        }

        if ($this->sign($metricDelta) !== $this->sign($anchorDelta)) {
            // Both moved but in opposing directions: reality contradicts the metric.
            return self::STATUS_DIVERGENCE;
        }

        return self::STATUS_ALIGNED;
    }

    private function isRealMove(float $delta): bool
    {
        return abs($delta) > self::MOVEMENT_EPSILON;
    }

    /**
     * Collapse a non-finite delta (NAN/INF from a poisoned or overflowed reading)
     * to a finite, JSON-encodable float for the output struct. NAN — which carries
     * no direction — becomes 0.0; a signed INF saturates to the float bound,
     * preserving its sign and magnitude ordering. Finite deltas are returned
     * unchanged, so every declared metric_delta/anchor_delta stays a real float.
     */
    private function finiteDelta(float $delta): float
    {
        if (is_finite($delta)) {
            return $delta;
        }

        if (is_nan($delta)) {
            return 0.0;
        }

        return $delta > 0.0 ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX;
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
     * An anchor is usable only if it is an array that exposes an independent
     * source and at least one numeric reading. This mirrors the S102 anchor
     * contract: a self-reportable or sourceless anchor is no anchor at all,
     * so the metric stays unverifiable (fail-closed).
     *
     * @param mixed $anchorReading
     */
    private function isUsableAnchor(mixed $anchorReading): bool
    {
        if (! is_array($anchorReading)) {
            return false;
        }

        $source = $anchorReading['independent_source'] ?? null;
        if (! is_string($source) || trim($source) === '') {
            return false;
        }

        return $this->hasNumeric($anchorReading, 'before')
            || $this->hasNumeric($anchorReading, 'after');
    }

    /**
     * @param mixed $reading
     */
    private function signedDelta(mixed $reading): float
    {
        if (! is_array($reading)) {
            return 0.0;
        }

        return $this->floatValue($reading, 'after') - $this->floatValue($reading, 'before');
    }

    /**
     * @param array<array-key, mixed> $reading
     */
    private function hasNumeric(array $reading, string $key): bool
    {
        return array_key_exists($key, $reading) && is_numeric($reading[$key]);
    }

    /**
     * @param array<array-key, mixed> $reading
     */
    private function floatValue(array $reading, string $key): float
    {
        $value = $reading[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
