<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure forecaster: estimates how long the task queue can keep muscle workers
 * productive given current depth, throughput, blocked families, and replenishment latency.
 *
 * Effective capacity = servable_depth only (blocked packets are fully discounted).
 * hours_until_dry = servable_depth / throughput_per_hour.
 * replenish_by    = hours_until_dry − replenishment_latency_hours (negative = urgent).
 *
 * Fails closed (hours_until_dry=0, risk=critical) when:
 *   - throughput_per_hour <= 0 (missing)
 *   - throughput_data_age_seconds > THROUGHPUT_STALE_SECONDS
 */
final class AtlasSelfConstructionQueueContinuityForecaster
{
    public const SCHEMA = 'atlas.self_construction.queue_continuity_forecaster.v1';

    public const RISK_CRITICAL = 'critical';

    public const RISK_HIGH = 'high';

    public const RISK_MEDIUM = 'medium';

    public const RISK_LOW = 'low';

    public const THROUGHPUT_STALE_SECONDS = 3600;

    private const RISK_CRITICAL_HOURS = 1.0;

    private const RISK_HIGH_HOURS = 4.0;

    private const RISK_MEDIUM_HOURS = 8.0;

    private const BUFFER_HOURS = 4.0;

    private const DEFAULT_BATCH_SIZE = 10;

    public const CONTINUITY_REPLENISH_BEFORE_EMPTY = 'replenish_before_empty';

    public const CONTINUITY_STABLE = 'stable_continuity';

    private const DEFAULT_SAFETY_WINDOW_HOURS = 2.0;

    /**
     * @param  array<string,mixed>  $snapshot  claimable_depth, servable_depth, blocked_count,
     *                                          throughput_per_hour, throughput_data_age_seconds,
     *                                          replenishment_latency_seconds
     * @return array<string,mixed>
     */
    public function forecast(array $snapshot): array
    {
        $claimable = max(0, (int) ($snapshot['claimable_depth'] ?? 0));
        $servable = max(0, (int) ($snapshot['servable_depth'] ?? 0));
        $blocked = max(0, (int) ($snapshot['blocked_count'] ?? 0));
        $throughput = (float) ($snapshot['throughput_per_hour'] ?? 0.0);
        $throughputAge = max(0, (int) ($snapshot['throughput_data_age_seconds'] ?? 0));
        $replenishLatencySec = max(0, (int) ($snapshot['replenishment_latency_seconds'] ?? 0));

        $stale = $throughputAge > self::THROUGHPUT_STALE_SECONDS;
        $missing = $throughput <= 0.0;

        if ($stale || $missing) {
            $activeWorkerCount = max(0, (int) ($snapshot['active_worker_count'] ?? 0));
            $claimablePerActiveWorker = isset($snapshot['claimable_per_active_worker'])
                ? (float) $snapshot['claimable_per_active_worker']
                : null;
            $nearWorkerFloor = $activeWorkerCount > 0 && $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= 2.0;

            return $this->failClosed(
                $stale ? 'throughput_data_stale' : 'throughput_data_missing',
                $claimable, $servable, $blocked,
                $nearWorkerFloor, $activeWorkerCount, $claimablePerActiveWorker,
            );
        }

        $hoursUntilDry = $servable / $throughput;
        $replenishBy = $hoursUntilDry - ($replenishLatencySec / 3600.0);
        $recommendedBatch = max(1, (int) ceil($throughput * self::BUFFER_HOURS));
        $riskLevel = $this->risk($hoursUntilDry);

        $activeWorkerCount = max(0, (int) ($snapshot['active_worker_count'] ?? 0));
        $minimumClaimablePerWorker = max(0, (int) ($snapshot['minimum_claimable_per_worker'] ?? 0));
        $workerFloor = $activeWorkerCount * $minimumClaimablePerWorker;
        $workerFloorGap = max(0, $workerFloor - $servable);

        if ($workerFloorGap > 0) {
            $riskLevel = $this->escalateRisk($riskLevel, self::RISK_HIGH);
            $recommendedBatch = max($recommendedBatch, $workerFloorGap);
        }

        // Predicts time-to-no-claimable from active leases actually draining the claimable pool
        // (recent completed_dry_run velocity), independent of the servable-depth/throughput forecast
        // above — a queue can look fine on hours_until_dry while still about to run dry per-worker.
        $activeLeases = max(0, (int) ($snapshot['active_leases'] ?? 0));
        $completedDryRunPerHourPerLease = max(0.0, (float) ($snapshot['completed_dry_run_per_hour_per_lease'] ?? 0.0));
        $safetyWindowHours = (float) ($snapshot['safety_window_hours'] ?? self::DEFAULT_SAFETY_WINDOW_HOURS);
        $drainRatePerHour = $activeLeases * $completedDryRunPerHourPerLease;
        $timeToNoClaimableHours = $drainRatePerHour > 0.0 ? $claimable / $drainRatePerHour : null;
        $continuityStatus = ($timeToNoClaimableHours !== null && $timeToNoClaimableHours < $safetyWindowHours)
            ? self::CONTINUITY_REPLENISH_BEFORE_EMPTY
            : self::CONTINUITY_STABLE;

        return [
            'schema_version' => self::SCHEMA,
            'hours_until_dry' => round($hoursUntilDry, 2),
            'replenish_by' => round($replenishBy, 2),
            'risk_level' => $riskLevel,
            'recommended_originator_batch_size' => $recommendedBatch,
            'fail_closed' => false,
            'fail_closed_reason' => null,
            'discounted_capacity' => [
                'claimable' => $claimable,
                'servable' => $servable,
                'blocked' => $blocked,
            ],
            'worker_floor' => $workerFloor,
            'worker_floor_gap' => $workerFloorGap,
            'time_to_no_claimable_hours' => $timeToNoClaimableHours !== null ? round($timeToNoClaimableHours, 2) : null,
            'continuity_status' => $continuityStatus,
        ];
    }

