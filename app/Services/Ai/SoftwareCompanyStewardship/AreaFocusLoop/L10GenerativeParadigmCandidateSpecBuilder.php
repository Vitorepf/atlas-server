<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S157 — L10 Generative Engineering Guard / R1 (generative engineering).
 *
 * Specifies candidate engineering paradigms or abstractions generated from
 * measured outcomes, marked proposal-only until novelty and safety are proven.
 * Doctrine (L10-R1): "R1 starts as proposal-only candidate, not magic
 * invention." A paradigm candidate is only emitted when an outcome carries a
 * real outcome basis (a measured delta) AND the non-negotiable precondition
 * holds: the R3 convergence bound is proven. The map is explicit (L10
 * pre-conditions): "O bound de convergencia e a soberania precedem R1/R2/R4."
 * Without a proven R3 bound every candidate is blocked; the safety gate pins
 * each candidate to measured-or-reverted under the proven bound, the twin and
 * the proven invariants.
 *
 * Pure: every returned field is computed from the method input via real rules
 * (deterministic slugging, measured before/after delta arithmetic, set
 * membership against the engineering-stage and non-engineering-domain lexicons,
 * deterministic safety-ref synthesis, deterministic ordering). No I/O, no DB,
 * no facades, no clock, no randomness, no write authority — this only specifies
 * proposal-only candidates, it never adopts a paradigm and never mutates
 * runtime.
 */
final class L10GenerativeParadigmCandidateSpecBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.generative_paradigm_candidate_spec.v1';

    /**
     * The scope every emitted candidate is pinned to. L10 is the asymptote of
     * software engineering and never leaves it.
     */
    private const ENGINEERING_SCOPE = 'engineering_only';

    /**
     * Default engineering stage applied when an outcome is in engineering scope
     * but names no recognisable pipeline stage.
     */
    private const DEFAULT_STAGE = 'unspecified';

    /**
     * Canonical software-engineering pipeline stages a generated paradigm may
     * affect. Mirrors the L9 engineering-stage lexicon byte-for-byte.
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
     * Non-engineering domains that take an outcome out of L10 scope. Mirrors the
     * L9 scope-creep lexicon byte-for-byte. An outcome resolving to any of these
     * is rejected and never becomes a paradigm candidate.
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
     *     proposal_only: true,
     *     mutates_runtime: false,
     *     r3_bound_proven: bool,
     *     blocked: bool,
     *     insufficient_evidence: bool,
     *     candidate_count: int,
     *     candidates: list<array{
     *         paradigm_candidate_id: string,
     *         novelty_claim: string,
     *         expected_outcome_delta: float,
     *         safety_refs: list<string>,
     *         scope: string,
     *         proposal_only: true
     *     }>,
     *     rejected_non_engineering: list<string>,
     *     rejected_no_basis: list<string>,
     *     measured_outcome_count: int,
     *     blockers: list<string>
     * }
     */
    public function build(array $outcomes): array
    {
        $r3BoundProven = $this->r3BoundProven($outcomes);
        $r3BoundRef = $this->r3BoundRef($outcomes);

        $rows = $this->rows($outcomes);

        $candidates = [];
        $rejectedNonEngineering = [];
        $rejectedNoBasis = [];
        $seen = [];
        $measuredOutcomeCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $paradigmId = $this->paradigmId($row);

            if ($paradigmId === '') {
                continue;
            }

            // Rule: non-engineering scope rejects — evaluated before measurement
            // so that an out-of-scope outcome can never become a candidate.
            $domain = $this->nonEngineeringDomain($row);

            if ($domain !== '') {
                $reason = $paradigmId . ':' . $domain;
                if (! in_array($reason, $rejectedNonEngineering, true)) {
                    $rejectedNonEngineering[] = $reason;
                }

                continue;
            }

            // Rule: no outcome basis blocks — an outcome with no real measured
            // basis contributes nothing and cannot seed a paradigm candidate.
            if (! $this->hasOutcomeBasis($row)) {
                if (! in_array($paradigmId, $rejectedNoBasis, true)) {
                    $rejectedNoBasis[] = $paradigmId;
                }

                continue;
            }

            $measuredOutcomeCount++;

            // Duplicate paradigm collapses; the later occurrence is discarded so
            // the same paradigm is never emitted twice.
            if (isset($seen[$paradigmId])) {
                continue;
            }
            $seen[$paradigmId] = true;

            // Rule: missing R3 bound blocks — without a proven convergence bound
            // no generative candidate may be specified (the bound precedes R1).
            // Measured rows are still counted so the basis is auditable, but no
            // candidate is materialised until the bound is proven.
            if (! $r3BoundProven) {
                continue;
            }

            $candidates[] = [
                'paradigm_candidate_id' => $paradigmId,
                'novelty_claim' => $this->noveltyClaim($row, $paradigmId),
                'expected_outcome_delta' => $this->expectedOutcomeDelta($row),
                'safety_refs' => $this->safetyRefs($row, $paradigmId, $r3BoundRef),
                'scope' => self::ENGINEERING_SCOPE,
                'proposal_only' => true,
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp($a['paradigm_candidate_id'], $b['paradigm_candidate_id'])
        );

        // A paradigm that produced a measured row (it is in $seen, whether emitted
        // as a candidate or collapsed as a duplicate) is NOT basis-less: an earlier
        // no-basis row for the same slug must not leave it in rejected_no_basis,
        // which would otherwise contradict the candidate and raise a spurious
        // no_outcome_basis blocker on a paradigm that does carry a basis. Only
        // paradigms with no measured basis on any row stay rejected.
        $rejectedNoBasis = array_values(array_filter(
            $rejectedNoBasis,
            static fn (string $paradigmId): bool => ! isset($seen[$paradigmId])
        ));

        // Sort as strings (SORT_STRING), consistent with the strcmp ordering used
        // for candidates above. Default SORT_REGULAR would order numeric-looking
        // paradigm slugs (e.g. "007", "7", "10") numerically and could treat
        // textually-distinct slugs as equal, breaking the list<string> ordering.
        sort($rejectedNonEngineering, SORT_STRING);
        sort($rejectedNoBasis, SORT_STRING);

        $blockers = [];

        if (! $r3BoundProven) {
            $blockers[] = 'r3_bound_missing';
        }

        if ($rejectedNoBasis !== []) {
            $blockers[] = 'no_outcome_basis';
        }

        if ($candidates === []) {
            $blockers[] = 'no_paradigm_candidate';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => self::ENGINEERING_SCOPE,
            'proposal_only' => true,
            'mutates_runtime' => false,
            'r3_bound_proven' => $r3BoundProven,
            'blocked' => $candidates === [],
            'insufficient_evidence' => $candidates === [],
            'candidate_count' => count($candidates),
            'candidates' => array_values($candidates),
            'rejected_non_engineering' => $rejectedNonEngineering,
            'rejected_no_basis' => $rejectedNoBasis,
            'measured_outcome_count' => $measuredOutcomeCount,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * The R3 convergence bound is the non-negotiable precondition: it must be
     * explicitly proven for any generative candidate to be specified.
     *
     * @param  array<string, mixed>  $outcomes
     */
    private function r3BoundProven(array $outcomes): bool
    {
        foreach (['r3_bound_proven', 'r3_convergence_bound_proven', 'convergence_bound_proven'] as $key) {
            if (($outcomes[$key] ?? null) === true) {
                return true;
            }
        }

        $bound = $outcomes['r3_bound'] ?? $outcomes['convergence_bound'] ?? null;

        if (is_array($bound)) {
            return ($bound['proven'] ?? false) === true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $outcomes
     */
    private function r3BoundRef(array $outcomes): string
    {
        $bound = $outcomes['r3_bound'] ?? $outcomes['convergence_bound'] ?? null;

        if (is_array($bound)) {
            $ref = $bound['ref'] ?? $bound['proof_ref'] ?? null;

            if ((is_string($ref) || is_int($ref) || is_float($ref)) && trim((string) $ref) !== '') {
                return 'r3_bound:' . trim((string) $ref);
            }
        }

        $ref = $outcomes['r3_bound_ref'] ?? $outcomes['convergence_bound_ref'] ?? null;

        if ((is_string($ref) || is_int($ref) || is_float($ref)) && trim((string) $ref) !== '') {
            return 'r3_bound:' . trim((string) $ref);
        }

        return 'r3_bound:proven';
    }

    /**
     * @param  array<string, mixed>  $outcomes
     * @return list<mixed>
     */
    private function rows(array $outcomes): array
    {
        $rows = $outcomes['outcomes'] ?? $outcomes['measured_outcomes'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function paradigmId(array $row): string
    {
        $raw = $row['paradigm']
            ?? $row['paradigm_id']
            ?? $row['paradigm_name']
            ?? $row['abstraction']
            ?? $row['name']
            ?? '';

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return '';
        }

        return $this->slug((string) $raw);
    }

    /**
     * Resolves the non-engineering domain for a row, or '' when the outcome is
     * in engineering scope.
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
    private function hasOutcomeBasis(array $row): bool
    {
        if ($this->numericOrNull($row['outcome_delta'] ?? $row['metric_delta'] ?? null) !== null) {
            return true;
        }

        $before = $this->numericOrNull($row['outcome_before'] ?? $row['metric_before'] ?? $row['baseline'] ?? null);
        $after = $this->numericOrNull($row['outcome_after'] ?? $row['metric_after'] ?? $row['observed'] ?? null);

        if ($before !== null && $after !== null) {
            return true;
        }

        return $this->numericOrNull($row['measured_value'] ?? null) !== null;
    }

    /**
     * Expected outcome delta computed from measurement: explicit delta wins,
     * else after − before, else the standalone measured value.
     *
     * @param  array<string, mixed>  $row
     */
    private function expectedOutcomeDelta(array $row): float
    {
        $delta = $this->numericOrNull($row['outcome_delta'] ?? $row['metric_delta'] ?? null);
        if ($delta !== null) {
            return round($delta, 4);
        }

        $before = $this->numericOrNull($row['outcome_before'] ?? $row['metric_before'] ?? $row['baseline'] ?? null);
        $after = $this->numericOrNull($row['outcome_after'] ?? $row['metric_after'] ?? $row['observed'] ?? null);

        if ($before !== null && $after !== null) {
            // Both operands are finite, but their subtraction can still overflow
            // to +/-INF for extreme magnitudes; a non-finite delta is not a real
            // measured outcome, so it collapses to 0.0 rather than leaking INF.
            $computed = $after - $before;

            return is_finite($computed) ? round($computed, 4) : 0.0;
        }

        $measured = $this->numericOrNull($row['measured_value'] ?? null);

        return $measured === null ? 0.0 : round($measured, 4);
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
     * Novelty claim: an explicit claim wins; otherwise a computed claim that
     * names the paradigm, the affected stage and the measured direction. Never
     * an absolute "proven new" — R1 is proposal-only until novelty is proven.
     *
     * @param  array<string, mixed>  $row
     */
    private function noveltyClaim(array $row, string $paradigmId): string
    {
        $explicit = $row['novelty_claim'] ?? null;

        if ((is_string($explicit) || is_int($explicit) || is_float($explicit))
            && trim((string) $explicit) !== ''
        ) {
            return trim((string) $explicit);
        }

        $stage = $this->affectedStage($row);
        $delta = $this->expectedOutcomeDelta($row);
        $direction = $delta >= 0.0 ? 'improves' : 'regresses';

        return sprintf(
            'proposed paradigm %s claims to expand the %s stage and %s outcome by %s',
            $paradigmId,
            $stage,
            $direction,
            $this->formatDelta($delta)
        );
    }

    /**
     * Safety references pin the candidate to the L10-R1 safety gate:
     * measured-or-reverted, under the proven R3 convergence bound, the twin
     * (P3) and the proven invariants (Q2). The proven R3 bound ref is always
     * present, then row-supplied refs, deduplicated as a list<string>.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function safetyRefs(array $row, string $paradigmId, string $r3BoundRef): array
    {
        $refs = [
            $r3BoundRef,
            'measured_or_reverted',
            'twin_p3',
            'invariants_q2',
        ];

        $raw = $row['safety_refs'] ?? $row['evidence_refs'] ?? null;

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

        if (! in_array('paradigm:' . $paradigmId, $refs, true)) {
            $refs[] = 'paradigm:' . $paradigmId;
        }

        return array_values($refs);
    }

    private function formatDelta(float $delta): string
    {
        $formatted = rtrim(rtrim(number_format($delta, 4, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function numericOrNull(mixed $value): ?float
    {
        // A non-finite numeric (NAN, +/-INF) — including a numeric string that
        // overflows to INF, e.g. "1e999" — is not a real measured value: it is a
        // garbled signal, never a measured outcome basis. Treating it as null
        // makes hasOutcomeBasis() reject a non-finite-only measurement (it lands
        // in rejected_no_basis) and guarantees every emitted expected_outcome_delta
        // stays finite and JSON-serialisable, matching the sibling outcome
        // validators across the loop.
        if (is_int($value) || is_float($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : null;
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
