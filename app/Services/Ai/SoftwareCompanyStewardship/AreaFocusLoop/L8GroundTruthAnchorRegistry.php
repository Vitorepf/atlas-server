<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8 P5 (Goodhart-proof foundation): immutable, independent ground-truth anchors
 * for the metrics the area-focus loop optimizes.
 *
 * Each optimized metric is bound to an INDEPENDENT source that the loop cannot
 * itself write. The anchor table is immutable and cannot be self-reported, which
 * is the precondition for safe self-evolution: the system can never move the
 * yardstick it is graded against.
 */
final class L8GroundTruthAnchorRegistry
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.ground_truth_anchor_registry.v1';

    /**
     * Canonical optimized metric -> independent ground-truth source.
     * Order is stable and is the canonical declaration order.
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        'useful_cycle_rate' => 'evidence_ledger',
        'trust_ledger_score' => 'trust_ledger_canonical',
        'dm_dt' => 'compounding_audit',
        'retained_evolution_rate' => 'measured_or_reverted_outcome_ledger',
        'provider_honesty_rate' => 'aemor_honesty_guard',
    ];

    /**
     * The immutable anchor registry.
     *
     * @return array{
     *     schema_version: string,
     *     optimized_metrics: list<array{
     *         optimized_metric: string,
     *         independent_source: string,
     *         immutable: bool,
     *         cannot_be_self_reported: bool
     *     }>
     * }
     */
    public function anchors(): array
    {
        $optimizedMetrics = [];

        foreach (self::SOURCES as $metric => $source) {
            $optimizedMetrics[] = [
                'optimized_metric' => $metric,
                'independent_source' => $source,
                'immutable' => true,
                'cannot_be_self_reported' => true,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'optimized_metrics' => $optimizedMetrics,
        ];
    }

    /**
     * Canonical metric names, in declaration order.
     *
     * @return list<string>
     */
    public function metricNames(): array
    {
        return array_values(array_keys(self::SOURCES));
    }

    /**
     * Resolve the immutable independent source for a single optimized metric.
     * Returns null for any metric that is not a registered ground-truth anchor.
     */
    public function sourceFor(string $metric): ?string
    {
        return self::SOURCES[$metric] ?? null;
    }

    /**
     * Validate a set of reported anchor metric names against the canonical
     * registry. Fail-closed: a duplicate anchor or a missing anchor blocks
     * admission. Unknown anchors (not in the canonical set) also block, since
     * an anchor outside the immutable registry could be self-introduced.
     *
     * @param list<string> $reportedMetrics
     * @return array{
     *     schema_version: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     duplicate_anchors: list<string>,
     *     missing_anchors: list<string>,
     *     unknown_anchors: list<string>
     * }
     */
    public function validate(array $reportedMetrics): array
    {
        $seen = [];
        $duplicates = [];
        $unknown = [];

        foreach ($reportedMetrics as $metric) {
            $name = $this->coerceMetricName($metric);

            if (! array_key_exists($name, self::SOURCES)) {
                if (! in_array($name, $unknown, true)) {
                    $unknown[] = $name;
                }

                continue;
            }

            $count = ($seen[$name] ?? 0) + 1;
            $seen[$name] = $count;

            if ($count === 2) {
                $duplicates[] = $name;
            }
        }

        $missing = [];
        foreach (array_keys(self::SOURCES) as $canonical) {
            if (! array_key_exists($canonical, $seen)) {
                $missing[] = $canonical;
            }
        }

        $blockers = [];
        if ($duplicates !== []) {
            $blockers[] = 'duplicate_anchor';
        }
        if ($missing !== []) {
            $blockers[] = 'missing_anchor';
        }
        if ($unknown !== []) {
            $blockers[] = 'unknown_anchor';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'admitted' => $blockers === [],
            'blockers' => $blockers,
            'duplicate_anchors' => $duplicates,
            'missing_anchors' => $missing,
            'unknown_anchors' => $unknown,
        ];
    }

    /**
     * Coerce a single reported anchor entry to a canonical string name.
     *
     * Strings pass through; integers are stringified (a numeric anchor name is
     * still matched against the canonical registry and otherwise blocks as
     * unknown). Any non-string, non-int value (array, object, bool, null) is a
     * malformed anchor entry: it can never be a canonical metric name, so it is
     * mapped to a stable non-empty sentinel that fails closed as an unknown
     * anchor rather than crashing the gate (object -> TypeError) or leaking a
     * warning and a meaningless "Array" token. A malformed anchor must never be
     * able to slip past — or silently break — the Goodhart-proof registry.
     */
    private function coerceMetricName(mixed $metric): string
    {
        if (is_string($metric)) {
            return $metric;
        }

        if (is_int($metric)) {
            return (string) $metric;
        }

        return '<non_string_anchor>';
    }
}
