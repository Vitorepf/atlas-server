<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S153 — L10LongHorizonTelosProposalSpecBuilder (block: L10 Generative Engineering Guard).
 *
 * L10-R2 ("estrategia de engenharia de longo horizonte"): the system FORMS and
 * PROPOSES a multi-year engineering telos — strategic direction for what this
 * universe of software should BECOME over several years — built strictly FROM
 * evidence. The doctrine is load-bearing: "a estrategia propoe a direcao; o
 * operador cura os fins". The system executes strategy, it never chooses the
 * final ends. Therefore every proposal this builder emits is marked
 * operator_curation_required=true and pins system_chosen_final_ends as a
 * forbidden action — the system articulating latent strategy is allowed; the
 * system authoring the final ends is forbidden.
 *
 * Rules (ordered, all computed from the method inputs):
 *   1. No evidence blocks — a telos with nothing behind it is not a proposal;
 *      it would be the system inventing direction. Empty/ref-less evidence
 *      yields the `no_evidence` blocker and zero strategic bets.
 *   2. Horizon below one year blocks — a sub-year horizon is tactical, not the
 *      multi-year telos R2 requires; it yields the `horizon_below_one_year`
 *      blocker.
 *   3. system_chosen_final_ends is forbidden — always present in
 *      forbidden_actions and reflected by system_chosen_final_ends_forbidden.
 *
 * When neither blocker fires the builder derives one strategic bet per distinct
 * evidence ref (the strategy is grounded in evidence, never free-floating), a
 * deterministic telos_proposal_id folded purely from the normalised horizon and
 * the sorted evidence refs, and the normalised whole-year horizon.
 *
 * Pure: every returned field is computed from build()'s inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider/HTTP, git/Process, filesystem,
 * clock/now() or randomness. Identical inputs always yield an identical result;
 * this class only SPECIFIES a proposal — it never approves, persists or executes
 * a telos.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
 */
final class L10LongHorizonTelosProposalSpecBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.long_horizon_telos_proposal_spec.v1';

    /** The engineering scope every proposal is pinned to. L10 is engineering-only. */
    private const SCOPE = 'engineering_only';

    /** Minimum horizon (in whole years) for a proposal to count as long-horizon. */
    private const MIN_HORIZON_YEARS = 1;

    /**
     * The system action this builder permanently forbids: the system choosing
     * the final engineering ends. The operator is the sole source of ends; the
     * system proposes direction only.
     */
    private const FORBIDDEN_FINAL_ENDS_ACTION = 'system_chosen_final_ends';

    /**
     * System actions the proposal permanently forbids. `system_chosen_final_ends`
     * is always present; the siblings spell out the same prohibition (the system
     * never self-authorises or finalises engineering ends/values). Sorted so the
     * registry is deterministic regardless of authoring order.
     *
     * @var list<string>
     */
    private const FORBIDDEN_ACTIONS = [
        'auto_approve_telos',
        'finalize_engineering_values',
        'self_authorize_telos',
        'system_chosen_final_ends',
    ];

    /**
     * Blocker raised when no usable evidence ref is supplied.
     */
    private const BLOCKER_NO_EVIDENCE = 'no_evidence';

    /**
     * Blocker raised when the requested horizon is below one whole year.
     */
    private const BLOCKER_HORIZON_BELOW_ONE_YEAR = 'horizon_below_one_year';

    /**
     * Build a long-horizon engineering telos proposal spec from evidence.
     *
     * `$evidence` may be a list of refs (strings/ints/floats) or a list of rows
     * each carrying `ref`/`source`/`id`/`evidence_ref`, or a wrapper array under
     * `evidence`/`evidence_refs`. `$horizon` may be a numeric scalar of years or
     * an array carrying `years`/`horizon_years`/`months`.
     *
     * @param  array<int|string, mixed>  $evidence
     * @param  array<string, mixed>|float|int|string|null  $horizon
     * @return array{
     *     schema_version: string,
     *     scope: string,
     *     telos_proposal_id: string,
     *     horizon_years: int,
     *     strategic_bets: list<array{
     *         bet_id: string,
     *         direction: string,
     *         evidence_ref: string,
     *         horizon_years: int
     *     }>,
     *     strategic_bet_count: int,
     *     evidence_refs: list<string>,
     *     evidence_ref_count: int,
     *     operator_curation_required: bool,
     *     forbidden_actions: list<string>,
     *     system_chosen_final_ends_forbidden: bool,
     *     proposable: bool,
     *     blockers: list<string>
     * }
     */
    public function build(array $evidence, array|float|int|string|null $horizon = null): array
    {
        $evidenceRefs = $this->evidenceRefs($evidence);
        $horizonYears = $this->horizonYears($horizon);

        $blockers = [];

        // Rule 1: no evidence blocks.
        if ($evidenceRefs === []) {
            $blockers[] = self::BLOCKER_NO_EVIDENCE;
        }

        // Rule 2: horizon below one year blocks.
        if ($horizonYears < self::MIN_HORIZON_YEARS) {
            $blockers[] = self::BLOCKER_HORIZON_BELOW_ONE_YEAR;
        }

        $proposable = $blockers === [];

        $strategicBets = $proposable
            ? $this->strategicBets($evidenceRefs, $horizonYears)
            : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => self::SCOPE,
            'telos_proposal_id' => $this->telosProposalId($evidenceRefs, $horizonYears),
            'horizon_years' => $horizonYears,
            'strategic_bets' => $strategicBets,
            'strategic_bet_count' => count($strategicBets),
            'evidence_refs' => $evidenceRefs,
            'evidence_ref_count' => count($evidenceRefs),
            // Rule 3 / doctrine: strategy proposes, operator curates the ends.
            'operator_curation_required' => true,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'system_chosen_final_ends_forbidden' => $this->forbids(self::FORBIDDEN_FINAL_ENDS_ACTION),
            'proposable' => $proposable,
            'blockers' => $blockers,
        ];
    }

    /**
     * Whether a system action is forbidden by the proposal. Computed by real
     * membership over the forbidden-action registry (never a canned answer).
     */
    public function forbids(string $action): bool
    {
        return in_array($action, self::FORBIDDEN_ACTIONS, true);
    }

    /**
     * Normalise the evidence input into a deduplicated, ascending list of
     * non-empty string refs. Accepts a wrapper array, a list of scalar refs, or
     * a list of rows carrying a ref-like key.
     *
     * @param  array<int|string, mixed>  $evidence
     * @return list<string>
     */
    private function evidenceRefs(array $evidence): array
    {
        $rows = $evidence['evidence'] ?? $evidence['evidence_refs'] ?? $evidence;

        if (! is_array($rows)) {
            return [];
        }

        $refs = [];

        foreach ($rows as $row) {
            $ref = $this->refOf($row);

            if ($ref === '' || in_array($ref, $refs, true)) {
                continue;
            }

            $refs[] = $ref;
        }

        // String (lexicographic) sort: evidence_refs is list<string>, so "ascending"
        // is well-defined only as a string order. Bare sort()/SORT_REGULAR would
        // order numeric-looking refs ('10','9','100') numerically and — worse — leave
        // numerically-equal-but-distinct refs ('1','01','1.0') in input order, so the
        // same evidence SET presented in a different order would yield a different
        // sorted list and a different telos_proposal_id, breaking determinism.
        sort($refs, SORT_STRING);

        return $refs;
    }

    /**
     * Resolve a single evidence ref from a row that may be a scalar or an array
     * carrying a ref-like key. Returns '' when no usable ref is present.
     */
    private function refOf(mixed $row): string
    {
        if (is_string($row) || is_int($row) || is_float($row)) {
            return trim((string) $row);
        }

        if (is_array($row)) {
            foreach (['ref', 'evidence_ref', 'source', 'source_ref', 'id'] as $key) {
                $value = $row[$key] ?? null;

                if (is_string($value) || is_int($value) || is_float($value)) {
                    $ref = trim((string) $value);

                    if ($ref !== '') {
                        return $ref;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Normalise the horizon into whole years (>= 0). A numeric scalar is read as
     * years; an array may carry explicit `years`/`horizon_years` (years) or
     * `months` (converted, 12 months per year). Fractional years floor down, so
     * an 11-month or 0.5-year horizon resolves to 0 and is correctly rejected as
     * below one year. Non-numeric input resolves to 0.
     *
     * @param  array<string, mixed>|float|int|string|null  $horizon
     */
    private function horizonYears(array|float|int|string|null $horizon): int
    {
        if (is_array($horizon)) {
            $years = $this->numericOrNull($horizon['years'] ?? $horizon['horizon_years'] ?? null);

            if ($years !== null) {
                return $this->floorNonNegative($years);
            }

            $months = $this->numericOrNull($horizon['months'] ?? null);

            if ($months !== null) {
                return $this->floorNonNegative($months / 12.0);
            }

            return 0;
        }

        $years = $this->numericOrNull($horizon);

        return $years === null ? 0 : $this->floorNonNegative($years);
    }

    /**
     * Derive one strategic bet per distinct evidence ref. The strategy is
     * grounded in evidence: each bet references exactly one evidence ref and
     * carries the proposal horizon. Bets follow the (already sorted) evidence
     * order so the output is deterministic.
     *
     * @param  list<string>  $evidenceRefs
     * @return list<array{bet_id: string, direction: string, evidence_ref: string, horizon_years: int}>
     */
    private function strategicBets(array $evidenceRefs, int $horizonYears): array
    {
        $bets = [];
        $position = 1;

        foreach ($evidenceRefs as $ref) {
            $bets[] = [
                'bet_id' => sprintf('bet.%02d.%s', $position, AreaFocusSlugNormalizer::lowerSnakeToken($ref)),
                // Proposed direction only — never a chosen final end.
                'direction' => sprintf(
                    'propose engineering direction over %d-year horizon from evidence %s',
                    $horizonYears,
                    $ref
                ),
                'evidence_ref' => $ref,
                'horizon_years' => $horizonYears,
            ];

            $position++;
        }

        return $bets;
    }

    /**
     * Deterministic telos proposal id folded purely from the normalised horizon
     * and the sorted evidence refs. No randomness, no clock: identical inputs
     * always produce the same id; different evidence/horizon produce a different
     * id (the digest of the joined refs distinguishes content).
     *
     * @param  list<string>  $evidenceRefs
     */
    private function telosProposalId(array $evidenceRefs, int $horizonYears): string
    {
        $digest = $evidenceRefs === []
            ? 'none'
            : substr(hash('sha256', implode('|', $evidenceRefs)), 0, 12);

        return sprintf('l10.telos.%dy.%d.%s', $horizonYears, count($evidenceRefs), $digest);
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Floor a horizon to a non-negative whole-year int without leaking runtime
     * warnings or overflow garbage.
     *
     * A non-finite (NAN/INF) horizon is not a real finite multi-year horizon —
     * it carries no usable years, so it resolves to 0 (and is then correctly
     * rejected as below one year), exactly like non-numeric input. A finite
     * magnitude at or beyond 2^63 is not representable as an int: casting it
     * would emit a runtime warning and OVERFLOW to a platform-dependent value
     * that wraps NEGATIVE — which would leak a negative horizon_years (breaking
     * the >= 0 contract) into the output and telos_proposal_id. Saturate such a
     * value to PHP_INT_MAX so it stays a valid (huge) non-negative horizon.
     */
    private function floorNonNegative(float $value): int
    {
        if (! is_finite($value) || $value <= 0.0) {
            return 0;
        }

        if ($value >= 9223372036854775808.0) {
            return PHP_INT_MAX;
        }

        return (int) floor($value);
    }
}
