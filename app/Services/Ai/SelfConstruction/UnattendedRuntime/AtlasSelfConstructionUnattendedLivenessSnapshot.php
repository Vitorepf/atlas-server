<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\Support\UnattendedLivenessFactsNormalizer;

/**
 * Pure facts composer for the final 24/7 Self-Construction supervisor liveness check.
 *
 * Accepts BOUNDED facts injected by the caller (no I/O, no DB, no providers, no processes).
 * Returns a stable envelope with a deterministic snapshot_hash. NO scalar scoring. NO recovery
 * decisions — that is a downstream concern.
 *
 * Required source keys: queue, heartbeat, continuous_runtime_cycle, native_worker, replenisher,
 * verification, merge. Missing required sources are reported in `missing_sources` rather than
 * pretending readiness.
 *
 * Pure normalize/classify/hash logic lives in {@see UnattendedLivenessFactsNormalizer}.
 */
final class AtlasSelfConstructionUnattendedLivenessSnapshot implements AtlasSelfConstructionUnattendedLivenessSnapshotPort
{
    public const SCHEMA = 'atlas.self_construction.unattended_liveness_snapshot.v1';

    public const STATUS_HEALTHY = UnattendedLivenessFactsNormalizer::STATUS_HEALTHY;

    public const STATUS_DEGRADED = UnattendedLivenessFactsNormalizer::STATUS_DEGRADED;

    public const STATUS_UNKNOWN = UnattendedLivenessFactsNormalizer::STATUS_UNKNOWN;

    public const HEARTBEAT_STALE_THRESHOLD_SECONDS = UnattendedLivenessFactsNormalizer::HEARTBEAT_STALE_THRESHOLD_SECONDS;

    /** @var list<string> */
    private const REQUIRED_SOURCES = [
        'queue',
        'heartbeat',
        'continuous_runtime_cycle',
        'native_worker',
        'replenisher',
        'verification',
        'merge',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compose(array $facts): array
    {
        $missingSources = [];
        foreach (self::REQUIRED_SOURCES as $key) {
            if (! array_key_exists($key, $facts) || ! is_array($facts[$key])) {
                $missingSources[] = $key;
            }
        }

        $queue = UnattendedLivenessFactsNormalizer::normalizeQueue($facts['queue'] ?? null);
        $heartbeat = UnattendedLivenessFactsNormalizer::normalizeHeartbeat($facts['heartbeat'] ?? null, (array) ($facts['active_leases'] ?? []));
        $cycle = UnattendedLivenessFactsNormalizer::normalizeCycle($facts['continuous_runtime_cycle'] ?? null);
        $worker = UnattendedLivenessFactsNormalizer::normalizeWorker($facts['native_worker'] ?? null);
        $replenisher = UnattendedLivenessFactsNormalizer::normalizeReplenisher($facts['replenisher'] ?? null);
        $verification = UnattendedLivenessFactsNormalizer::normalizeVerification($facts['verification'] ?? null);
        $merge = UnattendedLivenessFactsNormalizer::normalizeMerge($facts['merge'] ?? null);
        $activeLeases = array_values((array) ($facts['active_leases'] ?? []));
        $brainQuota = UnattendedLivenessFactsNormalizer::normalizeBrainQuota($facts['brain_quota'] ?? null);

        $factsOut = [
            'queue' => $queue,
            'heartbeat' => $heartbeat,
            'active_leases' => $activeLeases,
            'continuous_runtime_cycle' => $cycle,
            'native_worker' => $worker,
            'replenisher' => $replenisher,
            'verification' => $verification,
            'merge' => $merge,
            'brain_quota' => $brainQuota,
        ];

        $status = UnattendedLivenessFactsNormalizer::classify($factsOut, $missingSources);
        $snapshotHash = UnattendedLivenessFactsNormalizer::snapshotHash($status, $factsOut, $missingSources);

        // AC2/AC3: supply-side health signals — even when the snapshot is 'degraded',
        // the unattended runtime can continue safely if claimable supply is high and
        // there's no recoverable backlog.
        $claimableDepth = $queue['claimable_count'];
        $recoverableTotal = 0;
        foreach ($activeLeases as $lease) {
            if ((bool) ($lease['recoverable'] ?? false)) {
                $recoverableTotal++;
            }
        }
        $servableNow = $status === self::STATUS_HEALTHY
            || ($claimableDepth > 0 && $recoverableTotal === 0);
        $partialHealthSupplyOk = $status !== self::STATUS_HEALTHY
            && $claimableDepth >= 3
            && $claimableDepth >= $recoverableTotal
            && $recoverableTotal === 0;
        $servingJammed = ($queue['safety_stop'] || $queue['malformed_count'] > 0)
            && $claimableDepth > 0;
        $dryQueue = $claimableDepth === 0;
        $recoverableBacklog = $recoverableTotal > 0;
        $unsafeContinuation = $servingJammed || $dryQueue || $recoverableBacklog;

        // next_watch_item: the highest-risk signal to watch next.
        $nextWatchItem = match (true) {
            $missingSources !== [] => 'missing_sources',
            $servingJammed => 'serving_jammed',
            $recoverableBacklog => 'recoverable_backlog',
            $dryQueue => 'dry_queue',
            $status !== self::STATUS_HEALTHY && $partialHealthSupplyOk => 'supply_ok',
            (bool) $heartbeat['is_stale'] => 'stale_heartbeat',
            ! $worker['ready'] => 'worker_not_ready',
            default => 'none',
        };

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'facts' => $factsOut,
            'missing_sources' => $missingSources,
            'snapshot_hash' => $snapshotHash,
            'claimable_depth' => $claimableDepth,
            'recoverable_total' => $recoverableTotal,
            'servable_now' => $servableNow,
            'partial_health_supply_ok' => $partialHealthSupplyOk,
            'unsafe_continuation' => $unsafeContinuation,
            'next_watch_item' => $nextWatchItem,
        ];
    }
}
