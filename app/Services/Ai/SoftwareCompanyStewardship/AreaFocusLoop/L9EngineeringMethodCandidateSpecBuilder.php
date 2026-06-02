<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S139 — L9 Sovereign Engineering / Q3 (engineering discipline evolution).
 *
 * Specifies system-discovered engineering-method candidates from measured
 * outcomes, staying strictly inside engineering scope. Doctrine (L9-Q3):
 * "Q3 improves engineering discipline, not domains outside AAEOS." A candidate
 * is only emitted when an outcome carries a real measurement; outcomes whose
 * scope resolves to a non-engineering domain are rejected, and two outcomes
 * that resolve to the same engineering method collapse to a single candidate
 * (the later one is recorded as a rejected duplicate).
 *
 * Pure: every returned field is computed from the method input via real rules
 * (deterministic slugging, measured before/after delta arithmetic, set
 * membership against the engineering-stage and non-engineering-domain lexicons,
 * deterministic ordering). No I/O, no DB, no facades, no clock, no randomness,
 * no write authority — this only specifies candidates, it never adopts a method.
 */
final class L9EngineeringMethodCandidateSpecBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.engineering_method_candidate_spec.v1';

    /**
     * The scope every emitted candidate is pinned to. L9-Q3 never leaves
     * software engineering.
     */
    private const ENGINEERING_SCOPE = 'engineering_only';

    /**
     * Default engineering stage applied when an outcome is in engineering scope
     * but names no recognisable pipeline stage.
     */
    private const DEFAULT_STAGE = 'unspecified';

    /**
     * Canonical software-engineering pipeline stages a discovered method may
     * affect. An outcome stage token is normalised against this set; an
     * engineering-scoped outcome whose token is unknown still produces a
     * candidate, pinned to the default stage.
     *
     * @var list<string>
     */
    private const ENGINEERING_STAGES = [
        'planning',
        'spec',
        'design',
        'implementation',
        'review',
        'testing',
        'integration',
        'merge',
        'release',
    ];

    /**
     * Non-engineering domains that take an outcome out of L9 scope. Mirrors the
     * L9 scope-creep lexicon (marketing/finance/cyber/trading/external company,
     * plus domain generators). An outcome resolving to any of these is rejected
     * and never becomes a method candidate.
     *
     * @var list<string>
     */
    private const NON_ENGINEERING_DOMAINS = [
        'marketing',
        'finance',
        'cyber',
        'trading',
        'sales',
        'legal',
        'external_company',
        'domain_generator',
    ];

    /**
     * @param  array<string, mixed>  $outcomes
     * @return array{
     *     schema_version: string,
     *     scope: string,
     *     insufficient_evidence: bool,
     *     candidate_count: int,
     *     candidates: list<array{
     *         method_candidate_id: string,
     *         hypothesis: string,
     *         affected_engineering_stage: string,
     *         expected_metric_delta: float,
     *         scope: string,
     *         source_refs: list<string>
     *     }>,
     *     rejected_non_engineering: list<string>,
     *     rejected_duplicates: list<string>,
     *     measured_outcome_count: int
     * }
     */
    public function build(array $outcomes): array
    {
        $rows = $this->rows($outcomes);

        $candidates = [];
        $rejectedNonEngineering = [];
        $rejectedDuplicates = [];
        $seen = [];
        $measuredOutcomeCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $methodId = $this->methodId($row);

            if ($methodId === '') {
                continue;
            }

            // Rule: non-engineering scope rejects — evaluated before measurement
            // so that an out-of-scope outcome can never become a candidate.
            $domain = $this->nonEngineeringDomain($row);

            if ($domain !== '') {
                $reason = $methodId . ':' . $domain;
                if (! in_array($reason, $rejectedNonEngineering, true)) {
                    $rejectedNonEngineering[] = $reason;
                }

                continue;
            }

            // Rule: no measured outcome blocks — an outcome with no real
            // measurement contributes nothing and cannot seed a candidate.
            if (! $this->hasMeasurement($row)) {
                continue;
            }

            $measuredOutcomeCount++;

            // Rule: duplicate method rejects — a method already specified
            // collapses; the later occurrence is recorded, never re-emitted.
            if (isset($seen[$methodId])) {
                if (! in_array($methodId, $rejectedDuplicates, true)) {
                    $rejectedDuplicates[] = $methodId;
                }

                continue;
            }
            $seen[$methodId] = true;

            $candidates[] = [
                'method_candidate_id' => $methodId,
                'hypothesis' => $this->hypothesis($row, $methodId),
                'affected_engineering_stage' => $this->affectedStage($row),
                'expected_metric_delta' => $this->expectedMetricDelta($row),
                'scope' => self::ENGINEERING_SCOPE,
                'source_refs' => $this->sourceRefs($row, $methodId),
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp($a['method_candidate_id'], $b['method_candidate_id'])
        );

        // String ordering (not SORT_REGULAR): method-candidate ids are slugs and
        // may be pure numeric strings ("2", "10", "100"). Default sort() would
        // order those numerically, diverging from the strcmp-based candidate
        // ordering above and breaking the list<string> identity contract.
        sort($rejectedNonEngineering, SORT_STRING);
        sort($rejectedDuplicates, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => self::ENGINEERING_SCOPE,
            'insufficient_evidence' => $candidates === [],
            'candidate_count' => count($candidates),
            'candidates' => array_values($candidates),
            'rejected_non_engineering' => $rejectedNonEngineering,
            'rejected_duplicates' => $rejectedDuplicates,
            'measured_outcome_count' => $measuredOutcomeCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $outcomes
     * @return list<mixed>
     */
    private function rows(array $outcomes): array
    {
        $rows = $outcomes['outcomes'] ?? $outcomes['measured_outcomes'] ?? $outcomes;

        if (! is_array($rows)) {
            return [];
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function methodId(array $row): string
    {
        $raw = $row['method']
            ?? $row['method_id']
            ?? $row['method_name']
            ?? $row['name']
            ?? '';

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return '';
        }

        return $this->slug((string) $raw);
    }

    /**
     * Resolves the non-engineering domain for a row, or '' when the outcome is
     * in engineering scope. An explicit engineering scope/domain token always
     * keeps the outcome in scope.
     *
     * @param  array<string, mixed>  $row
     */
    private function nonEngineeringDomain(array $row): string
    {
        foreach (['scope', 'domain', 'area'] as $key) {
            $value = $row[$key] ?? null;

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $slug = $this->slug((string) $value);

            if ($slug === '') {
                continue;
            }

            if (in_array($slug, self::NON_ENGINEERING_DOMAINS, true)) {
                return $slug;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasMeasurement(array $row): bool
    {
        return $this->measuredDelta($row) !== null;
    }

    /**
     * Expected metric delta computed from measurement: explicit delta wins,
     * else after − before, else the standalone measured value. Returns the
     * (fail-closed) 0.0 only when there is no measurement at all — in the
     * candidate path this is unreachable because hasMeasurement() gates first.
     *
     * @param  array<string, mixed>  $row
     */
    private function expectedMetricDelta(array $row): float
    {
        return $this->measuredDelta($row) ?? 0.0;
    }

    /**
     * Single source of truth for "is this a real measurement, and what is its
     * delta". Explicit delta wins, else after − before, else the standalone
     * measured value; null when no measurement is present.
     *
     * The before − after subtraction is checked for finiteness AFTER the
     * arithmetic: two individually-finite operands of opposite sign (e.g.
     * ±1e308) overflow to ±INF, which would poison the >= 0.0 direction test
     * and — like any non-finite value — make expected_metric_delta
     * un-encodable in the evidence envelope. An overflowing difference is not a
     * real measurement, so it fails closed (treated as absent) exactly like a
     * non-finite operand, keeping hasMeasurement() and expectedMetricDelta() in
     * lockstep.
     *
     * @param  array<string, mixed>  $row
     */
    private function measuredDelta(array $row): ?float
    {
        $delta = $this->numericOrNull($row['metric_delta'] ?? null);
        if ($delta !== null) {
            return round($delta, 4);
        }

        $before = $this->numericOrNull($row['metric_before'] ?? $row['baseline'] ?? null);
        $after = $this->numericOrNull($row['metric_after'] ?? $row['observed'] ?? null);

        if ($before !== null && $after !== null) {
            $diff = $after - $before;

            return is_finite($diff) ? round($diff, 4) : null;
        }

        $measured = $this->numericOrNull($row['measured_value'] ?? null);

        return $measured === null ? null : round($measured, 4);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function affectedStage(array $row): string
    {
        foreach (['affected_engineering_stage', 'engineering_stage', 'stage'] as $key) {
            $value = $row[$key] ?? null;

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $slug = $this->slug((string) $value);

            if ($slug !== '' && in_array($slug, self::ENGINEERING_STAGES, true)) {
                return $slug;
            }
        }

        return self::DEFAULT_STAGE;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hypothesis(array $row, string $methodId): string
    {
        $explicit = $row['hypothesis'] ?? null;

        if ((is_string($explicit) || is_int($explicit) || is_float($explicit))
            && trim((string) $explicit) !== ''
        ) {
            return trim((string) $explicit);
        }

        $stage = $this->affectedStage($row);
        $delta = $this->expectedMetricDelta($row);
        $direction = $delta >= 0.0 ? 'improves' : 'regresses';

        return sprintf(
            'method %s %s the %s stage by %s',
            $methodId,
            $direction,
            $stage,
            $this->formatDelta($delta)
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function sourceRefs(array $row, string $methodId): array
    {
        $raw = $row['source_refs'] ?? $row['evidence_refs'] ?? null;

        $refs = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    continue;
                }

                $ref = trim((string) $value);
                if ($ref === '' || in_array($ref, $refs, true)) {
                    continue;
                }

                $refs[] = $ref;
            }
        }

        if ($refs === []) {
            $refs[] = 'measured_outcome:' . $methodId;
        }

        return $refs;
    }

    private function formatDelta(float $delta): string
    {
        $formatted = rtrim(rtrim(number_format($delta, 4, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        // A non-finite value (NAN, +/-INF) is not a real measurement: NAN would
        // poison the >= 0.0 direction test (NAN >= 0.0 is false) and any such
        // value makes expected_metric_delta un-encodable in the evidence
        // envelope. Treat it as absent so the no-measurement rule fails closed.
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : null;
        }

        return null;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
