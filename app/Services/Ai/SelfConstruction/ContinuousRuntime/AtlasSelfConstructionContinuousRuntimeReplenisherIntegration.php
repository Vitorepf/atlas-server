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

    public const ACTION_TOP_UP = 'top_up';
    public const ACTION_WAIT = 'wait';
    public const ACTION_REPAIR_FIRST = 'repair_first';

    private const DEFAULT_DEPTH_FLOOR = 3;
    private const DEFAULT_TARGET_DEPTH = 6;
    private const MAX_TARGET_NEW_PACKETS = 8;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function integrate(array $facts): array
    {
        $cycle = is_array($facts['runtime_cycle'] ?? null) ? $facts['runtime_cycle'] : [];
        $queue = is_array($facts['queue_health'] ?? null) ? $facts['queue_health'] : [];

        $malformed = max(0, (int) ($queue['malformed_count'] ?? 0));
        $claimable = max(0, (int) ($queue['claimable_depth'] ?? 0));
        $servable = max(0, (int) ($queue['servable_depth'] ?? 0));
        $floor = max(0, (int) ($queue['depth_floor'] ?? self::DEFAULT_DEPTH_FLOOR));
        $target = max($floor, (int) ($queue['target_depth'] ?? self::DEFAULT_TARGET_DEPTH));
        $cycleId = (string) ($cycle['cycle_id'] ?? '');

        if ($malformed > 0) {
            $request = [
                'action' => self::ACTION_REPAIR_FIRST,
                'cycle_id' => $cycleId,
                'reasons' => ['malformed_packets_present:'.$malformed],
                'malformed_count' => $malformed,
                'target_new_packet_count' => 0,
            ];

            return $this->envelope($request);
        }

        if ($claimable >= $floor) {
            $request = [
                'action' => self::ACTION_WAIT,
                'cycle_id' => $cycleId,
                'reasons' => ['claimable_depth_at_or_above_floor:'.$claimable.'>='.$floor],
                'claimable_depth' => $claimable,
                'target_new_packet_count' => 0,
            ];

            return $this->envelope($request);
        }

        $deficit = max(0, $target - $claimable);
        $bounded = min(self::MAX_TARGET_NEW_PACKETS, $deficit);

        $muscleCount   = max(0, (int)   ($cycle['muscle_count']    ?? 0));
        $drainRateHint = max(0.0, (float) ($cycle['drain_rate_hint'] ?? 0.0));

        $request = [
            'action' => self::ACTION_TOP_UP,
            'cycle_id' => $cycleId,
            'reasons' => ['claimable_depth_below_floor:'.$claimable.'<'.$floor],
            'claimable_depth' => $claimable,
            'servable_depth' => $servable,
            'depth_floor' => $floor,
            'target_depth' => $target,
            'target_new_packet_count' => $bounded,
        ];

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
