<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure, deterministic, read-only advisor that resolves the control-plane ambiguity where a stale p95
 * queue age can trigger MORE origination even though the better action is to drain or reprioritize
 * EXISTING work. The closed action set never includes "originate" — rotate_oldest, surface_oldest_to_muscles,
 * observe, and do_not_rotate are the only possible outcomes, so this advisor can never recommend new work
 * as the fix for stale old work. No queue mutation, dequeue, sleep, worker spawn, provider call,
 * filesystem write, or git command.
 *
 * Input facts shape: {queue_age:{p95:float, ...}, oldest_packet_ids:list<string>, claimable_depth:int,
 *   active_leases:int, serve_rate_per_minute:float|null}
 */
final class AtlasMaestroOldestPacketRotationAdvisor
{
    public const SCHEMA = 'atlas.self_construction.maestro.oldest_packet_rotation_advisor.v1';

    public const ACTION_ROTATE_OLDEST = 'rotate_oldest';

    public const ACTION_SURFACE_OLDEST_TO_MUSCLES = 'surface_oldest_to_muscles';

    public const ACTION_OBSERVE = 'observe';

    public const ACTION_DO_NOT_ROTATE = 'do_not_rotate';

    private const P95_STALE_THRESHOLD_MINUTES = 60.0;

    private const CLAIMABLE_DEPTH_HIGH_THRESHOLD = 20;

    private const LOW_CONSUMPTION_RATE_PER_MINUTE = 1.0;

    /**
     * Per-packet classification (AC2). Independent of advise()'s fleet-level action set — a single
     * old packet is classified into exactly one of these 5 outcomes, never "originate".
     */
    public const PACKET_ACTION_SERVE_NOW = 'serve_now';

    public const PACKET_ACTION_RESHAPE = 'reshape';

    public const PACKET_ACTION_RETIRE = 'retire';

    public const PACKET_ACTION_KEEP_WAITING = 'keep_waiting';

    public const PACKET_ACTION_QUARANTINE = 'quarantine';

    /** Age (minutes) at/above which any non-zero value decay is enough to retire the packet. */
    private const AGE_ANCIENT_MINUTES = 240.0;

    private const VALUE_DECAY_RETIRE_FLOOR = 0.8;

    private const STALENESS_RESHAPE_FLOOR = 0.6;

    private const WORKER_FIT_LOW_FLOOR = 0.3;

    private const PROOF_FRESHNESS_STALE_FLOOR = 0.3;

