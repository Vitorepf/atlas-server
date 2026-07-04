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

    private const QUALITY_GIVE_BACK_RATE_CEILING = 0.3;

    private const QUALITY_WEAK_GREEN_RATE_CEILING = 0.3;

    private const STARVATION_CONSECUTIVE_CONTENTION_THRESHOLD = 3;

    private const MAX_BACKOFF_SECONDS = 300;

    private const MIN_BACKOFF_SECONDS = 5;

    private const JITTER_BAND_SECONDS = 15;

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
        $recentGiveBackRate = (float) ($facts['recent_give_back_rate'] ?? 0.0);
        $weakGreenRate = (float) ($facts['weak_green_rate'] ?? 0.0);
        $consecutiveContentionRounds = (int) ($facts['consecutive_contention_rounds'] ?? 0);
        $workerClass = (string) ($facts['worker_class'] ?? 'default');
        $totalActiveWorkers = (int) ($facts['total_active_workers'] ?? 1);

        $reasonCodes = [];
        $qualityGateReasonCodes = [];
        $hasContention = false;
        $qualityBreached = false;

        if ($recentGiveBackRate > self::QUALITY_GIVE_BACK_RATE_CEILING) {
            $qualityGateReasonCodes[] = 'quality_gate:recent_give_back_rate='.$recentGiveBackRate;
            $qualityBreached = true;
        }
        if ($weakGreenRate > self::QUALITY_WEAK_GREEN_RATE_CEILING) {
            $qualityGateReasonCodes[] = 'quality_gate:weak_green_rate='.$weakGreenRate;
            $qualityBreached = true;
        }

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
            $delta = $activeLeases === 0
                ? 0
                : -max(1, (int) ceil($activeLeases / 2));
            $retryAfter = max(30, (int) round($averageTaskMinutes * 60 / 4));

            // Compute backoff with starvation prevention
            $backoffSeconds = $this->computeBackoffSeconds($consecutiveContentionRounds, $averageTaskMinutes);
            $jitterBand = $this->computeJitterBand($consecutiveContentionRounds);
            $fairnessReason = $this->computeFairnessReason($consecutiveContentionRounds, $workerClass, $totalActiveWorkers);

            return $this->result(
                self::DECISION_BACKOFF,
                $delta,
                $retryAfter,
                $reasonCodes,
                $qualityGateReasonCodes,
                $backoffSeconds,
                $jitterBand,
                $fairnessReason,
            );
        }

        $headroom = $servableNow - $activeLeases;
        if ($headroom >= self::SPAWN_HEADROOM_THRESHOLD) {
            // Queue headroom alone is not sufficient — recent worker quality caps whether Maestro
            // actually spawns more muscles, so a deep-but-unreliable queue does not compound failures.
            if ($qualityBreached) {
                $reasonCodes[] = 'headroom:servable_now_exceeds_active_leases='.$headroom;

                return $this->result(self::DECISION_HOLD_CURRENT, 0, 60, $reasonCodes, $qualityGateReasonCodes, 0, 0, '');
            }

            $reasonCodes[] = 'headroom:servable_now_exceeds_active_leases='.$headroom;

            return $this->result(self::DECISION_SPAWN_MORE, min($headroom, self::SPAWN_BURST_CAP), 0, $reasonCodes, $qualityGateReasonCodes, 0, 0, '');
        }

        $reasonCodes[] = 'stable:no_strong_signal';

        return $this->result(self::DECISION_HOLD_CURRENT, 0, 60, $reasonCodes, $qualityGateReasonCodes, 0, 0, '');
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $qualityGateReasonCodes
     * @return array<string, mixed>
     */
    private function result(
        string $decision,
        int $targetWorkerDelta,
        int $retryAfterSeconds,
        array $reasonCodes,
        array $qualityGateReasonCodes = [],
        int $backoffSeconds = 0,
        int $jitterBand = 0,
        string $fairnessReason = '',
    ): array {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'target_worker_delta' => $targetWorkerDelta,
            'retry_after_seconds' => $retryAfterSeconds,
            'reason_codes' => $reasonCodes,
            'quality_gate_reason_codes' => $qualityGateReasonCodes,
            'backoff_seconds' => $backoffSeconds,
            'jitter_band' => $jitterBand,
            'fairness_reason' => $fairnessReason,
        ];
    }

    /**
     * Compute backoff seconds with exponential scaling capped at MAX_BACKOFF_SECONDS.
     */
    private function computeBackoffSeconds(int $consecutiveContentionRounds, float $averageTaskMinutes): int
    {
        $base = max(self::MIN_BACKOFF_SECONDS, (int) round($averageTaskMinutes * 60 / 4));
        $scaled = $base * min(2 ** $consecutiveContentionRounds, 8);

        return min(self::MAX_BACKOFF_SECONDS, max(self::MIN_BACKOFF_SECONDS, $scaled));
    }

    /**
     * Compute jitter band to prevent thundering herd.
     */
    private function computeJitterBand(int $consecutiveContentionRounds): int
    {
        return min(self::JITTER_BAND_SECONDS * (1 + $consecutiveContentionRounds), self::MAX_BACKOFF_SECONDS / 2);
    }

    /**
     * Compute fairness reason: prevent one worker class from starving others.
     */
    private function computeFairnessReason(int $consecutiveContentionRounds, string $workerClass, int $totalActiveWorkers): string
    {
        if ($consecutiveContentionRounds >= self::STARVATION_CONSECUTIVE_CONTENTION_THRESHOLD) {
            return sprintf(
                'starvation_prevention:worker_class=%s consecutive_contention=%d total_workers=%d increasing_backoff_to_yield_queue',
                $workerClass,
                $consecutiveContentionRounds,
                $totalActiveWorkers,
            );
        }

        return '';
    }
}
