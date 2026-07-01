<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure policy. Decides when the external brain should seed more tasks, pause,
 * consolidate, or switch to unblock mode.
 *
 * Decision hierarchy (first match wins):
 *   unblock_first  — blocked/poison pressure ≥ threshold (clear before adding more)
 *   consolidate    — queue has healthy claimable depth but quality or malformed risk is rising
 *   pause          — worker throughput is low AND queue is not starving
 *   seed_now       — queue is low and conditions are safe
 *
 * Outputs: action, recommended_batch_size (bounded 1–max_batch_size), reasons.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionOriginatorCadencePolicy
{
    public const SCHEMA = 'atlas.self_construction.originator_cadence_policy.v1';

    public const ACTION_SEED_NOW      = 'seed_now';
    public const ACTION_PAUSE         = 'pause';
    public const ACTION_CONSOLIDATE   = 'consolidate';
    public const ACTION_UNBLOCK_FIRST = 'unblock_first';
    public const ACTION_CONTINUE_ORIGINATING  = 'continue_originating';
    public const ACTION_CONSOLIDATE_OR_REPAIR = 'consolidate_or_repair';

    // Thresholds.
    private const BLOCKED_PRESSURE_THRESHOLD     = 0.30; // blocked_count / (claimable+blocked) ratio
    private const QUALITY_RISK_THRESHOLD         = 5.5;  // recent_quality_score below this = risk
    private const MALFORMED_RISK_THRESHOLD       = 0.25; // malformed_risk above this = risk
    private const LOW_THROUGHPUT_THRESHOLD       = 0.30; // worker_throughput_rate below this
    private const STARVE_CLAIMABLE_THRESHOLD     = 3;    // claimable_depth below this = starving
    private const DEFAULT_MAX_BATCH_SIZE         = 5;

    /** Muscles expected to drain soon at/above this count threatens future supply even with a healthy queue. */
    private const WORKER_DRAIN_THREAT_FLOOR = 2;

    /**
     * @param  array{
     *   claimable_depth?: int,
     *   blocked_count?: int,
     *   worker_throughput_rate?: float,
     *   malformed_risk?: float,
     *   recent_quality_score?: float,
     *   total_queue_depth?: int,
     *   max_batch_size?: int,
     * }  $snapshot
     * @return array{schema:string, action:string, recommended_batch_size:int, reasons:list<string>}
     */
    public function decide(array $snapshot): array
    {
        $claimable      = max(0, (int)   ($snapshot['claimable_depth']        ?? 0));
        $blocked        = max(0, (int)   ($snapshot['blocked_count']          ?? 0));
        $throughput     = max(0.0, min(1.0, (float) ($snapshot['worker_throughput_rate']  ?? 1.0)));
        $malformedRisk  = max(0.0, min(1.0, (float) ($snapshot['malformed_risk']          ?? 0.0)));
        $qualityScore   = max(0.0, min(10.0, (float) ($snapshot['recent_quality_score']   ?? 10.0)));
        $maxBatch       = max(1, (int) ($snapshot['max_batch_size'] ?? self::DEFAULT_MAX_BATCH_SIZE));
        $workerDrainForecast = max(0, (int) ($snapshot['worker_drain_forecast'] ?? 0));
        $highValueTargetsRemaining = max(0, (int) ($snapshot['high_value_targets_remaining'] ?? 0));
        $learningFreshnessStale = (bool) ($snapshot['learning_freshness_stale'] ?? false);
        $targetYieldExhausted = (bool) ($snapshot['target_yield_exhausted'] ?? false);

        $qualityRisk    = $qualityScore < self::QUALITY_RISK_THRESHOLD;
        $malformedHigh  = $malformedRisk > self::MALFORMED_RISK_THRESHOLD;
        $total          = $claimable + $blocked;
        $blockedRatio   = $total > 0 ? $blocked / $total : 0.0;
        $isStarving     = $claimable < self::STARVE_CLAIMABLE_THRESHOLD;
        $lowThroughput  = $throughput < self::LOW_THROUGHPUT_THRESHOLD;

        // ── Decision hierarchy ────────────────────────────────────────────────

        if ($blockedRatio >= self::BLOCKED_PRESSURE_THRESHOLD && $blocked > 0) {
            return $this->result(self::ACTION_UNBLOCK_FIRST, 0, [
                'blocked_poison_pressure:ratio_'.(int) round($blockedRatio * 100).'pct',
            ]);
        }

        if (! $isStarving && ($qualityRisk || $malformedHigh)) {
            $reasons = [];
            if ($qualityRisk) {
                $reasons[] = 'quality_risk:score_'.number_format($qualityScore, 1);
            }
            if ($malformedHigh) {
                $reasons[] = 'malformed_risk:rate_'.(int) round($malformedRisk * 100).'pct';
            }
            $reasons[] = 'claimable_depth_healthy:'.$claimable.'_prefer_consolidation';

            return $this->result(self::ACTION_CONSOLIDATE, 0, $reasons);
        }

        // Supply-quality signals a static-depth model never sees: stale learning or an
        // exhausted target yield must trigger repair even when depth/throughput look fine.
        if (! $isStarving && ($learningFreshnessStale || $targetYieldExhausted)) {
            $reasons = [];
            if ($learningFreshnessStale) {
                $reasons[] = 'learning_freshness_stale';
            }
            if ($targetYieldExhausted) {
                $reasons[] = 'target_yield_exhausted';
            }
            $reasons[] = 'claimable_depth_healthy:'.$claimable.'_prefer_consolidation';

            return $this->result(self::ACTION_CONSOLIDATE_OR_REPAIR, 0, $reasons);
        }

        if ($lowThroughput && ! $isStarving) {
            return $this->result(self::ACTION_PAUSE, 0, [
                'low_worker_throughput:rate_'.(int) round($throughput * 100).'pct',
                'claimable_not_starving:'.$claimable,
            ]);
        }

        // Depth alone can lie: active muscles about to drain below the worker floor, with real
        // high-value targets still unqueued, must keep originating even though claimable_depth
        // currently looks sufficient.
        if (! $isStarving && $workerDrainForecast >= self::WORKER_DRAIN_THREAT_FLOOR && $highValueTargetsRemaining > 0) {
            return $this->result(self::ACTION_CONTINUE_ORIGINATING, $maxBatch, [
                'worker_drain_forecast:'.$workerDrainForecast,
                'high_value_targets_remaining:'.$highValueTargetsRemaining,
                'claimable_depth_sufficient_but_drain_threatens_future_supply',
            ]);
        }

        // Safe to seed: compute bounded batch size.
        $batchSize = $maxBatch;
        if ($qualityRisk) {
            $batchSize = max(1, (int) ceil($batchSize * 0.5));
        }
        if ($malformedHigh) {
            $batchSize = max(1, (int) ceil($batchSize * 0.7));
        }
        $batchSize = max(1, min($maxBatch, $batchSize));

        $reasons = ['claimable_depth_low:'.$claimable];
        if ($batchSize < $maxBatch) {
            $reasons[] = 'batch_size_reduced_due_to_risk';
        }

        $coverageBridge = is_array($snapshot['coverage_bridge'] ?? null) ? $snapshot['coverage_bridge'] : [];
        $pivotRequired = (bool) ($coverageBridge['pivot_required'] ?? false);
        $targetGaps = $pivotRequired ? array_values((array) ($coverageBridge['target_gaps'] ?? [])) : [];
        if ($pivotRequired) {
            $reasons[] = 'pivot_to_undercovered_gaps';
        }

        return $this->result(self::ACTION_SEED_NOW, $batchSize, $reasons, $targetGaps);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $targetGaps
     */
    private function result(string $action, int $batchSize, array $reasons, array $targetGaps = []): array
    {
        return [
            'schema'                  => self::SCHEMA,
            'action'                  => $action,
            'recommended_batch_size'  => $batchSize,
            'reasons'                 => $reasons,
            'target_gaps'             => $targetGaps,
        ];
    }
}
