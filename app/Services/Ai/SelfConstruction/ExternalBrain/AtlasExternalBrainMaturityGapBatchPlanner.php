<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner that merges gap-index findings and drift findings into a
 * coherent, ranked batch of worker-ready tasks.
 *
 * Each batch item carries:
 *   - category (proof_gap_closure | missing_next_leverage_recovery | other_drift_repair)
 *   - resolution_approach (task_family or repair_action)
 *   - leverage / compound_leverage (leverage + 0.2 bonus per unlock)
 *   - unlock_count, dependency_blockers
 *
 * Ranking: compound_leverage DESC, then category priority
 *   (proof_gap_closure > missing_next_leverage_recovery > other_drift_repair).
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainMaturityGapBatchPlanner
{
    public const SCHEMA = 'atlas.external_brain.maturity_gap_batch_planner.v1';

    private const CATEGORY_PRIORITY = [
        'proof_gap_closure' => 1,
        'missing_next_leverage_recovery' => 2,
        'contradictory_stale_repair' => 3,
        'other_drift_repair' => 4,
    ];

    /**
     * @param array<string,mixed> $gapIndex  gap index output (AtlasExternalBrainMaturityGapIndex)
     * @param array<string,mixed> $drift     drift output (AtlasExternalBrainCapabilityMapDriftDetector)
     * @return array{schema:string, batch:list<array<string,mixed>>}
     */
    public function plan(array $gapIndex, array $drift): array
    {
        $batch = [];

        // 1. Gap-index items → proof_gap_closure
        foreach ((array) ($gapIndex['gaps'] ?? []) as $gap) {
            $leverage = (float) ($gap['leverage'] ?? 0.0);
            $unlocks = (array) ($gap['unlocks'] ?? []);
            $blockedBy = (array) ($gap['blocked_by'] ?? []);
            $unlockCount = count($unlocks);
            $compoundLeverage = round($leverage + ($unlockCount * 0.1), 2);

            $batch[] = [
                'id' => (string) ($gap['dimension'] ?? 'unknown'),
                'category' => 'proof_gap_closure',
                'resolution_approach' => (string) ($gap['next_best_task_family'] ?? ''),
                'leverage' => $leverage,
                'unlock_count' => $unlockCount,
                'dependency_blockers' => $blockedBy,
                'compound_leverage' => $compoundLeverage,
            ];
        }

        // 2. Drift findings → missing_next_leverage_recovery or other_drift_repair
        foreach ((array) ($drift['findings'] ?? []) as $finding) {
            $driftType = (string) ($finding['drift_type'] ?? '');
            $unlocks = (array) ($finding['unlocks'] ?? []);
            $blockedBy = (array) ($finding['blocked_by'] ?? []);
            $unlockCount = count($unlocks);

            if ($driftType === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE) {
                $category = 'missing_next_leverage_recovery';
            } elseif ($driftType === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY) {
                $category = 'contradictory_stale_repair';
            } else {
                $category = 'other_drift_repair';
            }

            $leverage = match ((string) ($finding['impact'] ?? 'low')) {
                'high' => 0.8,
                'medium' => 0.5,
                default => 0.3,
            };
            $compoundLeverage = round($leverage + ($unlockCount * 0.1), 2);

            $batch[] = [
                'id' => (string) ($finding['area_id'] ?? 'unknown'),
                'category' => $category,
                'resolution_approach' => (string) ($finding['repair_action'] ?? ''),
                'leverage' => $leverage,
                'unlock_count' => $unlockCount,
                'dependency_blockers' => $blockedBy,
                'compound_leverage' => $compoundLeverage,
            ];
        }

        // Sort: compound_leverage DESC, then category priority ASC
        usort($batch, static function (array $a, array $b): int {
            $cmp = $b['compound_leverage'] <=> $a['compound_leverage'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $pa = self::CATEGORY_PRIORITY[$a['category']] ?? 99;
            $pb = self::CATEGORY_PRIORITY[$b['category']] ?? 99;
            return $pa <=> $pb;
        });

        return [
            'schema' => self::SCHEMA,
            'batch' => $batch,
        ];
    }
}
