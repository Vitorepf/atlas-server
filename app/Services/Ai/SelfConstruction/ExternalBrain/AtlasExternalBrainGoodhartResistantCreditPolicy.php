<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Credits seeds by downstream capability evidence instead of task count,
 * test count, wrapper count or queue depth proxies.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainGoodhartResistantCreditPolicy
{
    public const SCHEMA = 'atlas.self_construction.external_brain_goodhart_resistant_credit_policy.v1';

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    public function evaluate(array $seed): array
    {
        $taskCount = (int) ($seed['task_count'] ?? 0);
        $testCount = (int) ($seed['test_count'] ?? 0);
        $wrapperCount = (int) ($seed['wrapper_count'] ?? 0);
        $queueDepth = (int) ($seed['queue_depth'] ?? 0);

        $closedLoopLearning = (bool) ($seed['closed_loop_learning_evidence'] ?? false);
        $repairEnabling = (bool) ($seed['repair_enabling_evidence'] ?? false);
        $capabilityDelta = trim((string) ($seed['capability_delta'] ?? ''));
        $downstreamUnlock = (bool) ($seed['downstream_unlock_evidence'] ?? false);

        // Raw proxies receive ZERO credit
        $proxyCredit = 0.0;

        // Real credit comes from downstream capability evidence
        $realCredit = 0.0;
        if ($closedLoopLearning) {
            $realCredit += 0.4;
        }
        if ($repairEnabling) {
            $realCredit += 0.3;
        }
        if ($capabilityDelta !== '') {
            $realCredit += 0.2;
        }
        if ($downstreamUnlock) {
            $realCredit += 0.1;
        }

        $totalCredit = $proxyCredit + $realCredit;
        $goodhartSafe = $realCredit > 0.0;

        return [
            'schema' => self::SCHEMA,
            'credit' => round($totalCredit, 4),
            'proxy_credit' => $proxyCredit,
            'real_credit' => round($realCredit, 4),
            'goodhart_safe' => $goodhartSafe,
            'task_count' => $taskCount,
            'test_count' => $testCount,
            'wrapper_count' => $wrapperCount,
            'queue_depth' => $queueDepth,
            'closed_loop_learning' => $closedLoopLearning,
            'repair_enabling' => $repairEnabling,
            'capability_delta' => $capabilityDelta,
            'downstream_unlock' => $downstreamUnlock,
        ];
    }
}