    private const BLOCKED_HISTORY_QUARANTINE_FLOOR = 3;

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function advise(array $facts): array
    {
        $p95 = (float) data_get($facts, 'queue_age.p95', 0.0);
        $claimableDepth = (int) ($facts['claimable_depth'] ?? 0);
        $activeLeases = (int) ($facts['active_leases'] ?? 0);
        $serveRate = $facts['serve_rate_per_minute'] ?? null;
        $oldestIds = array_values(array_map('strval', (array) ($facts['oldest_packet_ids'] ?? [])));

        $isStale = $p95 >= self::P95_STALE_THRESHOLD_MINUTES;
        $isHighDepth = $claimableDepth >= self::CLAIMABLE_DEPTH_HIGH_THRESHOLD;
        $isLowOrUnknownConsumption = $serveRate === null || (float) $serveRate < self::LOW_CONSUMPTION_RATE_PER_MINUTE;

        $reasonCodes = [];
        if ($isStale) {
            $reasonCodes[] = 'p95_age_stale:'.$p95;
        }
        if ($isHighDepth) {
            $reasonCodes[] = 'claimable_depth_high:'.$claimableDepth;
        }
        if ($isLowOrUnknownConsumption) {
            $reasonCodes[] = $serveRate === null ? 'consumption_unknown' : 'consumption_low:'.$serveRate;
        }

        if ($isStale && $isHighDepth && $isLowOrUnknownConsumption) {
            $action = $activeLeases === 0 ? self::ACTION_ROTATE_OLDEST : self::ACTION_SURFACE_OLDEST_TO_MUSCLES;

            return $this->result($action, $reasonCodes, $oldestIds);
        }

        if ($isStale && ! $isHighDepth) {
            $reasonCodes[] = 'depth_not_high_enough_to_warrant_rotation';

            return $this->result(self::ACTION_DO_NOT_ROTATE, $reasonCodes, []);
        }

        return $this->result(self::ACTION_OBSERVE, $reasonCodes, []);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $packetIdsToSurface
     * @return array<string, mixed>
     */
    private function result(string $action, array $reasonCodes, array $packetIdsToSurface): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reason_codes' => $reasonCodes,
            'packet_ids_to_surface' => $packetIdsToSurface,
        ];
    }

    /**
     * Per-packet rotation classification (AC2/AC3/AC4). Independent of advise()'s fleet-level
     * action set and facts shape — pure, read-only, deterministic; never mutates a packet.
     *
     * @param  list<array<string,mixed>>  $packets  each: {task_packet_id?, age_minutes?,
     *   value_decay_score?, staleness_score?, worker_fit_score?, blocked_history_count?,
     *   proof_freshness_score?}
     * @return array{schema:string, classified_packets:list<array<string,mixed>>, count:int}
     */
    public function classifyOldestPackets(array $packets): array
    {
        $classified = [];
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $classified[] = $this->classifyPacket($packet);
        }

        return [
            'schema' => self::SCHEMA,
            'classified_packets' => $classified,
            'count' => count($classified),
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array{task_packet_id:string, action:string, rationale:string, signals:list<string>}
     */
    private function classifyPacket(array $packet): array
    {
        $id = (string) ($packet['task_packet_id'] ?? '');
        $ageMinutes = max(0.0, (float) ($packet['age_minutes'] ?? 0.0));
        $valueDecay = max(0.0, min(1.0, (float) ($packet['value_decay_score'] ?? 0.0)));
        $staleness = max(0.0, min(1.0, (float) ($packet['staleness_score'] ?? 0.0)));
        $workerFit = max(0.0, min(1.0, (float) ($packet['worker_fit_score'] ?? 1.0)));
        $blockedHistoryCount = max(0, (int) ($packet['blocked_history_count'] ?? 0));
        $proofFreshness = max(0.0, min(1.0, (float) ($packet['proof_freshness_score'] ?? 1.0)));
        $isAncient = $ageMinutes >= self::AGE_ANCIENT_MINUTES;

        $signals = [
            'age_minutes:'.$ageMinutes,
            'value_decay_score:'.$valueDecay,
            'staleness_score:'.$staleness,
            'worker_fit_score:'.$workerFit,
            'blocked_history_count:'.$blockedHistoryCount,
            'proof_freshness_score:'.$proofFreshness,
        ];

        [$action, $rationale] = match (true) {
            $blockedHistoryCount >= self::BLOCKED_HISTORY_QUARANTINE_FLOOR => [
                self::PACKET_ACTION_QUARANTINE,
                sprintf('packet %s has been blocked %d times — repeated failure to serve indicates a structural defect, not bad luck; quarantine it for review before it consumes more queue cycles', $id, $blockedHistoryCount),
            ],
            $valueDecay >= self::VALUE_DECAY_RETIRE_FLOOR || ($isAncient && $valueDecay > 0.0) => [
                self::PACKET_ACTION_RETIRE,
                sprintf('packet %s has decayed %.0f%% in value after %.0f minutes alive — the opportunity it targeted is stale or already captured elsewhere; an old low-value packet must not be kept alive forever just because nothing failed it outright', $id, $valueDecay * 100, $ageMinutes),
            ],
            $proofFreshness < self::PROOF_FRESHNESS_STALE_FLOOR => [
                self::PACKET_ACTION_RESHAPE,
                sprintf('packet %s relies on proof evidence that is no longer fresh (%.0f%% freshness) — reshape its acceptance criteria against current code before it can be safely served', $id, $proofFreshness * 100),
            ],
            $staleness >= self::STALENESS_RESHAPE_FLOOR => [
                self::PACKET_ACTION_RESHAPE,
                sprintf('packet %s is stale (%.0f%% staleness) relative to the current codebase — reshape its scope before it can be safely served', $id, $staleness * 100),
            ],
            $workerFit < self::WORKER_FIT_LOW_FLOOR => [
                self::PACKET_ACTION_KEEP_WAITING,
                sprintf('packet %s does not fit any currently-available worker profile (worker_fit=%.2f) — keep waiting for a matching worker rather than force a mismatch', $id, $workerFit),
            ],
            default => [
                self::PACKET_ACTION_SERVE_NOW,
                sprintf('packet %s shows no decay, staleness, proof-staleness, blocked-history or worker-fit problem — serve it now', $id),
            ],
        };

        return [
            'task_packet_id' => $id,
            'action' => $action,
            'rationale' => $rationale,
            'signals' => $signals,
        ];
    }
}
