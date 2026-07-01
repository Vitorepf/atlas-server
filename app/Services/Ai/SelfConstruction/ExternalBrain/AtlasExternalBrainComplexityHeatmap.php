<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, read-only fact scorer. Turns per-organ structural signals into a deterministic complexity
 * heatmap — a ranked list of hotspots (highest risk first) that planners (e.g.
 * {@see AtlasExternalBrainComplexityDebtBurnDownPlanner}, {@see AtlasExternalBrainArchitectureCompressionPlanner})
 * consume to target refactor work where structural debt is actually concentrated.
 *
 * INPUT per organ:
 *   { organ_id, line_count?:int, churn?:int, duplication?:float (0.0-1.0),
 *     coverage?:float (0.0-1.0), blast_radius?:int }
 *
 * RISK SCORE (0.0-1.0, higher = hotter, saturating per-signal so no single huge value dominates):
 *   risk_score = line_count_score*0.25 + churn_score*0.25 + duplication*0.20
 *              + (1 - coverage)*0.15 + blast_radius_score*0.15
 *   where *_score = min(1.0, raw / saturation_constant)
 *
 * OUTPUT: { schema, hotspots: list<{organ_id, risk_score, evidence:{line_count, churn,
 *   duplication, coverage, blast_radius}}> } sorted risk_score DESC, organ_id ASC (tiebreak).
 *
 * INVARIANT: this class NEVER recommends an edit, action, or verdict — it only returns ranked
 * facts. Any decision (delete/merge/simplify/keep) belongs to a downstream planner.
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainComplexityHeatmap
{
    public const SCHEMA = 'atlas.external_brain.complexity_heatmap.v1';

    private const WEIGHT_LINE_COUNT   = 0.25;
    private const WEIGHT_CHURN        = 0.25;
    private const WEIGHT_DUPLICATION  = 0.20;
    private const WEIGHT_COVERAGE_GAP = 0.15;
    private const WEIGHT_BLAST_RADIUS = 0.15;

    private const LINE_COUNT_SATURATION   = 1000;
    private const CHURN_SATURATION        = 50;
    private const BLAST_RADIUS_SATURATION = 20;

    /**
     * @param  list<array<string,mixed>>  $organs
     * @return array{schema:string, hotspots:list<array<string,mixed>>}
     */
    public function scan(array $organs): array
    {
        $hotspots = [];

        foreach ($organs as $organ) {
            if (! is_array($organ) || ! isset($organ['organ_id'])) {
                continue;
            }

            $organId     = (string) $organ['organ_id'];
            $lineCount   = max(0, (int) ($organ['line_count'] ?? 0));
            $churn       = max(0, (int) ($organ['churn'] ?? 0));
            $duplication = max(0.0, min(1.0, (float) ($organ['duplication'] ?? 0.0)));
            $coverage    = max(0.0, min(1.0, (float) ($organ['coverage'] ?? 0.0)));
            $blastRadius = max(0, (int) ($organ['blast_radius'] ?? 0));

            $lineScore  = min(1.0, $lineCount / self::LINE_COUNT_SATURATION);
            $churnScore = min(1.0, $churn / self::CHURN_SATURATION);
            $blastScore = min(1.0, $blastRadius / self::BLAST_RADIUS_SATURATION);
            $coverageGapScore = 1.0 - $coverage;

            $riskScore = round(
                $lineScore * self::WEIGHT_LINE_COUNT
                + $churnScore * self::WEIGHT_CHURN
                + $duplication * self::WEIGHT_DUPLICATION
                + $coverageGapScore * self::WEIGHT_COVERAGE_GAP
                + $blastScore * self::WEIGHT_BLAST_RADIUS,
                4,
            );

            $hotspots[] = [
                'organ_id' => $organId,
                'risk_score' => $riskScore,
                'evidence' => [
                    'line_count' => $lineCount,
                    'churn' => $churn,
                    'duplication' => $duplication,
                    'coverage' => $coverage,
                    'blast_radius' => $blastRadius,
                ],
            ];
        }

        usort($hotspots, static function (array $a, array $b): int {
            $r = $b['risk_score'] <=> $a['risk_score'];

            return $r !== 0 ? $r : strcmp($a['organ_id'], $b['organ_id']);
        });

        return [
            'schema' => self::SCHEMA,
            'hotspots' => $hotspots,
        ];
    }
}
