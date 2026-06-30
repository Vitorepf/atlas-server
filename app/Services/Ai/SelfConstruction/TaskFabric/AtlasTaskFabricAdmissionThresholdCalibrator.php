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
 */
final class AtlasTaskFabricAdmissionThresholdCalibrator
{
    public const SCHEMA = 'atlas.task_fabric.admission_threshold_calibrator.v1';

    private const GOOD_OUTCOMES = ['success', 'high_impact', 'green_commit'];
    private const BAD_OUTCOMES  = ['give_back', 'poison', 'template_farm', 'duplicate'];

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
}
