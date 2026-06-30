<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure SLO ledger: computes quality service-level objective status for each
 * (model_tier × scaffold_variant × task_class) segment from supplied outcome rows.
 *
 * Segments with sample_size < MIN_SAMPLE are marked 'insufficient_evidence' and
 * must not be treated as green — they appear in insufficient_segments, not in
 * failing_segments or green slo_rows.
 *
 * Five SLO dimensions and their thresholds:
 *   commit_success_rate  ≥ 0.70
 *   give_back_rate       ≤ 0.20
 *   value_proof_rate     ≥ 0.70
 *   duplicate_rate       ≤ 0.10
 *   evidence_strength    ≥ 0.70
 *
 * slo_rows:   full status per segment (green / red / insufficient_evidence).
 * failing_segments: segments with status='red'.
 * insufficient_segments: segments with sample too small.
 * recommended_routing_adjustments: one adjustment per red segment.
 */
final class AtlasExternalBrainModelQualitySloLedger
{
    public const SCHEMA = 'atlas.external_brain.model_quality_slo_ledger.v1';

    public const MIN_SAMPLE = 10;

    public const SLO_THRESHOLDS = [
        'commit_success_rate' => ['op' => '>=', 'value' => 0.70],
        'give_back_rate' => ['op' => '<=', 'value' => 0.20],
        'value_proof_rate' => ['op' => '>=', 'value' => 0.70],
        'duplicate_rate' => ['op' => '<=', 'value' => 0.10],
        'evidence_strength' => ['op' => '>=', 'value' => 0.70],
    ];

    /**
     * @param  array<string,mixed>  $input  outcome_rows list
     * @return array<string,mixed>
     */
    public function compute(array $input): array
    {
        $rows = is_array($input['outcome_rows'] ?? null) ? $input['outcome_rows'] : [];

        $sloRows = [];
        $failingSegments = [];
        $insufficientSegments = [];
        $routingAdjustments = [];

        foreach ($rows as $row) {
            $tier = (string) ($row['model_tier'] ?? '');
            $variant = (string) ($row['scaffold_variant'] ?? '');
            $class = (string) ($row['task_class'] ?? '');
            $sample = max(0, (int) ($row['sample_size'] ?? 0));
            $segmentKey = "{$tier}:{$variant}:{$class}";

            if ($sample < self::MIN_SAMPLE) {
                $insufficientSegments[] = [
                    'segment' => $segmentKey,
                    'model_tier' => $tier,
                    'scaffold_variant' => $variant,
                    'task_class' => $class,
                    'sample_size' => $sample,
                ];
                $sloRows[] = [
                    'segment' => $segmentKey,
                    'model_tier' => $tier,
                    'scaffold_variant' => $variant,
                    'task_class' => $class,
                    'status' => 'insufficient_evidence',
                    'sample_size' => $sample,
                    'failing_slos' => [],
                ];

                continue;
            }

            $failingSlos = [];
            foreach (self::SLO_THRESHOLDS as $metric => $rule) {
                $actual = (float) ($row[$metric] ?? 0.0);
                $passes = $rule['op'] === '>='
                    ? $actual >= $rule['value']
                    : $actual <= $rule['value'];
                if (! $passes) {
                    $failingSlos[] = $metric;
                }
            }

            $status = $failingSlos === [] ? 'green' : 'red';
            $sloRows[] = [
                'segment' => $segmentKey,
                'model_tier' => $tier,
                'scaffold_variant' => $variant,
                'task_class' => $class,
                'status' => $status,
                'sample_size' => $sample,
                'failing_slos' => $failingSlos,
            ];

            if ($status === 'red') {
                $failingSegments[] = $segmentKey;
                $routingAdjustments[] = [
                    'segment' => $segmentKey,
                    'adjustment' => count($failingSlos) >= 3 ? 'downgrade_tier' : 'add_extra_validation',
                    'failing_slos' => $failingSlos,
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'slo_rows' => $sloRows,
            'failing_segments' => $failingSegments,
            'insufficient_segments' => $insufficientSegments,
            'recommended_routing_adjustments' => $routingAdjustments,
        ];
    }
}
