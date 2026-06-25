<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Deterministic state-and-heartbeat reducer for the Self-Construction runtime daemon.
 *
 * Pure: no filesystem, no process, no clock fall-through. Every transition is driven by the
 * supplied event and (when needed) an injectable `now_at` timestamp on the state or event.
 * Designed so the daemon binary can be tested without spawning processes or touching disk.
 *
 * State shape (the canonical fields):
 *   - status                : 'stopped'|'planned'|'running'|'paused'|'safety_stopped'|'degraded'
 *   - status_reason         : explicit reason for entering current status
 *   - last_heartbeat_at     : ISO-8601 string|null
 *   - last_cycle_receipt_hash: string|null
 *   - next_tick_allowed     : bool — derived from status + heartbeat + safety
 *   - safety_stop           : bool — sticky until an explicit reset event
 *   - heartbeat_max_age_s   : int — staleness threshold; default 180 (3 minutes)
 *   - pause_requested       : bool — sticky until resume/stop
 *   - stop_requested        : bool — sticky until plan/reset
 */
final class AtlasSelfConstructionRuntimeDaemonState
{
    public const SCHEMA = 'atlas.self_construction.runtime_daemon_state.v1';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SAFETY_STOPPED = 'safety_stopped';

    public const STATUS_DEGRADED = 'degraded';

    public const HEARTBEAT_FRESH = 'fresh';

    public const HEARTBEAT_STALE = 'stale';

    public const HEARTBEAT_MISSING = 'missing';

    public const DEFAULT_HEARTBEAT_MAX_AGE_S = 180;

    /**
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>  $event {type, reason?, receipt_hash?, now_at?, ...}
     * @return array<string,mixed>
     */
    public function reduce(array $state, array $event): array
    {
        $status = (string) ($state['status'] ?? self::STATUS_STOPPED);
        $reason = (string) ($state['status_reason'] ?? '');
        $lastHeartbeatAt = $state['last_heartbeat_at'] ?? null;
        $lastReceipt = $state['last_cycle_receipt_hash'] ?? null;
        $safetyStop = (bool) ($state['safety_stop'] ?? false);
        $pauseRequested = (bool) ($state['pause_requested'] ?? false);
        $stopRequested = (bool) ($state['stop_requested'] ?? false);
        $maxAge = (int) ($state['heartbeat_max_age_s'] ?? self::DEFAULT_HEARTBEAT_MAX_AGE_S);

        $type = (string) ($event['type'] ?? '');
        $eventReason = (string) ($event['reason'] ?? '');
        $eventNow = $event['now_at'] ?? ($state['now_at'] ?? null);

        switch ($type) {
            case 'plan':
                $status = self::STATUS_PLANNED;
                $reason = $eventReason !== '' ? $eventReason : 'plan_requested';
                $stopRequested = false;
                break;
            case 'tick_started':
                if (! $safetyStop && ! $stopRequested && ! $pauseRequested) {
                    $status = self::STATUS_RUNNING;
                    $reason = 'tick_started';
                }
                if ($eventNow !== null) {
                    $lastHeartbeatAt = (string) $eventNow;
                }
                break;
            case 'tick_completed':
                if (isset($event['receipt_hash'])) {
                    $lastReceipt = (string) $event['receipt_hash'];
                }
                if ($eventNow !== null) {
                    $lastHeartbeatAt = (string) $eventNow;
                }
                if ($status === self::STATUS_RUNNING) {
                    $status = self::STATUS_RUNNING;
                    $reason = 'tick_completed';
                }
                break;
            case 'pause_requested':
                $pauseRequested = true;
                $status = self::STATUS_PAUSED;
                $reason = $eventReason !== '' ? $eventReason : 'pause_requested';
                break;
            case 'resume':
                $pauseRequested = false;
                $status = self::STATUS_PLANNED;
                $reason = 'resumed';
                break;
            case 'stop_requested':
                $stopRequested = true;
                $status = self::STATUS_STOPPED;
                $reason = $eventReason !== '' ? $eventReason : 'stop_requested';
                break;
            case 'safety_stop':
                $safetyStop = true;
                $status = self::STATUS_SAFETY_STOPPED;
                $reason = $eventReason !== '' ? $eventReason : 'safety_stop';
                break;
            case 'safety_reset':
                $safetyStop = false;
                $status = self::STATUS_PLANNED;
                $reason = $eventReason !== '' ? $eventReason : 'safety_reset';
                break;
            case 'degraded':
                $status = self::STATUS_DEGRADED;
                $reason = $eventReason !== '' ? $eventReason : 'degraded';
                break;
            case 'heartbeat':
                if ($eventNow !== null) {
                    $lastHeartbeatAt = (string) $eventNow;
                }
                break;
            default:
                $reason = 'unknown_event:'.$type;
        }

        $heartbeatStatus = $this->heartbeatStatus($lastHeartbeatAt, $eventNow, $maxAge);
        if ($heartbeatStatus === self::HEARTBEAT_STALE && in_array($status, [self::STATUS_RUNNING, self::STATUS_PLANNED], true)) {
            $status = self::STATUS_DEGRADED;
            if ($reason === '') {
                $reason = 'heartbeat_stale';
            }
        }

        $nextTickAllowed = $this->nextTickAllowed($status, $safetyStop, $pauseRequested, $stopRequested, $heartbeatStatus);

        $next = [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'status_reason' => $reason,
            'safety_stop' => $safetyStop,
            'pause_requested' => $pauseRequested,
            'stop_requested' => $stopRequested,
            'last_heartbeat_at' => $lastHeartbeatAt,
            'last_cycle_receipt_hash' => $lastReceipt,
            'heartbeat_status' => $heartbeatStatus,
            'heartbeat_max_age_s' => $maxAge,
            'next_tick_allowed' => $nextTickAllowed,
            'now_at' => $eventNow !== null ? (string) $eventNow : ($state['now_at'] ?? null),
        ];
        $next['state_hash'] = $this->hash($next);

        return $next;
    }

    /**
     * @param  mixed  $lastHeartbeatAt
     * @param  mixed  $nowAt
     */
    private function heartbeatStatus($lastHeartbeatAt, $nowAt, int $maxAge): string
    {
        if ($lastHeartbeatAt === null || $lastHeartbeatAt === '') {
            return self::HEARTBEAT_MISSING;
        }
        if ($nowAt === null) {
            return self::HEARTBEAT_FRESH;
        }
        $last = strtotime((string) $lastHeartbeatAt);
        $now = strtotime((string) $nowAt);
        if ($last === false || $now === false) {
            return self::HEARTBEAT_MISSING;
        }
        if (($now - $last) > $maxAge) {
            return self::HEARTBEAT_STALE;
        }

        return self::HEARTBEAT_FRESH;
    }

    private function nextTickAllowed(string $status, bool $safety, bool $pause, bool $stop, string $heartbeatStatus): bool
    {
        if ($safety || $pause || $stop) {
            return false;
        }
        if ($status === self::STATUS_SAFETY_STOPPED || $status === self::STATUS_PAUSED || $status === self::STATUS_STOPPED) {
            return false;
        }
        if ($heartbeatStatus === self::HEARTBEAT_STALE) {
            return false;
        }

        return in_array($status, [self::STATUS_PLANNED, self::STATUS_RUNNING, self::STATUS_DEGRADED], true);
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function hash(array $state): string
    {
        $copy = $state;
        unset($copy['state_hash']);
        ksort($copy);

        return hash('sha256', (string) json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
