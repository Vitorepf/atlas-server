<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S113 — L8 Transcendence / P2 (Meta-compounding).
 *
 * Mines candidate new M factors from measured evidence windows WITHOUT adding
 * them to the N x M equation. Doctrine: "Discover factors from measured
 * evidence, not imagination." A candidate is only emitted when a signal window
 * carries a measurable correlation with the observed dM/dt series; factors that
 * merely duplicate a factor already present in the equation are rejected.
 *
 * Pure: every returned field is computed from the method input via real rules
 * (Pearson correlation over the supplied numeric series, deterministic slugging,
 * set membership against the current-factor lexicon). No I/O, no clock, no
 * randomness, no write authority — the equation is never mutated here.
 */
final class L8MetaCompoundingFactorCandidateMiner
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.meta_compounding.factor_candidates.v1';

    /**
     * Minimum number of paired observations a window needs before a correlation
     * is considered measurable rather than noise.
     */
    private const MIN_SAMPLES = 3;

    /**
     * Absolute Pearson-correlation floor below which a window is treated as
     * uncorrelated noise and yields no candidate.
     */
    private const CORRELATION_FLOOR = 0.5;

    /**
     * Canonical M factors already chosen by the operator and present in the
     * N x M equation (atlas-aaeos-l8-transcendence-map P2). A mined candidate
     * that resolves to one of these is rejected as a duplicate of a current
     * factor. Used as the default when the caller supplies no current set.
     *
     * @var list<string>
     */
    private const CANONICAL_CURRENT_FACTORS = [
        'governed_memory',
        'evidence',
        'compounding',
        'self_construction',
        'multi_agent_topology',
        'provider_portfolio',
        'sovereignty',
        'gates',
    ];

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{
     *     schema_version: string,
     *     insufficient_evidence: bool,
     *     candidates: list<array{
     *         factor_id: string,
     *         source_refs: list<string>,
     *         correlation_hint: float,
     *         sample_count: int,
     *         duplicate_of_current: bool
     *     }>,
     *     rejected_duplicates: list<string>,
     *     window_count: int,
     *     current_factors: list<string>,
     *     adds_to_equation: bool
     * }
     */
    public function mine(array $evidence): array
    {
        $windows = $this->windows($evidence);
        $currentFactors = $this->currentFactors($evidence);
        $currentLookup = array_fill_keys($currentFactors, true);

        $candidates = [];
        $rejectedDuplicates = [];
        $seen = [];

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $observations = $this->numericSeries($window, 'observations');
            $outcomes = $this->numericSeries($window, 'dm_dt');

            $sampleCount = min(count($observations), count($outcomes));

            if ($sampleCount < self::MIN_SAMPLES) {
                continue;
            }

            $observations = array_slice($observations, 0, $sampleCount);
            $outcomes = array_slice($outcomes, 0, $sampleCount);

            $correlation = $this->pearson($observations, $outcomes);

            if (abs($correlation) < self::CORRELATION_FLOOR) {
                continue;
            }

            $factorId = $this->factorId($window);

            if ($factorId === '') {
                continue;
            }

            $isDuplicate = isset($currentLookup[$factorId]);

            if ($isDuplicate) {
                if (! in_array($factorId, $rejectedDuplicates, true)) {
                    $rejectedDuplicates[] = $factorId;
                }

                continue;
            }

            if (isset($seen[$factorId])) {
                continue;
            }
            $seen[$factorId] = true;

            $candidates[] = [
                'factor_id' => $factorId,
                'source_refs' => $this->sourceRefs($window),
                'correlation_hint' => $correlation,
                'sample_count' => $sampleCount,
                'duplicate_of_current' => false,
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp($a['factor_id'], $b['factor_id'])
        );

        // factor ids are slugs (strings) and may be purely numeric (slug() keeps
        // digits); sort them as strings so the ordering matches the candidate list
        // and never coerces numeric-string ids into a numeric ordering.
        usort($rejectedDuplicates, static fn (string $a, string $b): int => strcmp($a, $b));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'insufficient_evidence' => $candidates === [],
            'candidates' => array_values($candidates),
            'rejected_duplicates' => $rejectedDuplicates,
            'window_count' => count($windows),
            'current_factors' => $currentFactors,
            'adds_to_equation' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<mixed>
     */
    private function windows(array $evidence): array
    {
        $windows = $evidence['windows'] ?? $evidence['signal_windows'] ?? [];

        if (! is_array($windows)) {
            return [];
        }

        return array_values($windows);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private function currentFactors(array $evidence): array
    {
        $raw = $evidence['current_factors'] ?? null;

        if (! is_array($raw) || $raw === []) {
            return self::CANONICAL_CURRENT_FACTORS;
        }

        $normalized = [];
        foreach ($raw as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $slug = AreaFocusSlugNormalizer::lowerSnakeToken((string) $value);
            if ($slug === '' || in_array($slug, $normalized, true)) {
                continue;
            }

            $normalized[] = $slug;
        }

        return $normalized === [] ? self::CANONICAL_CURRENT_FACTORS : $normalized;
    }

    /**
     * @param  array<string, mixed>  $window
     * @return list<float>
     */
    private function numericSeries(array $window, string $primaryKey): array
    {
        $raw = $window[$primaryKey] ?? null;

        if ($raw === null && $primaryKey === 'dm_dt') {
            $raw = $window['dm_dt_series'] ?? $window['outcome'] ?? null;
        }

        if ($raw === null && $primaryKey === 'observations') {
            $raw = $window['series'] ?? $window['values'] ?? null;
        }

        if (! is_array($raw)) {
            return [];
        }

        $series = [];
        foreach ($raw as $value) {
            if (is_int($value) || is_float($value)) {
                $series[] = (float) $value;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $series[] = (float) $value;
            }
        }

        return $series;
    }

    /**
     * Population Pearson correlation coefficient, clamped to [-1.0, 1.0].
     * Returns 0.0 when either series has zero variance (flat ⇒ no signal).
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    private function pearson(array $x, array $y): float
    {
        $n = count($x);
        if ($n === 0) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $covariance = 0.0;
        $varianceX = 0.0;
        $varianceY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $covariance += $dx * $dy;
            $varianceX += $dx * $dx;
            $varianceY += $dy * $dy;
        }

        if ($varianceX <= 0.0 || $varianceY <= 0.0) {
            return 0.0;
        }

        $denominator = sqrt($varianceX * $varianceY);

        // Overflow guard: a series of huge magnitudes drives the squared deltas to
        // +INF, so the variance product (and thus the denominator) is non-finite and
        // the ratio collapses to NAN — which the [-1.0, 1.0] clamp cannot tame
        // (max/min propagate NAN). Treat any non-finite intermediate exactly like a
        // flat series: no measurable signal, no fabricated correlation.
        if (! is_finite($denominator) || $denominator <= 0.0) {
            return 0.0;
        }

        $correlation = $covariance / $denominator;

        if (! is_finite($correlation)) {
            return 0.0;
        }

        $correlation = max(-1.0, min(1.0, $correlation));

        return round($correlation, 4);
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function factorId(array $window): string
    {
        $signal = $window['factor_id']
            ?? $window['signal_id']
            ?? $window['signal']
            ?? $window['name']
            ?? '';

        if (! is_string($signal) && ! is_int($signal) && ! is_float($signal)) {
            return '';
        }

        return AreaFocusSlugNormalizer::lowerSnakeToken((string) $signal);
    }

    /**
     * @param  array<string, mixed>  $window
     * @return list<string>
     */
    private function sourceRefs(array $window): array
    {
        $raw = $window['source_refs'] ?? $window['evidence_refs'] ?? null;

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
            $factorId = $this->factorId($window);
            if ($factorId !== '') {
                $refs[] = 'evidence_window:'.$factorId;
            }
        }

        return $refs;
    }
}
