<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure integration. Converts runtime cycle facts + queue health into a BOUNDED native replenisher
 * action request.
 *
 * Decision:
 *   - malformed_count > 0           ⇒ action=repair_first
 *   - claimable_depth >= floor       ⇒ action=wait
 *   - otherwise                     ⇒ action=top_up with bounded target_new_packet_count
 *
 * Pure: NEVER invokes the replenisher, calls a provider, or writes anything.
 */
final class AtlasSelfConstructionContinuousRuntimeReplenisherIntegration
{
    public const SCHEMA = 'atlas.continuous_runtime.replenisher_integration.v1';

    public const ACTION_TOP_UP       = 'top_up';
    public const ACTION_WAIT         = 'wait';
    public const ACTION_HOLD         = 'hold';
    public const ACTION_REPAIR_FIRST = 'repair_first';

    private const DEFAULT_DEPTH_FLOOR = 3;
    private const DEFAULT_TARGET_DEPTH = 6;
    private const MAX_TARGET_NEW_PACKETS = 8;
    private const DEFAULT_QUALITY_FLOOR_THRESHOLD = 0.5;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function integrate(array $facts): array
    {
        $cycle = is_array($facts['runtime_cycle'] ?? null) ? $facts['runtime_cycle'] : [];
        $queue = is_array($facts['queue_health']  ?? null) ? $facts['queue_health']  : [];

        // Additional replenishment signals — absent when not provided (preserves hashes of existing tests).
        $maturityGaps   = is_array($facts['maturity_gaps']           ?? null) ? $facts['maturity_gaps']           : [];
        $outcomeSignals = is_array($facts['recent_outcome_learning']  ?? null) ? $facts['recent_outcome_learning']  : [];
        $liveTargets    = is_array($facts['live_target_exclusions']   ?? null) ? $facts['live_target_exclusions']   : [];
        $qualitySignals = is_array($facts['quality_signals']          ?? null) ? $facts['quality_signals']          : [];

        $poisonCount   = max(0, (int) ($qualitySignals['poison_count']   ?? 0));
        $lowValueCount = max(0, (int) ($qualitySignals['low_value_count'] ?? 0));
        $qualityFloorScore     = array_key_exists('quality_floor_score', $qualitySignals) ? (float) $qualitySignals['quality_floor_score'] : null;
        $qualityFloorThreshold = (float) ($qualitySignals['quality_floor_threshold'] ?? self::DEFAULT_QUALITY_FLOOR_THRESHOLD);
        $qualityFloorMet       = $qualityFloorScore === null || $qualityFloorScore >= $qualityFloorThreshold;

        $malformed = max(0, (int) ($queue['malformed_count'] ?? 0));
        $claimable = max(0, (int) ($queue['claimable_depth'] ?? 0));
        $servable  = max(0, (int) ($queue['servable_depth']  ?? 0));
        $floor     = max(0, (int) ($queue['depth_floor']     ?? self::DEFAULT_DEPTH_FLOOR));
        $target    = max($floor, (int) ($queue['target_depth'] ?? self::DEFAULT_TARGET_DEPTH));
        $cycleId   = (string) ($cycle['cycle_id'] ?? '');

        $muscleCount   = max(0, (int)    ($cycle['muscle_count']    ?? 0));
        $drainRateHint = max(0.0, (float) ($cycle['drain_rate_hint'] ?? 0.0));
        $ambitionDemand = (bool) ($cycle['ambition_demand'] ?? false);

        if ($malformed > 0 || $poisonCount > 0) {
            $reasons = [];
            if ($malformed > 0) {
                $reasons[] = 'malformed_packets_present:'.$malformed;
            }
            if ($poisonCount > 0) {
                $reasons[] = 'poison_signals_present:'.$poisonCount;
            }
            $request = [
                'action' => self::ACTION_REPAIR_FIRST,
                'cycle_id' => $cycleId,
                'reasons' => $reasons,
                'malformed_count' => $malformed,
                'target_new_packet_count' => 0,
            ];
            if ($poisonCount > 0) {
                $request['poison_count'] = $poisonCount;
            }

            return $this->envelope($request);
        }

        // Projected runway: cycles of claimable depth remaining at the current drain rate.
        // Below one full cycle of runway is treated as imminent starvation, not a safe wait.
        $projectedRunwayCycles = $drainRateHint > 0.0 ? $claimable / $drainRateHint : null;
        $lowRunway = $projectedRunwayCycles !== null && $projectedRunwayCycles < 1.0;

        if ($claimable >= $floor) {
            if ($lowRunway || $ambitionDemand) {
                $reason = $lowRunway
                    ? sprintf('low_projected_runway_overrides_wait:%.2f_cycles_remaining_at_drain_rate_%.2f', $projectedRunwayCycles, $drainRateHint)
                    : 'ambition_demand_overrides_wait';

                if (! $qualityFloorMet) {
                    return $this->envelope($this->qualityFloorHoldRequest($cycleId, $qualityFloorScore, $qualityFloorThreshold));
                }

                $deficit = max(0, $target - $claimable);
                $bounded = min(self::MAX_TARGET_NEW_PACKETS, max(1, $deficit));

                $request = [
                    'action' => self::ACTION_TOP_UP,
                    'cycle_id' => $cycleId,
                    'reasons' => ['claimable_depth_at_or_above_floor_but_'.$reason],
                    'claimable_depth' => $claimable,
                    'target_new_packet_count' => $bounded,
                    'depth_policy' => [
                        'muscle_count' => $muscleCount,
                        'drain_rate_hint' => $drainRateHint,
                        'safe_target_depth' => $bounded,
                        'deficit_reason' => $reason,
                        'why_target_is_bounded' => 'lazy_wait_refused_despite_healthy_depth',
                    ],
                ];

                return $this->envelope($request);
            }

            $request = [
                'action' => self::ACTION_WAIT,
                'cycle_id' => $cycleId,
                'reasons' => ['claimable_depth_at_or_above_floor:'.$claimable.'>='.$floor],
                'claimable_depth' => $claimable,
                'target_new_packet_count' => 0,
            ];

            return $this->envelope($request);
        }

        $deficit   = max(0, $target - $claimable);
        $bounded   = min(self::MAX_TARGET_NEW_PACKETS, $deficit);
        $liveCount = count(array_filter($liveTargets, static fn ($t): bool => (string) $t !== ''));

        // Duplicate-target hold: all slots we'd create are already covered by live targets.
        if ($bounded > 0 && $liveCount >= $bounded) {
            $request = [
                'action'                     => self::ACTION_HOLD,
                'cycle_id'                   => $cycleId,
                'reasons'                    => ['all_candidates_excluded_as_live_targets:'.$liveCount.'_slots_needed_'.$bounded],
                'live_target_exclusion_count' => $liveCount,
                'target_new_packet_count'    => 0,
            ];

            return $this->envelope($request);
        }

        // Low-value dominance hold: most of the candidate slots are already known low-value —
        // originating anyway would just enqueue work that fails the quality floor later.
        if ($bounded > 0 && $lowValueCount >= $bounded) {
            $request = [
                'action'                => self::ACTION_HOLD,
                'cycle_id'              => $cycleId,
                'reasons'               => ['low_value_signals_dominate:'.$lowValueCount.'_slots_needed_'.$bounded],
                'low_value_count'       => $lowValueCount,
                'target_new_packet_count' => 0,
            ];

            return $this->envelope($request);
        }

        if (! $qualityFloorMet) {
            return $this->envelope($this->qualityFloorHoldRequest($cycleId, $qualityFloorScore, $qualityFloorThreshold));
        }

        $request = [
            'action'                  => self::ACTION_TOP_UP,
            'cycle_id'                => $cycleId,
            'reasons'                 => ['claimable_depth_below_floor:'.$claimable.'<'.$floor],
            'claimable_depth'         => $claimable,
            'servable_depth'          => $servable,
            'depth_floor'             => $floor,
            'target_depth'            => $target,
            'target_new_packet_count' => $bounded,
        ];

        // Include optional learning signals only when present (avoids changing existing hashes).
        if ($maturityGaps !== []) {
            $request['maturity_gap_count'] = count($maturityGaps);
        }
        if ($outcomeSignals !== []) {
            $request['outcome_signal_count'] = count($outcomeSignals);
        }
        if ($liveCount > 0) {
            $request['live_excluded_count'] = $liveCount;
        }

        if ($muscleCount > 0 || $drainRateHint > 0.0) {
            $request['depth_policy'] = [
                'muscle_count'          => $muscleCount,
                'drain_rate_hint'       => $drainRateHint,
                'safe_target_depth'     => $bounded,
                'deficit_reason'        => "claimable_{$claimable}_below_target_{$target}_deficit_{$deficit}",
                'why_target_is_bounded' => $bounded < $deficit
                    ? 'max_packet_cap_applied:'.self::MAX_TARGET_NEW_PACKETS.'_deficit_was_'.$deficit
                    : 'deficit_within_cap',
            ];
        }

        return $this->envelope($request);
    }

    /** @return array<string,mixed> */
    private function qualityFloorHoldRequest(string $cycleId, ?float $score, float $threshold): array
    {
        return [
            'action' => self::ACTION_HOLD,
            'cycle_id' => $cycleId,
            'reasons' => [sprintf('quality_floor_not_met:score_%.3f_below_threshold_%.3f', $score ?? 0.0, $threshold)],
            'quality_floor_score' => $score ?? 0.0,
            'quality_floor_threshold' => $threshold,
            'target_new_packet_count' => 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function envelope(array $request): array
    {
        ksort($request);
        $payloadHash = hash('sha256', (string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema_version' => self::SCHEMA,
            'request' => $request,
            'payload_hash' => $payloadHash,
        ];
    }
}
