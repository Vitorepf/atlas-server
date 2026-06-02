<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Aucri;

final class ContextAblationDamageClassifier
{
    private const SCHEMA_VERSION = 'atlas.aucri.context_ablation_damage.v1';

    /**
     * Epsilon guards against float-noise false positives: metrics that are
     * equal within this tolerance are treated as undamaged.
     */
    private const EPSILON = 1e-9;

    /**
     * Fixed human reason emitted for each damaged dimension, keyed by dimension.
     */
    private const DIMENSION_REASONS = [
        'must_keep' => 'removed group dropped must-keep coverage below full — load-bearing context',
        'evidence_coverage' => 'removed group reduced evidence coverage — weakened proof support',
        'sufficiency' => 'removed group reduced sufficiency — answer completeness degraded',
        'quality' => 'removed group reduced quality score — output fidelity degraded',
    ];

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $ablated
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     damaged_dimensions: list<string>,
     *     reasons: list<string>,
     *     group_critical: bool
     * }
     */
    public function classify(array $baseline, array $ablated): array
    {
        $baselineQuality = $this->metric($baseline, 'quality_score');
        $baselineMustKeep = $this->metric($baseline, 'must_keep_coverage');
        $baselineEvidence = $this->metric($baseline, 'evidence_coverage');
        $baselineSufficiency = $this->metric($baseline, 'sufficiency');

        $ablatedQuality = $this->metric($ablated, 'quality_score');
        $ablatedMustKeep = $this->metric($ablated, 'must_keep_coverage');
        $ablatedEvidence = $this->metric($ablated, 'evidence_coverage');
        $ablatedSufficiency = $this->metric($ablated, 'sufficiency');

        $damagedDimensions = [];

        // R1: must-keep coverage must remain full; anything below 1.0 is damage.
        if ($ablatedMustKeep < 1.0) {
            $damagedDimensions[] = 'must_keep';
        }

        // R2: evidence coverage dropped beyond float noise.
        if ($ablatedEvidence < $baselineEvidence - self::EPSILON) {
            $damagedDimensions[] = 'evidence_coverage';
        }

        // R3: sufficiency dropped beyond float noise.
        if ($ablatedSufficiency < $baselineSufficiency - self::EPSILON) {
            $damagedDimensions[] = 'sufficiency';
        }

        // R4: quality dropped beyond float noise.
        if ($ablatedQuality < $baselineQuality - self::EPSILON) {
            $damagedDimensions[] = 'quality';
        }

        // R5: a group is critical if it damages must-keep or evidence coverage.
        $groupCritical = in_array('must_keep', $damagedDimensions, true)
            || in_array('evidence_coverage', $damagedDimensions, true);

        // R6: verdict derives from criticality then from whether any damage exists.
        if ($groupCritical) {
            $verdict = 'group_is_critical_keep';
        } elseif ($damagedDimensions === []) {
            $verdict = 'group_is_removable';
        } else {
            $verdict = 'group_is_soft_loss';
        }

        // R7: one fixed human reason per damaged dimension, in rule order.
        $reasons = [];
        foreach ($damagedDimensions as $dimension) {
            $reasons[] = self::DIMENSION_REASONS[$dimension];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'damaged_dimensions' => $damagedDimensions,
            'reasons' => $reasons,
            'group_critical' => $groupCritical,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function metric(array $payload, string $key): float
    {
        return (float) ($payload[$key] ?? 0.0);
    }
}
