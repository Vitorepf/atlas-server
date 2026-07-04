<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure calibrator. Given replay rows with value_score, risk_score,
 * duplicate_score, template_similarity and final outcome, picks the
 * tightest conservative thresholds that admit ALL good rows while
 * blocking as many poison/give_back rows as possible.
 *
 * Admission rule:
 *   value_score >= threshold_value_score       (higher = more valuable)
 *   risk_score  <= threshold_risk_score        (lower = safer)
 *   duplicate_score <= threshold_duplicate_score
 *   template_similarity <= threshold_template_similarity
 *
 * Threshold derivation (per dimension, from good rows only):
 *   GE dims (value_score): threshold = min(good values),   default 0.0
 *   LE dims (others):      threshold = max(good values),   default 1.0
 *
 * Confusion matrix:
 *   TP = good rows admitted   FP = bad rows admitted
 *   FN = good rows rejected   TN = bad rows rejected
 *
 * Pure: no I/O, no DB, no providers. Deterministic.
 *
 * calibrateAdaptive() (AC2/AC3/AC4) is a SEPARATE, additive calibration path over rate trends
 * (give_back/poison/weak-proof rate, worker drain) rather than a static replay-batch min/max
 * derivation. It raises admission strictness whenever any of the three risk rates is elevated;
 * it only ever LOWERS strictness for a frontier that is simultaneously high-value, under-served
 * (few historical samples) and backed by healthy worker outcomes — and never lowers while any
 * raise condition is active. calibrate() and its output shape are completely untouched.
 */
final class AtlasTaskFabricAdmissionThresholdCalibrator
{
    public const SCHEMA = 'atlas.task_fabric.admission_threshold_calibrator.v1';

    public const SCHEMA_ADAPTIVE = 'atlas.task_fabric.admission_threshold_calibrator.adaptive.v1';

    private const GOOD_OUTCOMES = ['success', 'high_impact', 'green_commit'];
    private const BAD_OUTCOMES  = ['give_back', 'poison', 'template_farm', 'duplicate'];

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    public const DIRECTION_RAISED = 'raised';

    public const DIRECTION_LOWERED = 'lowered';

    public const DIRECTION_UNCHANGED = 'unchanged';

    private const GIVE_BACK_RATE_RAISE_FLOOR = 0.20;

    private const POISON_RATE_RAISE_FLOOR = 0.10;

    private const WEAK_PROOF_RATE_RAISE_FLOOR = 0.25;

    private const RAISE_STEP = 0.05;

    private const LOWER_STEP = 0.05;

    private const LOWER_ALLOWED_MIN_VALUE_SCORE = 0.7;

    private const LOWER_ALLOWED_MAX_SAMPLE_COUNT = 5;

    private const LOWER_ALLOWED_MAX_GIVE_BACK_RATE = 0.05;

    private const LOWER_ALLOWED_MAX_POISON_RATE = 0.02;

    private const CONFIDENT_SAMPLE_FLOOR = 20;

    private const MEDIUM_SAMPLE_FLOOR = 5;

    /**
     * @param  array{replay_rows?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function calibrate(array $input): array
    {
        $rows = is_array($input['replay_rows'] ?? null) ? $input['replay_rows'] : [];

        $goodRows = array_values(array_filter(
            $rows,
            fn (array $r): bool => in_array((string) ($r['outcome'] ?? ''), self::GOOD_OUTCOMES, true),
        ));

        $thresholds = [
            'value_score'         => $this->thresholdGe(array_column($goodRows, 'value_score')),
            'risk_score'          => $this->thresholdLe(array_column($goodRows, 'risk_score')),
            'duplicate_score'     => $this->thresholdLe(array_column($goodRows, 'duplicate_score')),
            'template_similarity' => $this->thresholdLe(array_column($goodRows, 'template_similarity')),
        ];

        return [
            'schema_version'            => self::SCHEMA,
            'threshold_set'             => $thresholds,
            'expected_confusion_matrix' => $this->confusionMatrix($rows, $thresholds),
        ];
    }

    /** Admit if value >= threshold. Threshold = min(good values). */
    private function thresholdGe(array $vals): float
    {
        $vals = array_map('floatval', array_filter($vals, 'is_numeric'));

        return $vals !== [] ? round((float) min($vals), 4) : 0.0;
    }

    /** Admit if value <= threshold. Threshold = max(good values). */
    private function thresholdLe(array $vals): float
    {
        $vals = array_map('floatval', array_filter($vals, 'is_numeric'));

        return $vals !== [] ? round((float) max($vals), 4) : 1.0;
    }

    private function isAdmitted(array $row, array $thresholds): bool
    {
        return (float) ($row['value_score']         ?? 0.0) >= $thresholds['value_score']
            && (float) ($row['risk_score']           ?? 0.0) <= $thresholds['risk_score']
            && (float) ($row['duplicate_score']      ?? 0.0) <= $thresholds['duplicate_score']
            && (float) ($row['template_similarity']  ?? 0.0) <= $thresholds['template_similarity'];
    }

