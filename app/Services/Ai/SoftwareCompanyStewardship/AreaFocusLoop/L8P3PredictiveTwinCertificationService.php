<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S121 — L8P3PredictiveTwinCertificationService (block: L8 Transcendence).
 *
 * Read-only certification of the L8-P3 teto ("Twin preditivo do sistema"). P3 is
 * real only when the system CHOSE which evolutions to try guided by a forward
 * model (the "twin") and the rate of evolutions that were KEPT (not reverted) rose
 * PROVABLY versus the old trial-based selection — with the twin itself measured by
 * the accuracy of its predictions (atlas-aaeos-l8-transcendence-map.md, P3
 * criterio de chegada line 166 and the transcendence checklist line 245):
 *
 *   "taxa de evolucoes mantidas subiu com selecao guiada por twin vs. trial-based;
 *    twin medido por acuracia de predicao."
 *
 * P3 depends on P1 (a governed evolving FRAME to simulate) and P2 (a measured
 * METRIC to predict), and the whole L8 ladder sits on P5 (self-deception immunity)
 * as its universal safety precondition — so this certifier is fail-closed: missing
 * any of P1/P2/P5 blocks, a retained-rate that did not rise blocks, and a twin that
 * made predictions with no measured outcome (an un-grounded forecast that cannot
 * prove accuracy) blocks. It never promotes a level, never mutates state and never
 * hides a blocker.
 *
 * Two real quantities are COMPUTED from the supplied evidence:
 *   - twin_accuracy (0..1): of the supplied prediction records, the fraction that
 *     are GROUNDED (carry a measured outcome) AND whose predicted improvement
 *     direction matched the measured one. Predictions without an outcome never
 *     count as correct (they cannot ground accuracy) and additionally trip the
 *     `prediction_without_outcome` blocker. With no grounded prediction the
 *     accuracy is 0.0.
 *   - retained_rate_delta (-1..1): the twin-guided retained rate minus the
 *     trial-based baseline retained rate, where each arm's retained rate is
 *     retained / total (clamped to 0..1). A delta <= 0 means twin guidance did not
 *     provably raise the kept-evolution rate and blocks.
 *
 * Verdict rules (ordered, safety-first — same doctrine as the L7/P5/P1 certs):
 *   - any of P1/P2/P5 not certified blocks (`prerequisite_phase_not_certified`),
 *     listing the missing phases — P3 cannot stand without frame, metric and
 *     immunity;
 *   - a twin-guided retained rate that did not exceed the trial baseline
 *     (retained_rate_delta <= 0) blocks (`retained_rate_not_improved`);
 *   - any supplied prediction lacking a measured outcome blocks
 *     (`prediction_without_outcome`) — an un-grounded forecast is exactly the
 *     P5 Goodhart trap (claiming a win the twin never had to measure);
 *   - a grounded-but-weak twin whose measured accuracy is below the floor blocks
 *     (`twin_accuracy_below_floor`) — guidance no better than chance is not a twin;
 *   - p3_certified=true ONLY when every check is clean; otherwise p3_certified=false
 *     and the verdict carries every blocker in canonical order, never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
 */
