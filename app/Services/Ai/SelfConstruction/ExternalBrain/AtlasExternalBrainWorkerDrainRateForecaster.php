<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure forecaster. Estimates how fast the CURRENT muscles can actually
 * consume the queue, so the originator never creates work faster than the
 * implementation system can prove it.
 *
 * "Never treats active leases alone as proven throughput" (AC3): with zero
 * recent_successes, estimated_drain_per_hour is forced to 0.0 regardless of
 * how many active_leases exist — a lease is a claim, not a proof of
 * delivery.
 *
 * estimated_drain_per_hour = active_leases × (60 / median_task_minutes) × success_ratio,
 *   where success_ratio = recent_successes / (recent_successes + recent_give_backs),
 *   and the whole term is zeroed when recent_successes = 0.
 *
 * confidence is downgraded when data is sparse (sample < 5) or the
 * give_back rate is high (> 0.30), and forced to 'low' whenever there are
 * no active workers or zero proven successes.
 *
 * INPUT:
 *   active_leases?:        int
 *   recent_successes?:     int
 *   recent_give_backs?:    int
 *   median_task_minutes?:  float
 *   queue_depth?:          int
 *   claimed_records?:      int
 *
 * Pure: no I/O, no side effects, never creates or claims tasks.
 */
final class AtlasExternalBrainWorkerDrainRateForecaster
{
    public const SCHEMA = 'atlas.external_brain.worker_drain_rate_forecaster.v1';

    private const SPARSE_SAMPLE_FLOOR = 5;
    private const HIGH_SAMPLE_FLOOR = 10;
    private const HIGH_GIVE_BACK_CEILING = 0.30;
    private const LOW_GIVE_BACK_CEILING = 0.20;
    private const FAST_CLEAR_HOURS_CEILING = 2.0;

    private const DEFAULT_SUFFICIENT_DEPTH_FLOOR = 10;

    public const PROJECTION_RECOMMENDATION_WAIT = 'wait';
    public const PROJECTION_RECOMMENDATION_REPLENISH = 'replenish';
    public const PROJECTION_RECOMMENDATION_FIX_PROJECTION_TELEMETRY = 'fix_projection_telemetry';