    /** @return array{true_positive:int,false_positive:int,false_negative:int,true_negative:int} */
    private function confusionMatrix(array $rows, array $thresholds): array
    {
        $tp = $fp = $fn = $tn = 0;

        foreach ($rows as $row) {
            $outcome  = (string) ($row['outcome'] ?? '');
            $isGood   = in_array($outcome, self::GOOD_OUTCOMES, true);
            $admitted = $this->isAdmitted($row, $thresholds);

            if ($isGood &&  $admitted) { $tp++; }
            if (! $isGood &&  $admitted) { $fp++; }
            if ($isGood && ! $admitted) { $fn++; }
            if (! $isGood && ! $admitted) { $tn++; }
        }

        return [
            'true_positive'  => $tp,
            'false_positive' => $fp,
            'false_negative' => $fn,
            'true_negative'  => $tn,
        ];
    }

    /**
     * Adaptive threshold-movement path (AC2/AC3/AC4). Computes a signed strictness delta from
     * rate trends and, only when eligible, applies it to raise or lower per-dimension thresholds.
     *
     * Raise (AC2): any of give_back_rate/poison_rate/weak_proof_rate at or above its floor makes
     * the calibrator MORE conservative (value_score threshold up, risk/duplicate/template ceilings
     * down), scaled by how many rates are elevated.
     *
     * Lower (AC3): only permitted when NOT raising AND the frontier is simultaneously high-value,
     * under-served (few historical samples) and backed by healthy worker outcomes (low give_back/
     * poison rate) — a low-value or well-served or unhealthy frontier never gets easier admission.
     *
     * @param  array{
     *     give_back_rate?: float, poison_rate?: float, weak_proof_rate?: float, worker_drain_rate?: float,
     *     evidence_count?: int, frontier_value_score?: float, frontier_sample_count?: int
     * }  $input
     * @return array{schema_version:string, direction:string, threshold_deltas:array<string,float>, evidence_count:int, confidence:string, raise_reasons:list<string>, lower_eligible:bool}
     */
    public function calibrateAdaptive(array $input): array
    {
        $giveBackRate = max(0.0, min(1.0, (float) ($input['give_back_rate'] ?? 0.0)));
        $poisonRate = max(0.0, min(1.0, (float) ($input['poison_rate'] ?? 0.0)));
        $weakProofRate = max(0.0, min(1.0, (float) ($input['weak_proof_rate'] ?? 0.0)));
        $evidenceCount = max(0, (int) ($input['evidence_count'] ?? 0));
        $frontierValueScore = max(0.0, min(1.0, (float) ($input['frontier_value_score'] ?? 0.0)));
        $frontierSampleCount = max(0, (int) ($input['frontier_sample_count'] ?? 0));

        $raiseReasons = [];
        if ($giveBackRate >= self::GIVE_BACK_RATE_RAISE_FLOOR) {
            $raiseReasons[] = 'give_back_rate_elevated:'.$giveBackRate;
        }
        if ($poisonRate >= self::POISON_RATE_RAISE_FLOOR) {
            $raiseReasons[] = 'poison_rate_elevated:'.$poisonRate;
        }
        if ($weakProofRate >= self::WEAK_PROOF_RATE_RAISE_FLOOR) {
            $raiseReasons[] = 'weak_proof_rate_elevated:'.$weakProofRate;
        }
        $shouldRaise = $raiseReasons !== [];

        $isHighValue = $frontierValueScore >= self::LOWER_ALLOWED_MIN_VALUE_SCORE;
        $isUnderServed = $frontierSampleCount <= self::LOWER_ALLOWED_MAX_SAMPLE_COUNT;
        $isHealthyOutcomes = $giveBackRate <= self::LOWER_ALLOWED_MAX_GIVE_BACK_RATE
            && $poisonRate <= self::LOWER_ALLOWED_MAX_POISON_RATE;
        $lowerEligible = ! $shouldRaise && $isHighValue && $isUnderServed && $isHealthyOutcomes;

        if ($shouldRaise) {
            $direction = self::DIRECTION_RAISED;
            $strictnessDelta = self::RAISE_STEP * min(count($raiseReasons), 3);
        } elseif ($lowerEligible) {
            $direction = self::DIRECTION_LOWERED;
            $strictnessDelta = -self::LOWER_STEP;
        } else {
            $direction = self::DIRECTION_UNCHANGED;
            $strictnessDelta = 0.0;
        }

        $confidence = match (true) {
            $evidenceCount >= self::CONFIDENT_SAMPLE_FLOOR => self::CONFIDENCE_HIGH,
            $evidenceCount >= self::MEDIUM_SAMPLE_FLOOR => self::CONFIDENCE_MEDIUM,
            default => self::CONFIDENCE_LOW,
        };

        return [
            'schema_version' => self::SCHEMA_ADAPTIVE,
            'direction' => $direction,
            'threshold_deltas' => [
                'value_score' => round($strictnessDelta, 4),
                'risk_score' => round(-$strictnessDelta, 4),
                'duplicate_score' => round(-$strictnessDelta, 4),
                'template_similarity' => round(-$strictnessDelta, 4),
            ],
            'family_thresholds' => [
                'value_score' => round($strictnessDelta, 4),
                'risk_score' => round(-$strictnessDelta, 4),
                'duplicate_score' => round(-$strictnessDelta, 4),
                'template_similarity' => round(-$strictnessDelta, 4),
            ],
            'evidence_count' => $evidenceCount,
            'confidence' => $confidence,
            'raise_reasons' => $raiseReasons,
            'lower_eligible' => $lowerEligible,
            'evidence_refs' => $raiseReasons,
        ];
    }
}