final class L8P3PredictiveTwinCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.p3_predictive_twin_certification.v1';

    /** L8 phase this service certifies. */
    public const PHASE = 'L8-P3';

    public const STATUS_CERTIFIED = 'p3_certified';

    public const STATUS_BLOCKED = 'blocked_not_p3';

    /**
     * Minimum measured prediction accuracy for the twin to count as a real
     * forward model. Guidance at or below pure chance (0.5) is not a twin, so a
     * grounded twin below this floor cannot certify P3 even with a positive
     * retained-rate delta. Stable, deterministic threshold (better than chance).
     */
    public const TWIN_ACCURACY_FLOOR = 0.5;

    /**
     * The three phases P3 stands on, in canonical order: P1 (frame to simulate),
     * P2 (metric to predict) and P5 (self-deception immunity, the universal
     * precondition of the L8 ladder).
     *
     * @var list<string>
     */
    private const PREREQUISITE_PHASES = ['p1', 'p2', 'p5'];

    /** Blocker when any of P1/P2/P5 is not certified. */
    private const BLOCKER_PREREQUISITE_MISSING = 'prerequisite_phase_not_certified';

    /** Blocker when the twin-guided retained rate did not exceed the trial baseline. */
    private const BLOCKER_RETAINED_RATE_NOT_IMPROVED = 'retained_rate_not_improved';

    /** Blocker when a supplied prediction has no measured outcome to ground accuracy. */
    private const BLOCKER_PREDICTION_WITHOUT_OUTCOME = 'prediction_without_outcome';

    /** Blocker when the grounded twin's measured accuracy is below the floor. */
    private const BLOCKER_TWIN_ACCURACY_BELOW_FLOOR = 'twin_accuracy_below_floor';

    /**
     * Certify L8-P3 from twin-guided selection evidence.
     *
     * Recognised `$inputs`:
     *   - prerequisites: array<string,mixed> — certification status of the phases
     *     P3 depends on, keyed by 'p1'/'p2'/'p5' (also accepted: 'P1', plus the
     *     spelled keys 'frame'/'metric'/'immunity'). Each entry may be a bool, or
     *     an array carrying `certified`/`passed`/`p1_certified` etc. (bool). A
     *     phase is satisfied only when it explicitly asserts certified (fail-closed).
     *   - twin_guided: array<string,mixed> — the twin-guided arm; its retained rate
     *     is read from `retained_rate` (0..1) or computed from
     *     `retained_count`/`kept_count` over `total`/`selected_count`.
     *   - trial_baseline: array<string,mixed> — the trial-based baseline arm; same
     *     shape as twin_guided.
     *   - predictions: list<array<string,mixed>> — the twin's prediction records.
     *     A record is GROUNDED when it carries a measured outcome
     *     (`measured`/`actual_*`/`outcome`); it is CORRECT when grounded and its
     *     predicted improvement direction (`predicted_improved`, or sign of
     *     `predicted_delta`) matches the measured one (`actual_improved`, or sign
     *     of `actual_delta`). twin_accuracy = correct / supplied (0 when none
     *     grounded).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     p3_certified:bool,
     *     status:string,
     *     twin_accuracy:float,
     *     twin_accuracy_floor:float,
     *     retained_rate_delta:float,
     *     twin_guided_retained_rate:float,
     *     trial_baseline_retained_rate:float,
     *     prediction_count:int,
     *     grounded_prediction_count:int,
     *     correct_prediction_count:int,
     *     ungrounded_prediction_count:int,
     *     missing_prerequisites:list<string>,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $prerequisites = is_array($inputs['prerequisites'] ?? null) ? $inputs['prerequisites'] : [];
        $twinGuided = is_array($inputs['twin_guided'] ?? null) ? $inputs['twin_guided'] : [];
        $trialBaseline = is_array($inputs['trial_baseline'] ?? null) ? $inputs['trial_baseline'] : [];
        $predictions = $this->predictionList($inputs['predictions'] ?? null);

        // Prerequisites: P1/P2/P5 must each explicitly assert certified.
        $missingPrerequisites = $this->missingPrerequisites($prerequisites);

        // Retained-rate lift: twin-guided arm vs trial-based baseline. Each rate is
        // clamped to 0..1, so the delta is bounded to -1..1.
        $twinRate = $this->retainedRate($twinGuided);
        $baselineRate = $this->retainedRate($trialBaseline);
        $retainedRateDelta = $this->roundUnit($twinRate - $baselineRate);

        // Twin accuracy: fraction of supplied predictions that are grounded AND
        // correct. Ungrounded predictions never count as correct.
        $predictionCount = count($predictions);
        $groundedCount = 0;
        $correctCount = 0;

        foreach ($predictions as $prediction) {
            if (! $this->predictionIsGrounded($prediction)) {
                continue;
            }

            $groundedCount++;

            if ($this->predictionIsCorrect($prediction)) {
                $correctCount++;
            }
        }

        $ungroundedCount = $predictionCount - $groundedCount;
        $twinAccuracy = $predictionCount === 0
            ? 0.0
            : $this->roundUnit($correctCount / $predictionCount);

        // Blocker order (canonical, safety-first): prerequisite gaps first (P3 has
        // nothing to stand on), then an unimproved retained rate (the core claim of
        // P3), then any un-grounded prediction (the Goodhart trap), then a grounded
        // but below-floor twin. No blocker is ever hidden.
        $blockers = [];

        if ($missingPrerequisites !== []) {
            $blockers[] = self::BLOCKER_PREREQUISITE_MISSING;
        }

        if ($retainedRateDelta <= 0.0) {
            $blockers[] = self::BLOCKER_RETAINED_RATE_NOT_IMPROVED;
        }

        if ($ungroundedCount > 0) {
            $blockers[] = self::BLOCKER_PREDICTION_WITHOUT_OUTCOME;
        } elseif ($twinAccuracy < self::TWIN_ACCURACY_FLOOR) {
            // Only judge the floor when every prediction is grounded — otherwise the
            // un-grounded blocker already owns the failure and the accuracy is not
            // yet trustworthy.
            $blockers[] = self::BLOCKER_TWIN_ACCURACY_BELOW_FLOOR;
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'p3_certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'twin_accuracy' => $twinAccuracy,
            'twin_accuracy_floor' => self::TWIN_ACCURACY_FLOOR,
            'retained_rate_delta' => $retainedRateDelta,
            'twin_guided_retained_rate' => $twinRate,
            'trial_baseline_retained_rate' => $baselineRate,
            'prediction_count' => $predictionCount,
            'grounded_prediction_count' => $groundedCount,
            'correct_prediction_count' => $correctCount,
            'ungrounded_prediction_count' => $ungroundedCount,
            'missing_prerequisites' => $missingPrerequisites,
            'blockers' => $blockers,
        ];
    }

    /**
     * Canonical prerequisite phases not certified, in canonical order. A phase is
     * satisfied only when its evidence explicitly asserts it certified
     * (fail-closed); absent or unknown evidence is a missing prerequisite.
     *
     * @param  array<string,mixed>  $prerequisites
     * @return list<string>
     */
    private function missingPrerequisites(array $prerequisites): array
    {
        $missing = [];

        foreach (self::PREREQUISITE_PHASES as $phase) {
            if (! $this->phaseCertified($prerequisites, $phase)) {
                $missing[] = $phase;
            }
        }

        return $missing;
    }

    /**
     * Whether a single prerequisite phase is certified. Accepts the canonical key
     * ('p1'), its upper-case form ('P1') and a spelled alias
     * (frame/metric/immunity), each as a bool or an array asserting
     * certified/passed/<phase>_certified.
     *
     * @param  array<string,mixed>  $prerequisites
     */
    private function phaseCertified(array $prerequisites, string $phase): bool
    {
        foreach ($this->phaseKeys($phase) as $key) {
            if (! array_key_exists($key, $prerequisites)) {
                continue;
            }

            return $this->assertsCertified($prerequisites[$key], $phase);
        }

        return false;
    }

    /**
     * Accepted input keys for a prerequisite phase, most specific first.
     *
     * @return list<string>
     */
    private function phaseKeys(string $phase): array
    {
        $aliases = [
            'p1' => 'frame',
            'p2' => 'metric',
            'p5' => 'immunity',
        ];

        $keys = [$phase, strtoupper($phase)];

        if (isset($aliases[$phase])) {
            $keys[] = $aliases[$phase];
        }

        return $keys;
    }

    /**
     * A prerequisite entry asserts certification only via an explicit true: a bool
     * true, or an array carrying certified/passed/<phase>_certified === true.
     */
    private function assertsCertified(mixed $entry, string $phase): bool
    {
        if (is_bool($entry)) {
            return $entry;
        }

        if (is_array($entry)) {
            foreach (['certified', 'passed', $phase.'_certified'] as $flag) {
                if (array_key_exists($flag, $entry)) {
                    return $entry[$flag] === true;
                }
            }
        }

        return false;
    }

    /**
     * The retained (kept, not reverted) evolution rate of an arm, clamped to 0..1.
     * Read directly from `retained_rate` when a finite number, else computed from
     * a retained/kept count over a total/selected count. A zero or absent total
     * yields 0.0 (an arm that selected nothing retained nothing).
     *
     * @param  array<string,mixed>  $arm
     */
    private function retainedRate(array $arm): float
    {
        $explicit = $arm['retained_rate'] ?? $arm['kept_rate'] ?? null;
        if ((is_int($explicit) || is_float($explicit)) && is_finite((float) $explicit)) {
            return $this->clampUnit((float) $explicit);
        }

        $retained = $this->nonNegativeNumber($arm['retained_count'] ?? $arm['kept_count'] ?? null);
        $total = $this->nonNegativeNumber(
            $arm['total'] ?? $arm['total_count'] ?? $arm['selected_count'] ?? $arm['selections'] ?? null
        );

        if ($total <= 0.0) {
            return 0.0;
        }

        return $this->clampUnit($retained / $total);
    }

    /**
     * A prediction is grounded when it carries a measured outcome: an explicit
     * `measured`/`has_outcome` true, or any actual-outcome field
     * (actual_improved/actual_delta/actual/outcome). A forecast with no measured
     * outcome cannot ground accuracy.
     *
     * @param  array<string,mixed>  $prediction
     */
    private function predictionIsGrounded(array $prediction): bool
    {
        if (($prediction['measured'] ?? null) === true || ($prediction['has_outcome'] ?? null) === true) {
            return true;
        }

        if (($prediction['measured'] ?? null) === false || ($prediction['has_outcome'] ?? null) === false) {
            return false;
        }

        foreach (['actual_improved', 'actual_delta', 'actual', 'outcome', 'observed_delta', 'observed_improved'] as $key) {
            if (array_key_exists($key, $prediction) && $prediction[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A grounded prediction is correct when its predicted improvement direction
     * matches the measured one. Direction comes from an explicit boolean
     * (predicted_improved / actual_improved) or from the sign of a delta
     * (predicted_delta / actual_delta, observed_delta). Predictions that are not
     * grounded are never correct.
     *
     * @param  array<string,mixed>  $prediction
     */
    private function predictionIsCorrect(array $prediction): bool
    {
        if (! $this->predictionIsGrounded($prediction)) {
            return false;
        }

        $predicted = $this->improvementDirection($prediction, ['predicted_improved'], ['predicted_delta', 'predicted']);
        $actual = $this->improvementDirection(
            $prediction,
            ['actual_improved', 'observed_improved'],
            ['actual_delta', 'observed_delta', 'actual', 'outcome']
        );

        if ($predicted === null || $actual === null) {
            return false;
        }

        return $predicted === $actual;
    }

    /**
     * Resolve an improvement direction (true=improved, false=not) from a record:
     * first any explicit boolean flag, then the sign of the first numeric delta
     * field (>0 improved, <=0 not). Returns null when neither is present.
     *
     * @param  array<string,mixed>  $prediction
     * @param  list<string>  $boolKeys
     * @param  list<string>  $deltaKeys
     */
    private function improvementDirection(array $prediction, array $boolKeys, array $deltaKeys): ?bool
    {
        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $prediction) && is_bool($prediction[$key])) {
                return $prediction[$key];
            }
        }

        foreach ($deltaKeys as $key) {
            if (array_key_exists($key, $prediction)) {
                $value = $prediction[$key];
                if (is_int($value) || is_float($value)) {
                    return $value > 0;
                }
            }
        }

        return null;
    }

    /**
     * Coerce a value to a non-negative finite float; anything else is 0.0.
     */
    private function nonNegativeNumber(mixed $value): float
    {
        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            $number = (float) $value;

            return $number > 0.0 ? $number : 0.0;
        }

        return 0.0;
    }

    /**
     * Clamp a float to the 0..1 unit interval (and neutralise NaN to 0.0).
     */
    private function clampUnit(float $value): float
    {
        if (! is_finite($value) || $value < 0.0) {
            return 0.0;
        }

        return $value > 1.0 ? 1.0 : $value;
    }

    /**
     * Round a unit-scale quantity to a stable 4 decimals (deterministic; never
     * pushes a value past its bound).
     */
    private function roundUnit(float $value): float
    {
        return round($value, 4);
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function predictionList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
