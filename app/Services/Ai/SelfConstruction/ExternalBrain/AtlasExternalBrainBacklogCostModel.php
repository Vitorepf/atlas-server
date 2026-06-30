<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Estimates the real carrying cost of adding more tasks to the backlog and recommends the optimal
 * next action: seed new tasks, pause origination, consolidate existing work, or unblock blocked tasks.
 *
 * INPUT (all optional; missing fields use safe defaults):
 *   backlog_size:              int   — total tasks in queue (default 0)
 *   servable_depth:            int   — currently claimable tasks (default 0)
 *   worker_throughput:         float — tasks resolved per hour (default 1.0)
 *   give_back_rate:            float — fraction of tasks given back [0..1] (default 0.0)
 *   blocked_count:             int   — tasks blocked / unservable (default 0)
 *   average_integration_cost:  float — relative cost of integrating one task (default 1.0)
 *   impact_confidence:         float — confidence that queued tasks have real impact [0..1] (default 1.0)
 *
 * OUTPUT:
 *   { schema, carrying_cost:float, saturation_risk:string(low|medium|high),
 *     preferred_action:string(seed|pause|consolidate|unblock), reasons:list<string>,
 *     cost_breakdown:array<string,float> }
 *
 * PREFERRED ACTION PRIORITY (highest wins):
 *   unblock     — blocked tasks are a disproportionate share of the backlog (≥30% or ≥5 blocked)
 *   consolidate — servable depth is healthy AND (impact_confidence < 0.5 OR give_back_rate ≥ 0.30)
 *   pause       — saturation_risk = high
 *   seed        — default when none of the above apply
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainBacklogCostModel
{
    public const SCHEMA = 'atlas.external_brain.backlog_cost_model.v1';

    public const ACTION_SEED        = 'seed';
    public const ACTION_PAUSE       = 'pause';
    public const ACTION_CONSOLIDATE = 'consolidate';
    public const ACTION_UNBLOCK     = 'unblock';

    public const SATURATION_LOW    = 'low';
    public const SATURATION_MEDIUM = 'medium';
    public const SATURATION_HIGH   = 'high';

    /** Minimum servable_depth considered "healthy" for consolidation preference. */
    private const HEALTHY_SERVABLE_DEPTH = 10;

    /** Blocked fraction of backlog that triggers unblock preference. */
    private const BLOCKED_FRACTION_THRESHOLD = 0.30;

    /** Absolute blocked count that triggers unblock preference regardless of fraction. */
    private const BLOCKED_COUNT_THRESHOLD = 5;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function model(array $input): array
    {
        $backlogSize             = max(0, (int) ($input['backlog_size'] ?? 0));
        $servableDepth           = max(0, (int) ($input['servable_depth'] ?? 0));
        $workerThroughput        = max(0.01, (float) ($input['worker_throughput'] ?? 1.0));
        $giveBackRate            = min(1.0, max(0.0, (float) ($input['give_back_rate'] ?? 0.0)));
        $blockedCount            = max(0, (int) ($input['blocked_count'] ?? 0));
        $avgIntegrationCost      = max(0.0, (float) ($input['average_integration_cost'] ?? 1.0));
        $impactConfidence        = min(1.0, max(0.0, (float) ($input['impact_confidence'] ?? 1.0)));

        // ── Carrying cost breakdown ───────────────────────────────────────────
        $workerHoursCost   = round($backlogSize / $workerThroughput, 4);
        $giveBackBurden    = round($giveBackRate * $backlogSize * 0.5, 4);
        $reviewBurden      = round($backlogSize * 0.10, 4);
        $integrationLoad   = round($backlogSize * $avgIntegrationCost, 4);
        $opportunityCost   = round($blockedCount * 2.0, 4);

        $carryingCost = round($workerHoursCost + $giveBackBurden + $reviewBurden + $integrationLoad + $opportunityCost, 2);

        // ── Saturation risk ───────────────────────────────────────────────────
        $servableRatio   = $backlogSize > 0 ? $servableDepth / $backlogSize : 1.0;
        $saturationRisk  = $this->saturationRisk($giveBackRate, $servableRatio);

        // ── Preferred action & reasons ────────────────────────────────────────
        $reasons         = [];
        $preferredAction = $this->preferredAction(
            $backlogSize,
            $servableDepth,
            $giveBackRate,
            $blockedCount,
            $impactConfidence,
            $saturationRisk,
            $reasons,
        );

        return [
            'schema'           => self::SCHEMA,
            'carrying_cost'    => $carryingCost,
            'saturation_risk'  => $saturationRisk,
            'preferred_action' => $preferredAction,
            'reasons'          => array_values($reasons),
            'cost_breakdown'   => [
                'worker_hours_cost'  => $workerHoursCost,
                'give_back_burden'   => $giveBackBurden,
                'review_burden'      => $reviewBurden,
                'integration_load'   => $integrationLoad,
                'opportunity_cost'   => $opportunityCost,
            ],
        ];
    }

    private function saturationRisk(float $giveBackRate, float $servableRatio): string
    {
        if ($giveBackRate >= 0.5 || $servableRatio < 0.2) {
            return self::SATURATION_HIGH;
        }
        if ($giveBackRate >= 0.3 || $servableRatio < 0.4) {
            return self::SATURATION_MEDIUM;
        }

        return self::SATURATION_LOW;
    }

    /**
     * @param  list<string>  $reasons  (out — populated by reference)
     */
    private function preferredAction(
        int $backlogSize,
        int $servableDepth,
        float $giveBackRate,
        int $blockedCount,
        float $impactConfidence,
        string $saturationRisk,
        array &$reasons,
    ): string {
        // Priority 1 — unblock: blocked tasks are a disproportionate share.
        $blockedFraction = $backlogSize > 0 ? $blockedCount / $backlogSize : ($blockedCount > 0 ? 1.0 : 0.0);
        if ($blockedFraction >= self::BLOCKED_FRACTION_THRESHOLD || $blockedCount >= self::BLOCKED_COUNT_THRESHOLD) {
            $reasons[] = sprintf('blocked_pressure:count=%d fraction=%.2f', $blockedCount, $blockedFraction);

            return self::ACTION_UNBLOCK;
        }

        // Priority 2 — consolidate: healthy queue depth but impact confidence falling or give_back rising.
        $queueHealthy = $servableDepth >= self::HEALTHY_SERVABLE_DEPTH;
        if ($queueHealthy && ($impactConfidence < 0.5 || $giveBackRate >= 0.30)) {
            if ($impactConfidence < 0.5) {
                $reasons[] = sprintf('impact_confidence_low:%.2f', $impactConfidence);
            }
            if ($giveBackRate >= 0.30) {
                $reasons[] = sprintf('give_back_rate_elevated:%.2f', $giveBackRate);
            }
            $reasons[] = sprintf('servable_depth_healthy:%d', $servableDepth);

            return self::ACTION_CONSOLIDATE;
        }

        // Priority 3 — pause: saturation is high.
        if ($saturationRisk === self::SATURATION_HIGH) {
            $reasons[] = sprintf('saturation_risk_high:give_back_rate=%.2f', $giveBackRate);

            return self::ACTION_PAUSE;
        }

        // Default — seed.
        $reasons[] = sprintf('queue_healthy:servable=%d blocked=%d give_back_rate=%.2f', $servableDepth, $blockedCount, $giveBackRate);

        return self::ACTION_SEED;
    }
}
