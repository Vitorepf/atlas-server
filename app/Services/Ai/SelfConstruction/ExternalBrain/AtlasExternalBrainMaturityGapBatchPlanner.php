<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Merges {@see AtlasExternalBrainMaturityGapIndex} and
 * {@see AtlasExternalBrainCapabilityMapDriftDetector} output into one ranked
 * batch plan with an explicit resolution_approach per item, instead of
 * leaving both reports as isolated observability packets.
 *
 * Priority order (lowest rank number wins ties within the same tier):
 *   0. proof_gap_closure           — maturity-gap dimensions needing proof
 *   1. contradictory_stale_repair  — drift findings of type contradictory/stale
 *   2. missing_next_leverage_recovery — drift findings of type missing_next_leverage
 *   3. other_drift_repair          — every other drift finding
 *
 * Within a tier, items sort by leverage/impact descending, then id ascending
 * (deterministic tie-break).
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainMaturityGapBatchPlanner
{
    public const SCHEMA = 'atlas.external_brain.maturity_gap_batch_planner.v1';

    private const CATEGORY_PROOF_GAP_CLOSURE = 'proof_gap_closure';

    private const CATEGORY_CONTRADICTORY_STALE_REPAIR = 'contradictory_stale_repair';

    private const CATEGORY_MISSING_NEXT_LEVERAGE_RECOVERY = 'missing_next_leverage_recovery';

    private const CATEGORY_OTHER_DRIFT_REPAIR = 'other_drift_repair';

    private const CATEGORY_RANK = [
        self::CATEGORY_PROOF_GAP_CLOSURE => 0,
        self::CATEGORY_CONTRADICTORY_STALE_REPAIR => 1,
        self::CATEGORY_MISSING_NEXT_LEVERAGE_RECOVERY => 2,
        self::CATEGORY_OTHER_DRIFT_REPAIR => 3,
    ];

    private const IMPACT_TO_SCORE = ['high' => 1.0, 'medium' => 0.6, 'low' => 0.3];

    /**
     * @param  array{gaps?: list<array<string,mixed>>}  $gapIndexResult
     * @param  array{findings?: list<array<string,mixed>>}  $driftResult
     * @return array{schema:string, batch:list<array<string,mixed>>}
     */
    public function plan(array $gapIndexResult, array $driftResult): array
    {
        $batch = [];

        foreach ((array) ($gapIndexResult['gaps'] ?? []) as $gap) {
            $batch[] = [
                'source' => 'maturity_gap',
                'id' => (string) ($gap['dimension'] ?? ''),
                'category' => self::CATEGORY_PROOF_GAP_CLOSURE,
                'resolution_approach' => (string) ($gap['next_best_task_family'] ?? $gap['suggested_task_family'] ?? ''),
                'leverage' => max(0.0, min(1.0, (float) ($gap['leverage'] ?? 0.0))),
                'finding' => $gap,
            ];
        }

        foreach ((array) ($driftResult['findings'] ?? []) as $finding) {
            $driftType = (string) ($finding['drift_type'] ?? '');
            $category = match ($driftType) {
                AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY,
                AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_STALE => self::CATEGORY_CONTRADICTORY_STALE_REPAIR,
                AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE => self::CATEGORY_MISSING_NEXT_LEVERAGE_RECOVERY,
                default => self::CATEGORY_OTHER_DRIFT_REPAIR,
            };

            $batch[] = [
                'source' => 'capability_drift',
                'id' => (string) ($finding['area_id'] ?? ''),
                'category' => $category,
                'resolution_approach' => (string) ($finding['repair_action'] ?? ''),
                'leverage' => self::IMPACT_TO_SCORE[(string) ($finding['impact'] ?? 'low')] ?? 0.0,
                'finding' => $finding,
            ];
        }

        usort($batch, static function (array $a, array $b): int {
            $rankDiff = self::CATEGORY_RANK[$a['category']] <=> self::CATEGORY_RANK[$b['category']];
            if ($rankDiff !== 0) {
                return $rankDiff;
            }
            $leverageDiff = $b['leverage'] <=> $a['leverage'];

            return $leverageDiff !== 0 ? $leverageDiff : strcmp($a['id'], $b['id']);
        });

        return [
            'schema' => self::SCHEMA,
            'batch' => $batch,
        ];
    }
}
