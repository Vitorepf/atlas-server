<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

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
 */
final class AtlasSelfConstructionUnattendedLivenessSnapshot
{
    public const SCHEMA = 'atlas.self_construction.unattended_liveness_snapshot.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNKNOWN = 'unknown';

    public const HEARTBEAT_STALE_THRESHOLD_SECONDS = 120;

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

        $queue = $this->normalizeQueue($facts['queue'] ?? null);
        $heartbeat = $this->normalizeHeartbeat($facts['heartbeat'] ?? null, (array) ($facts['active_leases'] ?? []));
        $cycle = $this->normalizeCycle($facts['continuous_runtime_cycle'] ?? null);
        $worker = $this->normalizeWorker($facts['native_worker'] ?? null);
        $replenisher = $this->normalizeReplenisher($facts['replenisher'] ?? null);
        $verification = $this->normalizeVerification($facts['verification'] ?? null);
        $merge = $this->normalizeMerge($facts['merge'] ?? null);
        $activeLeases = array_values((array) ($facts['active_leases'] ?? []));
        $brainQuota = $this->normalizeBrainQuota($facts['brain_quota'] ?? null);

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

        $status = $this->classify($factsOut, $missingSources);
        $snapshotHash = $this->snapshotHash($status, $factsOut, $missingSources);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'facts' => $factsOut,
            'missing_sources' => $missingSources,
            'snapshot_hash' => $snapshotHash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeQueue(mixed $queue): array
    {
        $q = is_array($queue) ? $queue : [];

        return [
            'depth' => (int) ($q['depth'] ?? 0),
            'claimable_count' => (int) ($q['claimable_count'] ?? 0),
            'malformed_count' => (int) ($q['malformed_count'] ?? 0),
            'safety_stop' => (bool) ($q['safety_stop'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeHeartbeat(mixed $heartbeat, array $leases): array
    {
        $hb = is_array($heartbeat) ? $heartbeat : [];

        return [
            'last_seen_age_seconds' => isset($hb['last_seen_age_seconds']) ? (int) $hb['last_seen_age_seconds'] : null,
            'stale_threshold_seconds' => (int) ($hb['stale_threshold_seconds'] ?? self::HEARTBEAT_STALE_THRESHOLD_SECONDS),
            'active_lease_count' => count($leases),
            'is_stale' => $this->heartbeatIsStale($hb),
        ];
    }

    /**
     * @param  array<string,mixed>  $hb
     */
    private function heartbeatIsStale(array $hb): bool
    {
        if (! isset($hb['last_seen_age_seconds'])) {
            return true; // missing heartbeat ⇒ treated as stale
        }
        $threshold = (int) ($hb['stale_threshold_seconds'] ?? self::HEARTBEAT_STALE_THRESHOLD_SECONDS);

        return (int) $hb['last_seen_age_seconds'] > $threshold;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeCycle(mixed $cycle): array
    {
        $c = is_array($cycle) ? $cycle : [];

        return [
            'last_cycle_id' => (string) ($c['last_cycle_id'] ?? ''),
            'last_stop_reason' => (string) ($c['last_stop_reason'] ?? ''),
            'last_stopped' => (bool) ($c['last_stopped'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeWorker(mixed $worker): array
    {
        $w = is_array($worker) ? $worker : [];

        return [
            'ready' => (bool) ($w['ready'] ?? false),
            'concurrency_floor_ok' => (bool) ($w['concurrency_floor_ok'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeReplenisher(mixed $replenisher): array
    {
        $r = is_array($replenisher) ? $replenisher : [];

        return [
            'last_run_status' => (string) ($r['last_run_status'] ?? ''),
            'last_run_age_seconds' => isset($r['last_run_age_seconds']) ? (int) $r['last_run_age_seconds'] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeVerification(mixed $verification): array
    {
        $v = is_array($verification) ? $verification : [];

        return [
            'last_verdict' => (string) ($v['last_verdict'] ?? ''),
            'failed_run_count' => (int) ($v['failed_run_count'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeMerge(mixed $merge): array
    {
        $m = is_array($merge) ? $merge : [];

        return [
            'last_decision' => (string) ($m['last_decision'] ?? ''),
            'blocked' => (bool) ($m['blocked'] ?? false),
        ];
    }

    /**
     * Optional brain quota stall facts from atlas:brain:state snapshot. No I/O — caller injects.
     *
     * @return array<string,mixed>
     */
    private function normalizeBrainQuota(mixed $brainQuota): array
    {
        $b = is_array($brainQuota) ? $brainQuota : [];

        return [
            'status' => (string) ($b['status'] ?? ''),
            'actor' => (string) ($b['actor'] ?? ''),
            'remaining' => isset($b['remaining']) ? (int) $b['remaining'] : null,
            'stall_reason' => (string) ($b['stall_reason'] ?? ''),
            'must_run_now' => (bool) ($b['must_run_now'] ?? false),
            'active_brain_commands' => (int) ($b['active_brain_commands'] ?? 0),
            'temp_spec_path' => (string) ($b['temp_spec_path'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $missingSources
     */
    private function classify(array $facts, array $missingSources): string
    {
        if ($missingSources !== []) {
            return self::STATUS_UNKNOWN;
        }
        if ((bool) $facts['queue']['safety_stop']) {
            return self::STATUS_DEGRADED;
        }
        if ((bool) $facts['heartbeat']['is_stale']) {
            return self::STATUS_DEGRADED;
        }
        if ((bool) $facts['merge']['blocked']) {
            return self::STATUS_DEGRADED;
        }
        if (! (bool) $facts['native_worker']['ready']) {
            return self::STATUS_DEGRADED;
        }
        if ((int) $facts['queue']['malformed_count'] > 0) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_HEALTHY;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $missingSources
     */
    private function snapshotHash(string $status, array $facts, array $missingSources): string
    {
        $canonical = json_encode([
            'status' => $status,
            'facts' => $facts,
            'missing_sources' => $missingSources,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'snapshot_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
