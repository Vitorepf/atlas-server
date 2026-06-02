<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P5 self-deception adversarial probe.
 *
 * Before the loop is allowed to accept a self-reported gain, this probe tries to
 * REFUTE that gain. Given the metrics the loop optimizes and the independent
 * ground-truth anchors (the S102 anchor registry shape), it deterministically
 * fabricates the adversarial Goodhart scenario for each optimized metric: the
 * reported metric is pushed strictly UP while its independent anchor is held FLAT
 * (`metric_up_anchor_flat`). Each fabricated case carries an S103-compatible
 * `divergence_payload` — the exact `{metrics, anchors}` shape the
 * MetricRealityDivergenceDetector consumes — so the simulated gaming case is
 * provably detected downstream as `divergence_detected = true`.
 *
 * The probe is pure and deterministic: identical inputs always yield identical
 * output, no clock, no randomness, no I/O. Malformed inputs do not throw; they
 * collapse to a single fail-closed case so the substrate stays safe by default.
 */
final class L8SelfDeceptionAdversarialProbe
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.self_deception_probe.v1';

    /**
     * Canonical blocker the divergence detector (S103) is expected to raise for
     * every fabricated gaming case.
     */
    private const DIVERGENCE_BLOCKER = 'metric_reality_divergence';

    /**
     * Blocker emitted when the probe cannot construct any real adversarial case
     * because the inputs are malformed.
     */
    private const FAIL_CLOSED_BLOCKER = 'malformed_probe_inputs';

    /**
     * Movement label the fabricated gaming case asserts, mirroring the S103
     * detector vocabulary byte-for-byte.
     */
    private const GAMING_PATTERN = 'metric_up_anchor_flat';

    /**
     * Independent ground-truth sources mirrored from the S102
     * L8GroundTruthAnchorRegistry. A fabricated anchor MUST carry a usable
     * independent_source or the S103 detector treats it as unanchored
     * (unknown_blocked) instead of divergence. Metrics outside the canonical
     * registry fall back to a generic-but-non-empty independent source so the
     * payload still reads as a real, anchored Goodhart case downstream.
     *
     * @var array<string,string>
     */
    private const INDEPENDENT_SOURCES = [
        'useful_cycle_rate' => 'evidence_ledger',
        'trust_ledger_score' => 'trust_ledger_canonical',
        'dm_dt' => 'compounding_audit',
        'retained_evolution_rate' => 'measured_or_reverted_outcome_ledger',
        'provider_honesty_rate' => 'aemor_honesty_guard',
    ];

    /**
     * Independent source used for metrics not in the canonical S102 registry.
     */
    private const FALLBACK_INDEPENDENT_SOURCE = 'independent_reality_anchor';

    /**
     * Generate the deterministic adversarial probe over the reported metrics and
     * their independent ground-truth anchors.
     *
     * @param  array<string,mixed>  $metrics  reported (self-declared) metric values
     * @param  array<string,mixed>  $anchors  independent anchor values per metric
     * @return array{
     *     schema_version: string,
     *     fail_closed: bool,
     *     gaming_detected: bool,
     *     case_count: int,
     *     adversarial_case_count: int,
     *     adversarial_cases: list<array{
     *         case_id: string,
     *         metric: string,
     *         pattern: string,
     *         reported_value: float,
     *         gamed_value: float,
     *         anchor_value: float,
     *         metric_movement: float,
     *         anchor_movement: float,
     *         divergence_score: float,
     *         expected_blocker: string,
     *         should_block: bool,
     *         fail_closed: bool,
     *         divergence_payload: array{
     *             metrics: array<string,array{before: float, after: float}>,
     *             anchors: array<string,array{before: float, after: float, independent_source: string}>
     *         }
     *     }>,
     *     expected_blockers: list<string>
     * }
     */
    public function probe(array $metrics, array $anchors): array
    {
        $reported = $this->numericMap($metrics);
        $anchored = $this->numericMap($anchors);

        $sharedMetrics = $this->sharedSortedKeys($reported, $anchored);

        if ($sharedMetrics === []) {
            return $this->failClosedResult();
        }

        $cases = [];
        $blockers = [];

        foreach ($sharedMetrics as $metric) {
            $case = $this->buildGamingCase($metric, $reported[$metric], $anchored[$metric]);
            $cases[] = $case;
            $blockers[] = $case['expected_blocker'];
        }

        $gamingDetected = false;
        foreach ($cases as $case) {
            if ($case['should_block'] === true) {
                $gamingDetected = true;

                break;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fail_closed' => false,
            'gaming_detected' => $gamingDetected,
            'case_count' => count($cases),
            'adversarial_case_count' => count($cases),
            'adversarial_cases' => $cases,
            'expected_blockers' => $this->uniqueOrdered($blockers),
        ];
    }

    /**
     * Build one adversarial gaming case for a single metric: push the reported
     * value strictly up, hold the anchor flat, and emit an S103-compatible
     * payload that the divergence detector reads as `divergence_detected = true`.
     *
     * The payload mirrors the EXACT reading shape the S103
     * L8MetricRealityDivergenceDetector consumes: each metric is a
     * `{before, after}` reading and each anchor is a
     * `{before, after, independent_source}` reading. The reported metric moves
     * strictly up (before -> after by a real step that clears the detector's
     * movement epsilon) while the independent anchor stays flat (before == after),
     * which is precisely the `metric_up_anchor_flat` Goodhart signature the
     * detector classifies as divergence.
     *
     * @return array{
     *     case_id: string,
     *     metric: string,
     *     pattern: string,
     *     reported_value: float,
     *     gamed_value: float,
     *     anchor_value: float,
     *     metric_movement: float,
     *     anchor_movement: float,
     *     divergence_score: float,
     *     expected_blocker: string,
     *     should_block: bool,
     *     fail_closed: bool,
     *     divergence_payload: array{
     *         metrics: array<string,array{before: float, after: float}>,
     *         anchors: array<string,array{before: float, after: float, independent_source: string}>
     *     }
     * }
     */
    private function buildGamingCase(string $metric, float $reported, float $anchor): array
    {
        // Deterministic upward gaming step: at least +0.10 absolute, and at least
        // +10% relative to the reported magnitude, so the simulated reported gain
        // is unambiguous even for small reported values.
        $step = max(0.1, abs($reported) * 0.1);
        $gamed = $reported + $step;

        $metricMovement = $gamed - $reported;   // strictly positive by construction
        $anchorMovement = 0.0;                   // anchor held flat (reality did not move)

        return [
            'case_id' => 'gaming_'.$metric.'_'.self::GAMING_PATTERN,
            'metric' => $metric,
            'pattern' => self::GAMING_PATTERN,
            'reported_value' => $reported,
            'gamed_value' => $gamed,
            'anchor_value' => $anchor,
            'metric_movement' => $metricMovement,
            'anchor_movement' => $anchorMovement,
            'divergence_score' => $this->divergenceScore($metricMovement, $anchorMovement),
            'expected_blocker' => self::DIVERGENCE_BLOCKER,
            'should_block' => true,
            'fail_closed' => false,
            'divergence_payload' => [
                // S103 detect() input shape: the reported metric reading moves up
                // (before -> after); the independent anchor reading stays flat
                // (before == after) and carries a usable independent_source so the
                // detector treats it as anchored and flags metric_up_anchor_flat.
                'metrics' => [
                    $metric => [
                        'before' => $reported,
                        'after' => $gamed,
                    ],
                ],
                'anchors' => [
                    $metric => [
                        'before' => $anchor,
                        'after' => $anchor,
                        'independent_source' => $this->independentSourceFor($metric),
                    ],
                ],
            ],
        ];
    }

    /**
     * Resolve the immutable independent source for a metric, mirroring the S102
     * registry, with a generic-but-non-empty fallback for metrics outside the
     * canonical set so every fabricated anchor remains usable to the detector.
     */
    private function independentSourceFor(string $metric): string
    {
        return self::INDEPENDENT_SOURCES[$metric] ?? self::FALLBACK_INDEPENDENT_SOURCE;
    }

    /**
     * Single fail-closed case for malformed input: no real adversarial scenario
     * can be constructed, so the probe blocks by default.
     *
     * @return array{
     *     schema_version: string,
     *     fail_closed: bool,
     *     gaming_detected: bool,
     *     case_count: int,
     *     adversarial_case_count: int,
     *     adversarial_cases: list<array{
     *         case_id: string,
     *         metric: string,
     *         pattern: string,
     *         reported_value: float,
     *         gamed_value: float,
     *         anchor_value: float,
     *         metric_movement: float,
     *         anchor_movement: float,
     *         divergence_score: float,
     *         expected_blocker: string,
     *         should_block: bool,
     *         fail_closed: bool,
     *         divergence_payload: array{
     *             metrics: array<string,array{before: float, after: float}>,
     *             anchors: array<string,array{before: float, after: float, independent_source: string}>
     *         }
     *     }>,
     *     expected_blockers: list<string>
     * }
     */
    private function failClosedResult(): array
    {
        $case = [
            'case_id' => 'fail_closed',
            'metric' => '',
            'pattern' => 'fail_closed',
            'reported_value' => 0.0,
            'gamed_value' => 0.0,
            'anchor_value' => 0.0,
            'metric_movement' => 0.0,
            'anchor_movement' => 0.0,
            'divergence_score' => 1.0,
            'expected_blocker' => self::FAIL_CLOSED_BLOCKER,
            'should_block' => true,
            'fail_closed' => true,
            'divergence_payload' => [
                'metrics' => [],
                'anchors' => [],
            ],
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fail_closed' => true,
            'gaming_detected' => false,
            'case_count' => 1,
            'adversarial_case_count' => 1,
            'adversarial_cases' => [$case],
            'expected_blockers' => [self::FAIL_CLOSED_BLOCKER],
        ];
    }

    /**
     * Bounded 0..1 divergence strength: 1.0 when the reported metric moves up while
     * the anchor stays flat (pure Goodhart), shrinking toward 0 as the anchor
     * tracks the metric. Never exceeds 1.0 for any input.
     */
    private function divergenceScore(float $metricMovement, float $anchorMovement): float
    {
        $metricMagnitude = abs($metricMovement);
        $anchorMagnitude = abs($anchorMovement);

        if ($metricMagnitude <= 0.0) {
            return 0.0;
        }

        // A non-finite metric movement (an overflowed reported value pushed up
        // against an anchor that does NOT also overflow) is the purest Goodhart
        // signature: the reported number ran away from a reality that did not,
        // so the divergence is maximal. Returning 1.0 here keeps the declared
        // 0..1 bound for every finite input instead of leaking INF/INF = NAN.
        if (! is_finite($metricMagnitude)) {
            return is_finite($anchorMagnitude) ? 1.0 : 0.0;
        }

        $tracked = min($anchorMagnitude, $metricMagnitude);
        $untracked = $metricMagnitude - $tracked;

        $score = $untracked / $metricMagnitude;

        // Guard the bound against any residual NAN/precision artifact: NAN fails
        // both comparisons below, so it is collapsed to the safe floor first.
        if (is_nan($score) || $score < 0.0) {
            return 0.0;
        }

        if ($score > 1.0) {
            return 1.0;
        }

        return $score;
    }

    /**
     * Keys present in both maps, sorted ascending for deterministic ordering.
     *
     * Each key is re-cast to string before collection: a numeric-string metric key
     * is silently coerced back to an int key by PHP when it indexes $first, so
     * iterating yields an int that would otherwise break the string metric contract
     * downstream. SORT_STRING is mandatory — the default SORT_REGULAR would compare
     * any coerced-numeric keys numerically and order a mixed numeric/alpha key set
     * non-deterministically, violating the list<string> contract.
     *
     * @param  array<string,float>  $first
     * @param  array<string,float>  $second
     * @return list<string>
     */
    private function sharedSortedKeys(array $first, array $second): array
    {
        $shared = [];

        foreach ($first as $key => $value) {
            if (array_key_exists($key, $second)) {
                $shared[] = (string) $key;
            }
        }

        sort($shared, SORT_STRING);

        return array_values($shared);
    }

    /**
     * Coerce a payload to a string-keyed map of finite floats, dropping any entry
     * whose key is blank or whose value is not numeric. Guards the list<string>-key
     * contract against PHP integer-key coercion: a numeric-string metric key (e.g.
     * "10") that PHP silently coerces to the int key 10 is normalised back to its
     * canonical string form rather than being dropped, mirroring how the S103
     * L8MetricRealityDivergenceDetector casts every metric key to string. Dropping
     * such keys would falsely collapse a real, optimizable metric to fail-closed.
     *
     * @param  array<array-key,mixed>  $payload
     * @return array<string,float>
     */
    private function numericMap(array $payload): array
    {
        $map = [];

        foreach ($payload as $key => $value) {
            // PHP array keys are only ever int or string; an int key here is a
            // numeric-string metric key PHP coerced. Normalise to its canonical
            // string form so it stays a real optimizable metric.
            $stringKey = (string) $key;

            if (trim($stringKey) === '') {
                continue;
            }

            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $float = (float) $value;

            if (! is_finite($float)) {
                continue;
            }

            $map[$stringKey] = $float;
        }

        return $map;
    }

    /**
     * Distinct values preserving first-seen order.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueOrdered(array $values): array
    {
        $seen = [];
        $ordered = [];

        foreach ($values as $value) {
            if (array_key_exists($value, $seen)) {
                continue;
            }

            $seen[$value] = true;
            $ordered[] = $value;
        }

        return $ordered;
    }
}
