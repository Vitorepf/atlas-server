<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Concurrency;

/**
 * Pure, read-only worker-cadence advisor. Decides whether Maestro should spawn more workers, hold the
 * current count, or back off, based ONLY on contention evidence (recent commit failures, recent git
 * index-lock retries) and queue saturation (active leases already covering or exceeding servable work).
 * Deliberately does NOT take a generic "healthy" flag as input — a degraded health snapshot is not a
 * reason to stop spawning when servable_now is actually high; only real contention or saturation is.
 * No process spawning, sleeps, queue mutation, provider calls, or git commands — advisory facts only.
 */
final class AtlasMaestroLeaseContentionBackoffAdvisor
{
    public const SCHEMA = 'atlas.self_construction.maestro.lease_contention_backoff_advisor.v1';

    public const DECISION_SPAWN_MORE = 'spawn_more';

    public const DECISION_HOLD_CURRENT = 'hold_current';

    public const DECISION_BACKOFF = 'backoff';

    private const COMMIT_FAILURE_THRESHOLD = 2;

    private const INDEX_LOCK_RETRY_THRESHOLD = 3;

    private const SPAWN_HEADROOM_THRESHOLD = 2;

    private const SPAWN_BURST_CAP = 4;

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function advise(array $facts): array
    {
        $activeLeases = (int) ($facts['active_leases'] ?? 0);
        $servableNow = (int) ($facts['servable_now'] ?? 0);
        $recentCommitFailures = (int) ($facts['recent_commit_failures'] ?? 0);
        $recentIndexLockRetries = (int) ($facts['recent_index_lock_retries'] ?? 0);
        $averageTaskMinutes = (float) ($facts['average_task_minutes'] ?? 10.0);

        $reasonCodes = [];
        $hasContention = false;

        if ($recentCommitFailures >= self::COMMIT_FAILURE_THRESHOLD) {
            $reasonCodes[] = 'contention:commit_failures='.$recentCommitFailures;
            $hasContention = true;
        }
        if ($recentIndexLockRetries >= self::INDEX_LOCK_RETRY_THRESHOLD) {
            $reasonCodes[] = 'contention:index_lock_retries='.$recentIndexLockRetries;
            $hasContention = true;
        }

        $isSaturated = $servableNow <= $activeLeases;

        if ($hasContention || $isSaturated) {
            if ($isSaturated && ! $hasContention) {
                $reasonCodes[] = 'saturation:active_leases_ge_servable_now';
            }
            $delta = -max(1, (int) ceil($activeLeases / 2));
            $retryAfter = max(30, (int) round($averageTaskMinutes * 60 / 4));

            return $this->result(self::DECISION_BACKOFF, $delta, $retryAfter, $reasonCodes);
        }

        $headroom = $servableNow - $activeLeases;
        if ($headroom >= self::SPAWN_HEADROOM_THRESHOLD) {
            $reasonCodes[] = 'headroom:servable_now_exceeds_active_leases='.$headroom;

            return $this->result(self::DECISION_SPAWN_MORE, min($headroom, self::SPAWN_BURST_CAP), 0, $reasonCodes);
        }

        $reasonCodes[] = 'stable:no_strong_signal';

        return $this->result(self::DECISION_HOLD_CURRENT, 0, 60, $reasonCodes);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @return array<string, mixed>
     */
    private function result(string $decision, int $targetWorkerDelta, int $retryAfterSeconds, array $reasonCodes): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'target_worker_delta' => $targetWorkerDelta,
            'retry_after_seconds' => $retryAfterSeconds,
            'reason_codes' => $reasonCodes,
        ];
    }
}