    private function risk(float $hours): string
    {
        if ($hours <= self::RISK_CRITICAL_HOURS) {
            return self::RISK_CRITICAL;
        }
        if ($hours <= self::RISK_HIGH_HOURS) {
            return self::RISK_HIGH;
        }
        if ($hours <= self::RISK_MEDIUM_HOURS) {
            return self::RISK_MEDIUM;
        }

        return self::RISK_LOW;
    }

    /** Never downgrades risk — only raises it to at least $atLeast. */
    private function escalateRisk(string $current, string $atLeast): string
    {
        $order = [self::RISK_LOW => 0, self::RISK_MEDIUM => 1, self::RISK_HIGH => 2, self::RISK_CRITICAL => 3];

        return ($order[$current] ?? 0) >= ($order[$atLeast] ?? 0) ? $current : $atLeast;
    }

    private function failClosed(
        string $reason,
        int $claimable,
        int $servable,
        int $blocked,
        bool $nearWorkerFloor = false,
        int $activeWorkerCount = 0,
        ?float $claimablePerActiveWorker = null,
    ): array {
        $riskLevel = self::RISK_CRITICAL;
        $continuityStatus = self::CONTINUITY_STABLE;
        $recommendedBatch = self::DEFAULT_BATCH_SIZE;

        if ($nearWorkerFloor) {
            $riskLevel = $claimablePerActiveWorker <= 0.0 ? self::RISK_CRITICAL : self::RISK_HIGH;
            $continuityStatus = self::CONTINUITY_REPLENISH_BEFORE_EMPTY;
            $gap = max(0, (int) ceil(($activeWorkerCount * 2) - ($claimablePerActiveWorker * $activeWorkerCount)));
            $recommendedBatch = max(self::DEFAULT_BATCH_SIZE, $gap);
        }

        return [
            'schema_version' => self::SCHEMA,
            'hours_until_dry' => 0.0,
            'replenish_by' => null,
            'risk_level' => $riskLevel,
            'recommended_originator_batch_size' => $recommendedBatch,
            'fail_closed' => true,
            'fail_closed_reason' => $reason,
            'discounted_capacity' => [
                'claimable' => $claimable,
                'servable' => $servable,
                'blocked' => $blocked,
            ],
            'continuity_status' => $continuityStatus,
        ];
    }
}
