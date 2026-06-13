<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S138 — L9Q1OperatorJudgmentAmplificationCertificationService (block: L9 Sovereign
 * Engineering).
 *
 * Read-only certification of the L9-Q1 teto ("amplificar o julgamento do operador").
 * Q1 is real ONLY when the operator validates a provably larger volume of engineering
 * per unit time WITHOUT losing sovereignty — and the Q1 arrival criterion in the L9
 * sovereign-engineering map (atlas-aaeos-l9-sovereign-engineering-map.md line 219) is
 * exactly three measured facts together:
 *
 *   "o operador valida volume de engenharia comprovadamente maior por unidade de tempo,
 *    com override caindo de forma medida e zero decisao fora do escopo/risco delegado."
 *
 * i.e. (1) throughput lift is positive, (2) the override rate IMPROVES (falls in a
 * measured way), and (3) ZERO pre-decisions exceeded the proven Q2 bounds. Q2 is the
 * safety precondition of Q1 (line 220: "Q2 e a pre-condicao de seguranca para Q1/Q3";
 * line 219 invariant: "Q1 so e seguro porque esse invariante nao se move; Q2 o
 * protege"), so an uncertified Q2 blocks Q1 outright — amplifying operator judgment on
 * top of unproven invariants is precisely the sovereignty risk the thesis refuses.
 *
 * This certifier is read-only and fail-closed: it never promotes a level, never mutates
 * state, never executes, and never hides a blocker. Every returned field is COMPUTED
 * from the supplied evidence (the throughput-lift measurement and the Q2 certification
 * status), never canned.
 *
 * Three real quantities are surfaced from the throughput-lift evidence (the shape
 * produced by the L9 operator-throughput-lift scorer: throughput_lift,
 * override_rate_delta, out_of_bound_count):
 *   - throughput_lift (float): operator-validated engineering throughput of the current
 *     window minus the baseline. Read from `throughput_lift`, else computed as
 *     current_throughput - baseline_throughput. Must be > 0 to certify (a non-positive
 *     lift is no amplification).
 *   - override_rate_delta (float, -1..1): current operator override rate minus baseline
 *     override rate, each clamped to 0..1. "Override improves" means the operator had to
 *     override the system LESS, so the rate FELL — a delta <= 0. A delta > 0 (override
 *     rate rose) is the `override_rate_up` failure: the operator is correcting the
 *     system more, not less.
 *   - out_of_bound_count (int, >= 0): how many pre-decisions exceeded the proven Q2
 *     delegation bounds. Any value > 0 blocks (a single decision outside the proven
 *     scope/risk breaks the "zero decisao fora do escopo/risco delegado" criterion and
 *     the Q2 safety boundary).
 *
 * Verdict rules (ordered, safety-first — same doctrine as the L8 P-phase certs):
 *   - Q2 not certified blocks first (`q2_not_certified`) — Q1 has no safe boundary to
 *     stand on without proven invariants;
 *   - a non-positive throughput lift blocks (`throughput_lift_not_positive`) — without a
 *     measured volume gain there is nothing to certify;
 *   - any decision beyond the proven bounds blocks (`out_of_bound_decisions_present`)
 *     when out_of_bound_count > 0 — sovereignty/scope was exceeded;
 *   - an override rate that ROSE blocks (`override_rate_up`) when override_rate_delta > 0
 *     — the operator is overriding more, so judgment was not amplified;
 *   - q1_certified=true ONLY when every check is clean; otherwise q1_certified=false and
 *     the verdict carries every blocker in canonical order, never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules above. No
 * I/O, DB, Eloquent, facade, provider, git, filesystem, clock or randomness. Identical
 * inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
 */
final class L9Q1OperatorJudgmentAmplificationCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.q1_operator_judgment_amplification_certification.v1';

    /** L9 phase this service certifies. */
    public const PHASE = 'L9-Q1';

    public const STATUS_CERTIFIED = 'q1_certified';

    public const STATUS_BLOCKED = 'blocked_not_q1';

    /** Blocker when the L9-Q2 proven-invariant precondition is not certified. */
    private const BLOCKER_Q2_NOT_CERTIFIED = 'q2_not_certified';

    /** Blocker when the measured throughput lift is not strictly positive. */
    private const BLOCKER_THROUGHPUT_LIFT_NOT_POSITIVE = 'throughput_lift_not_positive';

    /** Blocker when any pre-decision exceeded the proven Q2 delegation bounds. */
    private const BLOCKER_OUT_OF_BOUND_DECISIONS = 'out_of_bound_decisions_present';

    /** Blocker when the operator override rate rose instead of improving (falling). */
    private const BLOCKER_OVERRIDE_RATE_UP = 'override_rate_up';

    /**
     * Certify L9-Q1 from operator-judgment-amplification evidence.
     *
     * Recognised `$inputs`:
     *   - q2: array<string,mixed>|bool — the L9-Q2 proven-invariant certification.
     *     Accepted as a bool, or an array asserting certification via
     *     `q2_certified`/`certified`/`passed` === true. Also accepted at the top level
     *     as `q2_certified` (bool). Fail-closed: Q2 counts as certified only on an
     *     explicit true.
     *   - throughput: array<string,mixed> — the throughput-lift measurement (the shape
     *     produced by the L9 throughput-lift scorer). `throughput_lift` is read directly
     *     when a finite number, else computed as
     *     (`current_throughput`|`current`) - (`baseline_throughput`|`baseline`).
     *     `override_rate_delta` is read directly when finite, else computed as
     *     (`current_override_rate` - `baseline_override_rate`), each clamped to 0..1.
     *     `out_of_bound_count` is read as a non-negative int (also accepted:
     *     `out_of_bound_decisions`/`out_of_bound`). The three metrics are also accepted
     *     at the top level of `$inputs` when no `throughput` sub-array is supplied.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     q1_certified:bool,
     *     status:string,
     *     q2_certified:bool,
     *     throughput_lift:float,
     *     override_rate_delta:float,
     *     override_rate_improved:bool,
     *     out_of_bound_count:int,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $throughputSource = is_array($inputs['throughput'] ?? null) ? $inputs['throughput'] : $inputs;

        $q2Certified = $this->q2Certified($inputs);
        $throughputLift = $this->throughputLift($throughputSource);
        $overrideRateDelta = $this->overrideRateDelta($throughputSource);
        $outOfBoundCount = $this->outOfBoundCount($throughputSource);

        // "Override improves" means the operator had to override LESS, so the rate fell:
        // a delta of zero or below. A positive delta is the override_rate_up failure.
        $overrideRateImproved = $overrideRateDelta <= 0.0;

        // Blocker order (canonical, safety-first): the Q2 safety precondition first (Q1
        // has no proven boundary to stand on without it), then a non-positive throughput
        // lift (nothing was amplified), then any decision beyond the proven bounds
        // (sovereignty/scope exceeded), then a rising override rate (the operator is
        // correcting the system more, not less). No blocker is ever hidden.
        $blockers = [];

        if (! $q2Certified) {
            $blockers[] = self::BLOCKER_Q2_NOT_CERTIFIED;
        }

        if ($throughputLift <= 0.0) {
            $blockers[] = self::BLOCKER_THROUGHPUT_LIFT_NOT_POSITIVE;
        }

        if ($outOfBoundCount > 0) {
            $blockers[] = self::BLOCKER_OUT_OF_BOUND_DECISIONS;
        }

        if ($overrideRateDelta > 0.0) {
            $blockers[] = self::BLOCKER_OVERRIDE_RATE_UP;
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'q1_certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'q2_certified' => $q2Certified,
            'throughput_lift' => $throughputLift,
            'override_rate_delta' => $overrideRateDelta,
            'override_rate_improved' => $overrideRateImproved,
            'out_of_bound_count' => $outOfBoundCount,
            'blockers' => $blockers,
        ];
    }

    /**
     * Whether the L9-Q2 proven-invariant precondition is certified. Fail-closed: only an
     * explicit true (bool, or an array asserting q2_certified/certified/passed, or the
     * top-level `q2_certified` flag) counts.
     *
     * @param  array<string,mixed>  $inputs
     */
    private function q2Certified(array $inputs): bool
    {
        $q2 = $inputs['q2'] ?? null;

        if (is_bool($q2)) {
            return $q2;
        }

        if (is_array($q2)) {
            foreach (['q2_certified', 'certified', 'passed'] as $flag) {
                if (array_key_exists($flag, $q2)) {
                    return $q2[$flag] === true;
                }
            }
        }

        return ($inputs['q2_certified'] ?? null) === true;
    }

    /**
     * Operator-validated engineering throughput lift. Read from `throughput_lift` when a
     * finite number, else computed as current - baseline (current and baseline each
     * default to 0.0 when absent). Not clamped: a lift may be any real magnitude, and
     * the certification only requires it to be strictly positive.
     *
     * @param  array<string,mixed>  $source
     */
    private function throughputLift(array $source): float
    {
        $explicit = $source['throughput_lift'] ?? null;
        if ($this->finiteNumber($explicit)) {
            return $this->roundThroughputLift((float) $explicit);
        }

        $current = $this->finiteNumberOrZero($source['current_throughput'] ?? $source['current'] ?? null);
        $baseline = $this->finiteNumberOrZero($source['baseline_throughput'] ?? $source['baseline'] ?? null);

        return $this->roundThroughputLift($current - $baseline);
    }

    /**
     * Operator override-rate delta (current minus baseline), bounded to -1..1. Read from
     * `override_rate_delta` when finite (then clamped to -1..1), else computed from the
     * current and baseline override rates, each clamped to the 0..1 unit interval so the
     * delta cannot leave -1..1.
     *
     * @param  array<string,mixed>  $source
     */
    private function overrideRateDelta(array $source): float
    {
        $explicit = $source['override_rate_delta'] ?? null;
        if ($this->finiteNumber($explicit)) {
            return $this->roundOverrideRateDelta($this->clampSigned((float) $explicit));
        }

        $current = $this->finiteClampUnit($this->finiteNumberOrZero($source['current_override_rate'] ?? null));
        $baseline = $this->finiteClampUnit($this->finiteNumberOrZero($source['baseline_override_rate'] ?? null));

        return $this->roundOverrideRateDelta($current - $baseline);
    }

    /**
     * Count of pre-decisions that exceeded the proven Q2 delegation bounds, as a
     * non-negative int. Accepts `out_of_bound_count`, `out_of_bound_decisions` or
     * `out_of_bound`. Negative or non-numeric values floor at 0.
     *
     * @param  array<string,mixed>  $source
     */
    private function outOfBoundCount(array $source): int
    {
        $value = $source['out_of_bound_count']
            ?? $source['out_of_bound_decisions']
            ?? $source['out_of_bound']
            ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (
            (is_float($value) && is_finite($value))
            || (is_string($value) && is_numeric($value) && is_finite((float) $value))
        ) {
            // A float at or beyond 2^63 is not representable as an int: casting it
            // would emit a runtime warning and overflow to a platform-dependent
            // (even negative) value, breaking purity/determinism. A magnitude that
            // large is unambiguously > 0, so it must block; clamp it to PHP_INT_MAX.
            $value = (float) $value;

            if ($value >= 9223372036854775808.0) {
                return PHP_INT_MAX;
            }

            $int = (int) $value;

            return $int > 0 ? $int : 0;
        }

        return 0;
    }

    /**
     * Whether a value is a finite number accepted by the metric normalizers.
     */
    private function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && is_finite((float) $value);
    }

    /**
     * Finite numeric metric value, fail-closed to zero when absent or invalid.
     */
    private function finiteNumberOrZero(mixed $value): float
    {
        return $this->finiteNumber($value) ? (float) $value : 0.0;
    }

    /**
     * Clamp a finite rate to the 0..1 unit interval.
     */
    private function finiteClampUnit(float $value): float
    {
        if (! is_finite($value)) {
            return 0.0;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        return $value > 1.0 ? 1.0 : $value;
    }

    /**
     * Clamp a float to the signed -1..1 interval (and neutralise NaN to 0.0).
     */
    private function clampSigned(float $value): float
    {
        if (! is_finite($value)) {
            return 0.0;
        }

        if ($value < -1.0) {
            return -1.0;
        }

        return $value > 1.0 ? 1.0 : $value;
    }

    /**
     * Round a throughput lift without erasing a strictly positive measured gain.
     */
    private function roundThroughputLift(float $value): float
    {
        $rounded = $this->round($value);

        return $value > 0.0 && $rounded <= 0.0 ? $value : $rounded;
    }

    /**
     * Round an override-rate delta without erasing a strictly positive measured increase.
     */
    private function roundOverrideRateDelta(float $value): float
    {
        $rounded = $this->round($value);

        return $value > 0.0 && $rounded <= 0.0 ? $value : $rounded;
    }

    /**
     * Round a measured quantity to a stable 4 decimals (deterministic; never pushes a
     * value past a bound it already satisfies).
     */
    private function round(float $value): float
    {
        return round($value, 4);
    }
}
