<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure budget allocator. Given queue health signals, reserves a bounded share
 * of the next task batch for consolidation (simplification) and unblock tasks,
 * leaving the remainder for implementation origination.
 *
 * Base ratios:
 *   consolidation: 0.15 (15%)   unblock: 0.10 (10%)
 *
 * Upward pressure (each trigger adds +0.10 to the relevant bucket):
 *   consolidation_up: duplicate_pressure > 0.3 | orphaned_capability > 20 | value_proof < 0.3
 *   unblock_up      : blocked_pressure > 0.3
 *
 * Downward relief (each trigger subtracts from consolidation):
 *   consolidation_down: queue_depth < 10 (-0.10) | value_proof > 0.7 (-0.05)
 *
 * Hard caps after adjustment: consolidation in [0.05, 0.40], unblock in [0.0, 0.30].
 * implementation = batch_size − consolidation_budget − unblock_budget (floor 0).
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasTaskFabricConsolidationBudgetAllocator
{
    public const SCHEMA = 'atlas.task_fabric.consolidation_budget_allocator.v1';

    private const BASE_CONSOLIDATION    = 0.15;
    private const BASE_UNBLOCK          = 0.10;
    private const MIN_CONSOLIDATION     = 0.05;
    private const MAX_CONSOLIDATION     = 0.40;
    private const MAX_UNBLOCK           = 0.30;
    private const HIGH_DUPLICATE        = 0.3;
    private const HIGH_ORPHAN           = 20;
    private const WEAK_VALUE_PROOF      = 0.3;
    private const STRONG_VALUE_PROOF    = 0.7;
    private const HIGH_BLOCKED_PRESSURE = 0.3;
    private const THIN_QUEUE_DEPTH      = 10;
    private const STEP                  = 0.10;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function allocate(array $facts): array
    {
        $batchSize     = max(1, (int) ($facts['batch_size'] ?? 10));
        $queueDepth    = (int) ($facts['queue_depth'] ?? 0);
        $dupPressure   = (float) ($facts['duplicate_pressure'] ?? 0.0);
        $orphanCount   = (int) ($facts['orphaned_capability_count'] ?? 0);
        $blockedPress  = (float) ($facts['blocked_pressure'] ?? 0.0);
        $valueDensity  = (float) ($facts['value_proof_density'] ?? 1.0);

        $consolidationRatio = self::BASE_CONSOLIDATION;
        $unblockRatio       = self::BASE_UNBLOCK;
        $triggers           = [];

        // Upward pressure on consolidation.
        if ($dupPressure > self::HIGH_DUPLICATE) {
            $consolidationRatio += self::STEP;
            $triggers[] = 'high_duplicate_pressure';
        }
        if ($orphanCount > self::HIGH_ORPHAN) {
            $consolidationRatio += self::STEP;
            $triggers[] = 'high_orphan_count';
        }
        if ($valueDensity < self::WEAK_VALUE_PROOF) {
            $consolidationRatio += self::STEP;
            $triggers[] = 'weak_value_proof';
        }

        // Downward relief on consolidation.
        if ($queueDepth < self::THIN_QUEUE_DEPTH) {
            $consolidationRatio -= self::STEP;
            $triggers[] = 'thin_queue_relief';
        }
        if ($valueDensity > self::STRONG_VALUE_PROOF) {
            $consolidationRatio -= 0.05;
            $triggers[] = 'strong_value_proof_relief';
        }

        // Upward pressure on unblock.
        if ($blockedPress > self::HIGH_BLOCKED_PRESSURE) {
            $unblockRatio += self::STEP;
            $triggers[] = 'high_blocked_pressure';
        }

        // Clamp and round ratios (avoid float drift like 0.15 − 0.05 = 0.09999...).
        $consolidationRatio = round(max(self::MIN_CONSOLIDATION, min(self::MAX_CONSOLIDATION, $consolidationRatio)), 4);
        $unblockRatio       = round(max(0.0, min(self::MAX_UNBLOCK, $unblockRatio)), 4);

        // Allocate task slots.
        $consolidationBudget = (int) floor($batchSize * $consolidationRatio);
        $unblockBudget       = (int) floor($batchSize * $unblockRatio);
        $implementationBudget = max(0, $batchSize - $consolidationBudget - $unblockBudget);

        return [
            'schema_version'        => self::SCHEMA,
            'consolidation_budget'  => $consolidationBudget,
            'unblock_budget'        => $unblockBudget,
            'implementation_budget' => $implementationBudget,
            'total_budget'          => $batchSize,
            'consolidation_ratio'   => round($consolidationRatio, 3),
            'unblock_ratio'         => round($unblockRatio, 3),
            'active_triggers'       => $triggers,
            'diagnostics' => [
                'queue_depth'               => $queueDepth,
                'duplicate_pressure'        => $dupPressure,
                'orphaned_capability_count' => $orphanCount,
                'blocked_pressure'          => $blockedPress,
                'value_proof_density'       => $valueDensity,
                'batch_size'                => $batchSize,
            ],
        ];
    }
}
