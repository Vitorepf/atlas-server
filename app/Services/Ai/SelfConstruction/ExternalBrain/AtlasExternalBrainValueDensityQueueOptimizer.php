<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides whether to keep feeding the queue, let muscles drain first, prioritize top packets,
 * or self-heal / respec when shallow depth is caused by risky / malformed packets.
 *
 * DECISION PRIORITY (first match wins):
 *   drain_first         — queue already has enough high-value work
 *                         triggers: claimable_count >= oversaturation_limit
 *                                OR (claimable_count >= depth_floor AND value_density >= floor)
 *   self_heal_or_respec — queue is shallow/low-density AND aggregate risk is high
 *                         triggers: would otherwise be feed_queue
 *                                   AND max(mean_give_back_risk,
 *                                           mean_malformed_risk,
 *                                           mean_poison_family_risk) >= RISK_TRIGGER_CEILING
 *   feed_queue          — depth or density too low for the active muscle fleet; packets are clean
 *   prioritize_top      — depth adequate, density adequate, selection optimisation available
 *
 * RISK FIELDS (per packet, all optional):
 *   give_back_risk      float  (default 0.0) — likelihood worker returns task without completing
 *   malformed_risk      float  (default 0.0) — structural defect / poison risk
 *   poison_family_risk  float  (default 0.0) — family-level poison contamination risk
 *   consolidation_debt  float  (default 0.0) — outstanding consolidation burden (informational)
 *   outcome_confidence  float  (default 1.0) — probability task produces a useful outcome (informational)
 *
 * VALUE DENSITY PER PACKET = expected_value / max(1, estimated_worker_minutes)
 * VALUE DENSITY SCORE      = mean of per-packet densities (0.0 if no packets)
 * AGGREGATE RISK SCORE     = max(mean_give_back_risk, mean_malformed_risk, mean_poison_family_risk)
 *
 * DEFAULT THRESHOLDS:
 *   value_density_floor  = 0.50
 *   depth_floor          = muscle_count × depth_floor_multiplier (default 2)
 *   oversaturation_limit = muscle_count × oversaturation_multiplier (default 5)
 *   risk_trigger_ceiling = 0.50
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainValueDensityQueueOptimizer
{
    public const SCHEMA = 'atlas.external_brain.value_density_queue_optimizer.v1';

    public const ACTION_FEED_QUEUE          = 'feed_queue';
    public const ACTION_DRAIN_FIRST         = 'drain_first';
    public const ACTION_PRIORITIZE_TOP      = 'prioritize_top';
    public const ACTION_SELF_HEAL_OR_RESPEC = 'self_heal_or_respec';

    private const DEFAULT_MUSCLE_COUNT            = 1;
    private const DEFAULT_MUSCLE_MINUTES_PER_TASK = 30.0;
    private const DEFAULT_VALUE_DENSITY_FLOOR     = 0.50;
    private const DEFAULT_DEPTH_FLOOR_MULTIPLIER  = 2;
    private const DEFAULT_OVERSAT_MULTIPLIER      = 5;
    private const DEFAULT_RISK_TRIGGER_CEILING    = 0.50;
    private const TOP_N                           = 3;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function optimize(array $input): array
    {
        $rawPackets        = is_array($input['packets'] ?? null) ? $input['packets'] : [];
        $muscleCount       = max(1, (int)   ($input['muscle_count']              ?? self::DEFAULT_MUSCLE_COUNT));
        $muscleMinutes     = max(1.0, (float) ($input['muscle_minutes_per_task'] ?? self::DEFAULT_MUSCLE_MINUTES_PER_TASK));
        $valueDensityFloor = (float) ($input['value_density_floor']              ?? self::DEFAULT_VALUE_DENSITY_FLOOR);
        $depthMultiplier   = max(1, (int) ($input['depth_floor_multiplier']      ?? self::DEFAULT_DEPTH_FLOOR_MULTIPLIER));
        $oversatMultiplier = max(1, (int) ($input['oversaturation_multiplier']   ?? self::DEFAULT_OVERSAT_MULTIPLIER));
        $riskCeiling       = (float) ($input['risk_trigger_ceiling']             ?? self::DEFAULT_RISK_TRIGGER_CEILING);

        $depthFloor       = $muscleCount * $depthMultiplier;
        $oversatLimit     = $muscleCount * $oversatMultiplier;
        $muscleMinutesCap = (float) ($muscleCount * $muscleMinutes);

        // Parse packets and compute per-packet metrics.
        $packets        = [];
        $giveBackRisks  = [];
        $malformedRisks = [];
        $poisonRisks    = [];

        foreach ($rawPackets as $p) {
            if (! is_array($p) || ! isset($p['packet_id'])) {
                continue;
            }
            $expectedValue = (float) ($p['expected_value']          ?? 0.0);
            $workerMinutes = max(1.0, (float) ($p['estimated_worker_minutes'] ?? 1.0));

            $packets[] = [
                'packet_id' => (string) $p['packet_id'],
                'density'   => $expectedValue / $workerMinutes,
            ];

            $giveBackRisks[]  = (float) ($p['give_back_risk']     ?? 0.0);
            $malformedRisks[] = (float) ($p['malformed_risk']     ?? 0.0);
            $poisonRisks[]    = (float) ($p['poison_family_risk'] ?? 0.0);
        }

        $claimableCount    = count($packets);
        $densities         = array_column($packets, 'density');
        $valueDensityScore = $claimableCount > 0
            ? array_sum($densities) / $claimableCount
            : 0.0;

        // Aggregate risk: max of mean scores for the three trigger signals.
        $aggregateRisk = 0.0;
        if ($claimableCount > 0) {
            $aggregateRisk = max(
                array_sum($giveBackRisks)  / $claimableCount,
                array_sum($malformedRisks) / $claimableCount,
                array_sum($poisonRisks)    / $claimableCount,
            );
        }

        $isHighRisk  = $aggregateRisk >= $riskCeiling;
        $needsFeeding = $claimableCount < $depthFloor || $valueDensityScore < $valueDensityFloor;

        // Top-N by density (descending, tie-break by packet_id ASC).
        usort($packets, static fn (array $a, array $b): int =>
            abs($b['density'] - $a['density']) < 0.00001
                ? strcmp($a['packet_id'], $b['packet_id'])
                : ($b['density'] <=> $a['density'])
        );
        $topPacketClasses = array_column(array_slice($packets, 0, self::TOP_N), 'packet_id');

        $lowValueTail = array_values(array_column(
            array_filter($packets, static fn (array $p): bool => $p['density'] < $valueDensityFloor),
            'packet_id',
        ));

        // Decision (priority order).
        if ($claimableCount >= $oversatLimit
            || ($claimableCount >= $depthFloor && $valueDensityScore >= $valueDensityFloor)
        ) {
            $action = self::ACTION_DRAIN_FIRST;
        } elseif ($needsFeeding && $isHighRisk) {
            $action = self::ACTION_SELF_HEAL_OR_RESPEC;
        } elseif ($needsFeeding) {
            $action = self::ACTION_FEED_QUEUE;
        } else {
            $action = self::ACTION_PRIORITIZE_TOP;
        }

        return [
            'schema'                  => self::SCHEMA,
            'action'                  => $action,
            'value_density_score'     => round($valueDensityScore, 6),
            'aggregate_risk_score'    => round($aggregateRisk, 6),
            'top_packet_classes'      => $topPacketClasses,
            'low_value_tail'          => $lowValueTail,
            'muscle_minutes_capacity' => $muscleMinutesCap,
        ];
    }
}
