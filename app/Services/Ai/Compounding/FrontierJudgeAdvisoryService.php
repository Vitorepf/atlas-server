<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;

/**
 * MAXJ-08 — Frontier motor no assento de JUIZ (advisory, zero veto).
 *
 * A second opinion on "does this lesson worth keeping?" from a frontier
 * engine — but never with veto power. §1686-1691 pétreos baked in the class:
 *
 *   1. ZERO VETO — the frontier only ATTACHES a band; the deterministic
 *      gates (CaptureQualityGate + falseLearningGate + ASI-02) remain the
 *      only writers of `decision`/`status`. The advisory is a SIGNAL logged
 *      on the payload, never a promotion/blocking channel. Enforced by
 *      contract: `judge()` refuses to accept a `decision` field on its
 *      output; it always returns a band + rationale + provenance.
 *   2. PHYSICAL author≠judge — the class refuses to grade a distillation
 *      authored by the same engine (author_engine_id == judge_engine_id ⇒
 *      status=refused_same_engine, band=null). This is the invariant that
 *      makes "a second opinion" meaningful.
 *   3. Ex-post calibration reuses CalibrationBandClassifier (ASI-15 pattern);
 *      each declared_band is compared against real lift (MAXJ-05) to
 *      produce {n, lift_mean_lift}. Insufficient samples ⇒
 *      insufficient_sample (never fabricated calibration).
 *   4. DEATH CRITERION written on the payload: after N lessons the
 *      advisory `high` band must predict lift ABOVE the `low` band; if it
 *      does not, `death_criterion.satisfied=false` and the digest surfaces
 *      the recommendation to remove the judge. Refuted-with-registration
 *      is a valid landing state.
 *
 * Purely computational. Zero I/O. Provider-safe by construction — the
 * frontier engine's rationale is a SHORT PROVIDER-SAFE HANDLE (`rationale_
 * ref`), not raw text — the caller stores the raw text under its own
 * provider-safety gates if it must, but this class never traffics in it.
 */
final class FrontierJudgeAdvisoryService
{
    public const SCHEMA_VERSION = 'atlas.compounding.frontier_judge_advisory.v1';

    /** §1690 pinned minimum lessons before the death criterion CAN evaluate. */
    public const DEATH_CRITERION_MIN_N = 30;

    /** §1690 pinned minimum lift separation `high band - low band` for the
     * judge to be considered informative. */
    public const DEATH_CRITERION_MIN_LIFT_SEPARATION = 0.0;

    /**
     * Grade a distillation. author_engine_id / judge_engine_id are REQUIRED
     * — a missing id refuses the grading with a named refusal reason.
     *
     * @param  array<string,mixed>  $signal  {
     *   probability: float 0.0-1.0 (the frontier engine's confidence that
     *     the lesson is high-quality; caller-supplied; the DECISION
     *     stays with the deterministic gates).
     *   author_engine_id: string (the engine that AUTHORED the
     *     distillation — usually the deterministic template on default-OFF
     *     ASI-09).
     *   judge_engine_id: string (the frontier engine grading — MUST be
     *     different from author_engine_id).
     *   rationale_ref: string (short opaque handle to a provider-safe
     *     rationale; the raw text never enters this class).
     * }
     * @return array<string,mixed>
     */
    public function judge(array $signal): array
    {
        $authorId = trim((string) ($signal['author_engine_id'] ?? ''));
        $judgeId = trim((string) ($signal['judge_engine_id'] ?? ''));
        if ($authorId === '' || $judgeId === '') {
            return $this->refused('missing_engine_id');
        }
        if ($authorId === $judgeId) {
            return $this->refused('same_engine_authored_and_judged');
        }
        $probability = $signal['probability'] ?? null;
        if ($probability === null || ! is_numeric($probability)) {
            return $this->refused('probability_missing');
        }
        $band = (new CalibrationBandClassifier)->classify((float) $probability);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'graded',
            'advisory_quality_band' => $band['band'],
            'probability' => $band['probability'],
            'author_engine_id' => $authorId,
            'judge_engine_id' => $judgeId,
            'rationale_ref' => (string) ($signal['rationale_ref'] ?? ''),
            'source' => [
                'read_only' => true,
                'veto' => false,
                'writes_decision' => false,
                'writes_status' => false,
            ],
        ];
    }

    /**
     * Ex-post calibration curve: declared_band × realized lift.
     *
     * @param  list<array{advisory_quality_band:string,lift:float|int}>  $samples
     *   samples emitted by the judge whose realized `lift` (MAXJ-05 delta)
     *   has been measured post-hoc.
     * @return array<string,mixed>
     */
    public function calibration(array $samples): array
    {
        $buckets = ['low' => [], 'sweet' => [], 'high' => []];
        foreach ($samples as $row) {
            $band = (string) ($row['advisory_quality_band'] ?? '');
            if (! isset($buckets[$band])) {
                continue;
            }
            if (! isset($row['lift']) || ! is_numeric($row['lift'])) {
                continue;
            }
            $buckets[$band][] = (float) $row['lift'];
        }

        $curve = [];
        $n = 0;
        foreach ($buckets as $band => $lifts) {
            $count = count($lifts);
            $mean = $count === 0 ? null : array_sum($lifts) / $count;
            $curve[$band] = [
                'n' => $count,
                'mean_lift' => $mean === null ? null : round($mean, 4),
                'basis' => $count === 0 ? 'insufficient_sample' : 'measured',
            ];
            $n += $count;
        }

        $deathCriterion = $this->deathCriterion($curve, $n);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $n === 0 ? 'insufficient_signal' : 'ok',
            'n_total' => $n,
            'curve' => $curve,
            'death_criterion' => $deathCriterion,
            'source' => [
                'read_only' => true,
                'veto' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function refused(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'refused',
            'refusal_reason' => $reason,
            'advisory_quality_band' => null,
            'probability' => null,
            'source' => [
                'read_only' => true,
                'veto' => false,
                'writes_decision' => false,
                'writes_status' => false,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $curve
     * @return array<string,mixed>
     */
    private function deathCriterion(array $curve, int $nTotal): array
    {
        $high = $curve['high']['mean_lift'] ?? null;
        $low = $curve['low']['mean_lift'] ?? null;

        if ($nTotal < self::DEATH_CRITERION_MIN_N || $high === null || $low === null) {
            return [
                'evaluable' => false,
                'satisfied' => null,
                'min_n' => self::DEATH_CRITERION_MIN_N,
                'reason' => 'insufficient_sample_or_bands_empty',
            ];
        }
        $separation = round($high - $low, 4);
        $satisfied = $separation > self::DEATH_CRITERION_MIN_LIFT_SEPARATION;

        return [
            'evaluable' => true,
            'satisfied' => $satisfied,
            'lift_high' => $high,
            'lift_low' => $low,
            'lift_separation' => $separation,
            'min_separation' => self::DEATH_CRITERION_MIN_LIFT_SEPARATION,
            'recommendation' => $satisfied ? 'keep' : 'remove_judge',
        ];
    }
}
