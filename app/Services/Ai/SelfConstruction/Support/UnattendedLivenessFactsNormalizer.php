<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Pure facts normalizer for unattended Self-Construction liveness snapshots.
 *
 * Peeled from {@see \App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedLivenessSnapshot}:
 * no I/O, no DB, no providers, no processes. Deterministic normalization,
 * heartbeat staleness, status classification, and snapshot hashing only.
 */
final class UnattendedLivenessFactsNormalizer
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNKNOWN = 'unknown';

    public const HEARTBEAT_STALE_THRESHOLD_SECONDS = 120;

    /**
     * @return array<string,mixed>
     */
    public static function normalizeQueue(mixed $queue): array
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
     * @param  array<int|string,mixed>  $leases
     * @return array<string,mixed>
     */
    public static function normalizeHeartbeat(mixed $heartbeat, array $leases): array
    {
        $hb = is_array($heartbeat) ? $heartbeat : [];

        return [
            'last_seen_age_seconds' => isset($hb['last_seen_age_seconds']) ? (int) $hb['last_seen_age_seconds'] : null,
            'stale_threshold_seconds' => (int) ($hb['stale_threshold_seconds'] ?? self::HEARTBEAT_STALE_THRESHOLD_SECONDS),
            'active_lease_count' => count($leases),
            'is_stale' => self::heartbeatIsStale($hb),
        ];
    }

    /**
     * @param  array<string,mixed>  $hb
     */
    public static function heartbeatIsStale(array $hb): bool
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
    public static function normalizeCycle(mixed $cycle): array
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
    public static function normalizeWorker(mixed $worker): array
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
    public static function normalizeReplenisher(mixed $replenisher): array
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
    public static function normalizeVerification(mixed $verification): array
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
    public static function normalizeMerge(mixed $merge): array
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
    public static function normalizeBrainQuota(mixed $brainQuota): array
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
    public static function classify(array $facts, array $missingSources): string
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
        if ((int) $facts['queue']['claimable_count'] === 0) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_HEALTHY;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $missingSources
     */
    public static function snapshotHash(string $status, array $facts, array $missingSources): string
    {
        $canonical = json_encode([
            'status' => $status,
            'facts' => $facts,
            'missing_sources' => $missingSources,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'snapshot_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
