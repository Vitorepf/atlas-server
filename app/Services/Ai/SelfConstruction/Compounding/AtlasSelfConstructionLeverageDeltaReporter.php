<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Compounding;

/**
 * Pure FACTS-only delta reporter. Compares BEFORE vs AFTER FACT snapshots across 5 named dimensions:
 *
 *   - capability_coverage    (int: count of canonical capabilities the system can demonstrate)
 *   - autonomous_ownership   (int: count of steady-state organs owned by atlas_native)
 *   - task_waste             (int: rework/give_back/poison count — LOWER is better)
 *   - queue_health           (int: ready packets minus blocked packets)
 *   - verification_strength  (int: gate count + frozen mutop count)
 *
 * Output: {schema_version, deltas:array<name,int>, real_leverage:bool, proxy_wins:list<string>,
 *           flagged_proxy:bool}
 *
 * Proxy wins are flagged when task_count grew (after.task_count > before.task_count) but capability
 * AND verification did NOT grow — the classic vanity throughput pattern.
 */
final class AtlasSelfConstructionLeverageDeltaReporter
{
    public const SCHEMA = 'atlas.self_construction.leverage_delta.v1';

    public const DIMENSIONS = [
        'capability_coverage',
        'autonomous_ownership',
        'task_waste',
        'queue_health',
        'verification_strength',
    ];

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,mixed>
     */
    public function report(array $before, array $after): array
    {
        $deltas = [];
        foreach (self::DIMENSIONS as $dim) {
            $deltas[$dim] = (int) ($after[$dim] ?? 0) - (int) ($before[$dim] ?? 0);
        }

        // Real leverage requires:
        //   capability_coverage increased (or held high)
        //   AND verification_strength didn't drop
        //   AND task_waste did NOT grow.
        $realLeverage = $deltas['capability_coverage'] > 0
            && $deltas['verification_strength'] >= 0
            && $deltas['task_waste'] <= 0;

        $proxyWins = [];
        $taskCountDelta = (int) ($after['task_count'] ?? 0) - (int) ($before['task_count'] ?? 0);
        if ($taskCountDelta > 0 && $deltas['capability_coverage'] <= 0 && $deltas['verification_strength'] <= 0) {
            $proxyWins[] = 'task_count_grew_without_capability_or_verification_lift';
        }
        $lineChurnDelta = (int) ($after['line_churn'] ?? 0) - (int) ($before['line_churn'] ?? 0);
        if ($lineChurnDelta > 0 && $deltas['capability_coverage'] <= 0) {
            $proxyWins[] = 'line_churn_grew_without_capability_lift';
        }

        return [
            'schema_version' => self::SCHEMA,
            'deltas' => $deltas,
            'real_leverage' => $realLeverage,
            'proxy_wins' => $proxyWins,
            'flagged_proxy' => $proxyWins !== [],
        ];
    }
}
