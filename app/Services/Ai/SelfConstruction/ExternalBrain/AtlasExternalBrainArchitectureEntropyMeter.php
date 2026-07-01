<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, read-only fact scorer. Complements {@see AtlasExternalBrainComplexityHeatmap} (which scores
 * per-organ SIZE/churn hotness) by scoring per-area STRUCTURAL DISORDER — where architecture is
 * becoming chaotic even when individual files stay small. A large-but-clean module is not high
 * entropy; a small module scattered across many unrelated responsibilities, duplicated elsewhere,
 * wrapped in shallow pass-throughs, and passed between owners repeatedly IS.
 *
 * INPUT per area:
 *   { area_id, responsibility_count?:int, duplicate_purpose_count?:int, wrapper_count?:int,
 *     total_class_count?:int, ownership_change_count?:int }
 *
 * ENTROPY SCORE (0.0-1.0, higher = more chaotic, each signal saturating independently so no
 * single huge value dominates):
 *   entropy_score = responsibility_scatter_score*0.30 + duplicate_purpose_score*0.25
 *                 + wrapper_ratio*0.25 + ownership_drift_score*0.20
 *   where *_score = min(1.0, raw / saturation_constant), and wrapper_ratio = wrapper_count /
 *   max(1, total_class_count) (already a 0.0-1.0 ratio, no saturation needed).
 *
 * OUTPUT: { schema, areas: list<{area_id, entropy_score, factors:{responsibility_scatter,
 *   duplicate_purpose_count, wrapper_ratio, ownership_drift}}> } sorted entropy_score DESC,
 *   area_id ASC (tiebreak) — highest-entropy areas first.
 *
 * INVARIANT: this class NEVER recommends an edit, action, or verdict — it only returns ranked
 * facts. Any decision (merge/split/reassign ownership) belongs to a downstream planner.
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainArchitectureEntropyMeter
{
    public const SCHEMA = 'atlas.external_brain.architecture_entropy_meter.v1';

    private const WEIGHT_RESPONSIBILITY_SCATTER = 0.30;
    private const WEIGHT_DUPLICATE_PURPOSE       = 0.25;
    private const WEIGHT_WRAPPER_RATIO           = 0.25;
    private const WEIGHT_OWNERSHIP_DRIFT         = 0.20;

    private const RESPONSIBILITY_SCATTER_SATURATION = 10;
    private const DUPLICATE_PURPOSE_SATURATION       = 5;
    private const OWNERSHIP_DRIFT_SATURATION         = 5;

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return array{schema:string, areas:list<array<string,mixed>>}
     */
    public function scan(array $areas): array
    {
        $scored = [];

        foreach ($areas as $area) {
            if (! is_array($area) || ! isset($area['area_id'])) {
                continue;
            }

            $areaId = (string) $area['area_id'];
            $responsibilityCount = max(0, (int) ($area['responsibility_count'] ?? 0));
            $duplicatePurposeCount = max(0, (int) ($area['duplicate_purpose_count'] ?? 0));
            $wrapperCount = max(0, (int) ($area['wrapper_count'] ?? 0));
            $totalClassCount = max(0, (int) ($area['total_class_count'] ?? 0));
            $ownershipChangeCount = max(0, (int) ($area['ownership_change_count'] ?? 0));

            $scatterScore = min(1.0, $responsibilityCount / self::RESPONSIBILITY_SCATTER_SATURATION);
            $duplicateScore = min(1.0, $duplicatePurposeCount / self::DUPLICATE_PURPOSE_SATURATION);
            $wrapperRatio = $totalClassCount > 0 ? min(1.0, $wrapperCount / $totalClassCount) : 0.0;
            $driftScore = min(1.0, $ownershipChangeCount / self::OWNERSHIP_DRIFT_SATURATION);

            $entropyScore = round(
                $scatterScore * self::WEIGHT_RESPONSIBILITY_SCATTER
                + $duplicateScore * self::WEIGHT_DUPLICATE_PURPOSE
                + $wrapperRatio * self::WEIGHT_WRAPPER_RATIO
                + $driftScore * self::WEIGHT_OWNERSHIP_DRIFT,
                4,
            );

            $scored[] = [
                'area_id' => $areaId,
                'entropy_score' => $entropyScore,
                'factors' => [
                    'responsibility_scatter' => $responsibilityCount,
                    'duplicate_purpose_count' => $duplicatePurposeCount,
                    'wrapper_ratio' => round($wrapperRatio, 4),
                    'ownership_drift' => $ownershipChangeCount,
                ],
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $r = $b['entropy_score'] <=> $a['entropy_score'];

            return $r !== 0 ? $r : strcmp($a['area_id'], $b['area_id']);
        });

        return [
            'schema' => self::SCHEMA,
            'areas' => $scored,
        ];
    }
}
