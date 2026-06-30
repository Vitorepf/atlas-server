<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Estimates the real carrying cost of adding more tasks to the backlog and recommends the optimal
 * next action: seed new tasks, drain claimable depth, pause origination, consolidate existing
 * work, or unblock blocked tasks.
 *
 * INPUT (all optional; missing fields use safe defaults):
 *   backlog_size:              int   — total tasks in queue (default 0)
 *   claimable_depth:           int   — currently claimable tasks (alias: servable_depth; default 0)
 *   worker_capacity:           int   — number of workers / concurrency slots (default 1)
 *   worker_throughput:         float — tasks resolved per hour (default 1.0)
 *   give_back_rate:            float — fraction of tasks given back [0..1] (default 0.0)
 *   blocked_count:             int   — tasks blocked / unservable (default 0)
 *   average_integration_cost:  float — relative cost of integrating one task (default 1.0)
 *   impact_confidence:         float — confidence that queued tasks have real impact [0..1] (default 1.0)
 *   expected_value_density:    float — average value per claimable task [0..1] (default 1.0)
 *   age_cost:                  float — additional carrying cost per task per backlog unit (default 0.0)
 *
 * OUTPUT:
 *   { schema, carrying_cost:float, saturation_risk:string(low|medium|high),
 *     preferred_action:string(seed|drain|consolidate|unblock|pause), reasons:list<string>,
 *     cost_breakdown:array<string,float>,
 *     recommended_queue_action:{ action, reasons, economics } }
 *
 * RECOMMENDED_QUEUE_ACTION ECONOMICS: bundles all six decision inputs so the caller can
 * audit what drove the choice: worker_capacity, claimable_depth, blocked_count,
 * give_back_rate, expected_value_density, age_cost.
 *
 * PREFERRED ACTION PRIORITY (highest wins):
 *   unblock     — blocked tasks are a disproportionate share of the backlog (≥30% or ≥5 blocked)
 *   drain       — claimable depth is high (≥HIGH_CLAIMABLE_DEPTH) AND value density is falling (<LOW_VALUE_DENSITY)
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
    public const ACTION_DRAIN       = 'drain';
    public const ACTION_PAUSE       = 'pause';
    public const ACTION_CONSOLIDATE = 'consolidate';
    public const ACTION_UNBLOCK     = 'unblock';

    public const SATURATION_LOW    = 'low';
    public const SATURATION_MEDIUM = 'medium';
    public const SATURATION_HIGH   = 'high';

    /** Minimum claimable_depth considered "healthy" for consolidation preference. */
    private const HEALTHY_SERVABLE_DEPTH = 10;

    /** Blocked fraction of backlog that triggers unblock preference. */
    private const BLOCKED_FRACTION_THRESHOLD = 0.30;

    /** Absolute blocked count that triggers unblock preference regardless of fraction. */
    private const BLOCKED_COUNT_THRESHOLD = 5;

    /** Minimum claimable_depth for drain to be considered. */
    private const HIGH_CLAIMABLE_DEPTH_THRESHOLD = 20;

    /** expected_value_density below this triggers drain instead of seed when depth is high. */
    private const LOW_VALUE_DENSITY_THRESHOLD = 0.50;

    /** Claimable-task age (minutes, p95) above which the backlog reads as genuinely stale. */
    private const STALE_QUEUE_AGE_MINUTES = 60.0;

    /** Serve rate (tasks/minute) below which muscles are not keeping up with the claimable backlog. */
    private const LOW_SERVE_RATE_PER_MINUTE = 0.10;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function model(array $input): array
    {
        $backlogSize          = max(0, (int) ($input['backlog_size'] ?? 0));
        // Accept claimable_depth (new canonical name) or servable_depth (legacy alias).
        $claimableDepth       = max(0, (int) ($input['claimable_depth'] ?? $input['servable_depth'] ?? 0));
        $workerCapacity       = max(1, (int) ($input['worker_capacity'] ?? 1));
        $workerThroughput     = max(0.01, (float) ($input['worker_throughput'] ?? 1.0));
        $giveBackRate         = min(1.0, max(0.0, (float) ($input['give_back_rate'] ?? 0.0)));
        $blockedCount         = max(0, (int) ($input['blocked_count'] ?? 0));
        $avgIntegrationCost   = max(0.0, (float) ($input['average_integration_cost'] ?? 1.0));
        $impactConfidence     = min(1.0, max(0.0, (float) ($input['impact_confidence'] ?? 1.0)));
        $expectedValueDensity = min(1.0, max(0.0, (float) ($input['expected_value_density'] ?? 1.0)));
        $ageCost              = max(0.0, (float) ($input['age_cost'] ?? 0.0));
        $queueAgeP95Minutes   = max(0.0, (float) ($input['queue_age_p95_minutes'] ?? $input['claimable_age_p95_minutes'] ?? 0.0));
        $serveRatePerMinute   = max(0.0, (float) ($input['serve_rate_per_minute'] ?? 0.0));

        // ── Carrying cost breakdown ───────────────────────────────────────────
        $workerHoursCost     = round($backlogSize / $workerThroughput, 4);
        $giveBackBurden      = round($giveBackRate * $backlogSize * 0.5, 4);
        $reviewBurden        = round($backlogSize * 0.10, 4);
        $integrationLoad     = round($backlogSize * $avgIntegrationCost, 4);
        $opportunityCost     = round($blockedCount * 2.0, 4);
        $ageCostContribution = round($ageCost * $backlogSize, 4);

        // Stale-backlog carrying cost: hours of p95 staleness × the claimable depth muscles are NOT
        // consuming within an hour at the observed serve rate. Real carrying cost of old, slow-moving
        // work — not just a flat per-task age multiplier.
        $unconsumedPerHour    = max(0.0, $claimableDepth - ($serveRatePerMinute * 60.0));
        $staleBacklogCost     = round(($queueAgeP95Minutes / 60.0) * $unconsumedPerHour, 4);

        $carryingCost = round(
            $workerHoursCost + $giveBackBurden + $reviewBurden
            + $integrationLoad + $opportunityCost + $ageCostContribution + $staleBacklogCost,
            2
        );

        // ── Saturation risk ───────────────────────────────────────────────────
        $servableRatio  = $backlogSize > 0 ? $claimableDepth / $backlogSize : 1.0;
        $saturationRisk = $this->saturationRisk($giveBackRate, $servableRatio);

        // ── Preferred action & reasons ────────────────────────────────────────
        $reasons         = [];
        $preferredAction = $this->preferredAction(
            $backlogSize,
            $claimableDepth,
            $giveBackRate,
            $blockedCount,
            $impactConfidence,
            $expectedValueDensity,
            $saturationRisk,
            $queueAgeP95Minutes,
            $serveRatePerMinute,
            $reasons,
        );

        return [
            'schema'           => self::SCHEMA,
            'carrying_cost'    => $carryingCost,
            'saturation_risk'  => $saturationRisk,
            'preferred_action' => $preferredAction,
            'reasons'          => array_values($reasons),
            'cost_breakdown'   => [
                'worker_hours_cost'    => $workerHoursCost,
                'give_back_burden'     => $giveBackBurden,
                'review_burden'        => $reviewBurden,
                'integration_load'     => $integrationLoad,
                'opportunity_cost'     => $opportunityCost,
                'age_cost_contribution' => $ageCostContribution,
                'stale_backlog_cost'   => $staleBacklogCost,
            ],
            'recommended_queue_action' => [
                'action'    => $preferredAction,
                'reasons'   => array_values($reasons),
                'economics' => [
                    'worker_capacity'        => $workerCapacity,
                    'claimable_depth'        => $claimableDepth,
                    'blocked_count'          => $blockedCount,
                    'give_back_rate'         => $giveBackRate,
                    'expected_value_density' => $expectedValueDensity,
                    'age_cost'               => $ageCost,
                    'queue_age_p95_minutes'  => $queueAgeP95Minutes,
                    'serve_rate_per_minute'  => $serveRatePerMinute,
                ],
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
        int    $backlogSize,
        int    $claimableDepth,
        float  $giveBackRate,
        int    $blockedCount,
        float  $impactConfidence,
        float  $expectedValueDensity,
        string $saturationRisk,
        float  $queueAgeP95Minutes,
        float  $serveRatePerMinute,
        array  &$reasons,
    ): string {
        // Priority 1 — unblock: blocked tasks are a disproportionate share. This MUST win over
        // stale-backlog drain/consolidate — an unblock fixes the root cause that is also stalling
        // throughput, so it always takes precedence.
        $blockedFraction = $backlogSize > 0
            ? $blockedCount / $backlogSize
            : ($blockedCount > 0 ? 1.0 : 0.0);
        if ($blockedFraction >= self::BLOCKED_FRACTION_THRESHOLD || $blockedCount >= self::BLOCKED_COUNT_THRESHOLD) {
            $reasons[] = sprintf('blocked_pressure:count=%d fraction=%.2f', $blockedCount, $blockedFraction);

            return self::ACTION_UNBLOCK;
        }

        // Priority 2 — stale low-throughput backlog: old claimable work is accumulating faster than
        // muscles consume it. Prefer draining/working through it instead of seeding more on top.
        $isStaleLowThroughput = $claimableDepth > 0
            && $queueAgeP95Minutes >= self::STALE_QUEUE_AGE_MINUTES
            && $serveRatePerMinute < self::LOW_SERVE_RATE_PER_MINUTE;
        if ($isStaleLowThroughput) {
            $reasons[] = sprintf(
                'stale_backlog:queue_age_p95_minutes=%.1f serve_rate_per_minute=%.3f claimable_depth=%d drain_instead_of_seed',
                $queueAgeP95Minutes,
                $serveRatePerMinute,
                $claimableDepth,
            );

            return self::ACTION_DRAIN;
        }

        // Priority 3 — drain: high claimable depth but value density is falling.
        //   The queue has plenty of claimable work, but tasks are losing expected value →
        //   work through existing backlog instead of seeding more.
        if ($claimableDepth >= self::HIGH_CLAIMABLE_DEPTH_THRESHOLD
            && $expectedValueDensity < self::LOW_VALUE_DENSITY_THRESHOLD
        ) {
            $reasons[] = sprintf(
                'claimable_depth_high:%d expected_value_density_low:%.2f drain_instead_of_seed',
                $claimableDepth,
                $expectedValueDensity,
            );

            return self::ACTION_DRAIN;
        }

        // Priority 3 — consolidate: healthy queue depth but impact confidence falling or give_back rising.
        $queueHealthy = $claimableDepth >= self::HEALTHY_SERVABLE_DEPTH;
        if ($queueHealthy && ($impactConfidence < 0.5 || $giveBackRate >= 0.30)) {
            if ($impactConfidence < 0.5) {
                $reasons[] = sprintf('impact_confidence_low:%.2f', $impactConfidence);
            }
            if ($giveBackRate >= 0.30) {
                $reasons[] = sprintf('give_back_rate_elevated:%.2f', $giveBackRate);
            }
            $reasons[] = sprintf('servable_depth_healthy:%d', $claimableDepth);

            return self::ACTION_CONSOLIDATE;
        }

        // Priority 4 — pause: saturation is high.
        if ($saturationRisk === self::SATURATION_HIGH) {
            $reasons[] = sprintf('saturation_risk_high:give_back_rate=%.2f', $giveBackRate);

            return self::ACTION_PAUSE;
        }

        // Default — seed.
        $reasons[] = sprintf(
            'queue_healthy:servable=%d blocked=%d give_back_rate=%.2f',
            $claimableDepth,
            $blockedCount,
            $giveBackRate,
        );

        return self::ACTION_SEED;
    }
}