    /**
     * Consumes Maestro projection facts (queue depth + telemetry_confidence from
     * {@see \App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroMuscleThroughputContinuityModel})
     * to decide whether the external brain should wait, replenish, or fix its own telemetry first.
     *
     * DECISION PRIORITY (first match wins):
     *   1. replenish                  — queue_depth below sufficient_depth_floor, REGARDLESS of
     *                                    telemetry mode (a thin queue is a thin queue either way).
     *   2. fix_projection_telemetry   — depth looks sufficient but telemetry_confidence='blind':
     *                                    a blind spot must never be silently read as "safe to wait".
     *   3. wait                       — depth sufficient AND telemetry is not blind.
     *
     * @param  array<string,mixed>  $projectionFacts
     *         queue_depth?:             int
     *         sufficient_depth_floor?:  int
     *         telemetry_confidence?:    string  'direct'|'estimated'|'blind'
     * @return array<string,mixed>
     */
    public function recommendFromProjection(array $projectionFacts): array
    {
        $queueDepth = max(0, (int) ($projectionFacts['queue_depth'] ?? 0));
        $sufficientDepthFloor = max(1, (int) ($projectionFacts['sufficient_depth_floor'] ?? self::DEFAULT_SUFFICIENT_DEPTH_FLOOR));
        $telemetryConfidence = (string) ($projectionFacts['telemetry_confidence'] ?? 'blind');

        $depthSufficient = $queueDepth >= $sufficientDepthFloor;

        [$recommendation, $reason] = match (true) {
            ! $depthSufficient => [self::PROJECTION_RECOMMENDATION_REPLENISH, "queue_depth={$queueDepth}_below_floor={$sufficientDepthFloor}"],
            $telemetryConfidence === 'blind' => [self::PROJECTION_RECOMMENDATION_FIX_PROJECTION_TELEMETRY, 'telemetry_confidence_blind_cannot_trust_wait'],
            default => [self::PROJECTION_RECOMMENDATION_WAIT, "queue_depth={$queueDepth}_sufficient_and_telemetry={$telemetryConfidence}"],
        };

        return [
            'schema' => self::SCHEMA,
            'recommendation' => $recommendation,
            'reason' => $reason,
            'queue_depth' => $queueDepth,
            'sufficient_depth_floor' => $sufficientDepthFloor,
            'telemetry_confidence' => $telemetryConfidence,
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function forecast(array $facts): array
    {
        $activeLeases = max(0, (int) ($facts['active_leases'] ?? 0));
        $recentSuccesses = max(0, (int) ($facts['recent_successes'] ?? 0));
        $recentGiveBacks = max(0, (int) ($facts['recent_give_backs'] ?? 0));
        $medianTaskMinutes = (float) ($facts['median_task_minutes'] ?? 0.0);
        $queueDepth = max(0, (int) ($facts['queue_depth'] ?? 0));
        $claimedRecords = max(0, (int) ($facts['claimed_records'] ?? 0));

        $sampleSize = $recentSuccesses + $recentGiveBacks;
        $successRatio = $sampleSize > 0 ? $recentSuccesses / $sampleSize : 0.0;
        $giveBackRate = $sampleSize > 0 ? round($recentGiveBacks / $sampleSize, 4) : 0.0;

        $provenThroughputPerWorkerPerHour = ($medianTaskMinutes > 0 && $recentSuccesses > 0)
            ? (60.0 / $medianTaskMinutes) * $successRatio
            : 0.0;

        // AC3 — active leases alone are never proof of throughput.
        $estimatedDrainPerHour = $recentSuccesses > 0
            ? round($activeLeases * $provenThroughputPerWorkerPerHour, 4)
            : 0.0;

        $hoursToClearClaimable = $estimatedDrainPerHour > 0.0
            ? round($queueDepth / $estimatedDrainPerHour, 2)
            : null;

        $confidence = $this->confidence($activeLeases, $recentSuccesses, $sampleSize, $giveBackRate);
        $bottleneckReason = $this->bottleneckReason($activeLeases, $recentSuccesses, $sampleSize, $giveBackRate);
        $recommendedPace = $this->recommendedPace($confidence, $giveBackRate, $hoursToClearClaimable);

        return [
            'schema' => self::SCHEMA,
            'estimated_drain_per_hour' => $estimatedDrainPerHour,
            'hours_to_clear_claimable' => $hoursToClearClaimable,
            'confidence' => $confidence,
            'bottleneck_reason' => $bottleneckReason,
            'recommended_originator_pace' => $recommendedPace,
            'inputs' => [
                'active_leases' => $activeLeases,
                'recent_successes' => $recentSuccesses,
                'recent_give_backs' => $recentGiveBacks,
                'median_task_minutes' => $medianTaskMinutes,
                'queue_depth' => $queueDepth,
                'claimed_records' => $claimedRecords,
                'give_back_rate' => $giveBackRate,
            ],
        ];
    }

    private function confidence(int $activeLeases, int $recentSuccesses, int $sampleSize, float $giveBackRate): string
    {
        if ($activeLeases === 0 || $recentSuccesses === 0) {
            return 'low';
        }
        if ($sampleSize < self::SPARSE_SAMPLE_FLOOR || $giveBackRate > self::HIGH_GIVE_BACK_CEILING) {
            return 'medium';
        }
        if ($sampleSize >= self::HIGH_SAMPLE_FLOOR && $giveBackRate <= self::LOW_GIVE_BACK_CEILING) {
            return 'high';
        }

        return 'medium';
    }

    private function bottleneckReason(int $activeLeases, int $recentSuccesses, int $sampleSize, float $giveBackRate): string
    {
        return match (true) {
            $activeLeases === 0 => 'no_active_workers',
            $recentSuccesses === 0 => 'no_proven_throughput_yet',
            $sampleSize < self::SPARSE_SAMPLE_FLOOR => 'insufficient_sample_data',
            $giveBackRate > self::HIGH_GIVE_BACK_CEILING => 'high_give_back_rate',
            default => 'none',
        };
    }

    private function recommendedPace(string $confidence, float $giveBackRate, ?float $hoursToClearClaimable): string
    {
        if ($confidence === 'low') {
            return 'pause_origination';
        }
        if ($giveBackRate > self::HIGH_GIVE_BACK_CEILING) {
            return 'throttle_origination';
        }
        if ($confidence === 'high' && $hoursToClearClaimable !== null && $hoursToClearClaimable < self::FAST_CLEAR_HOURS_CEILING) {
            return 'increase_origination';
        }

        return 'maintain_pace';
    }
}
