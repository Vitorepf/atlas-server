<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

/**
 * Pure compiler — translates queue depth, servable_now, active workers, throughput samples,
 * give_back rate, and claim latency into deterministic SLO verdicts for continuous muscle
 * operation. Tells the Maestro whether the queue will keep workers fed (servable supply),
 * roughly when it will drain, whether give_backs are amplifying the backlog, and whether
 * claim latency is within budget.
 *
 * INPUT FACTS (each optional, sane defaults applied):
 *   queue_depth:            int     — pending task_packet count (default 0)
 *   servable_now:           int     — claimable-right-now count (default 0)
 *   active_workers:         int     — currently-active worker count (default 0)
 *   throughput_samples:     list<float> — recent completed-tasks-per-hour samples (default [])
 *   give_back_rate:         float 0-1 — fraction of attempts that give_back (default 0.0)
 *   claim_latency_seconds:  float   — average time-to-claim (default 0.0)
 *
 * OUTPUT (FACTS only — no scalar quality score):
 *   {schema_version, status, verdicts, blockers}
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasMaestroDrainContinuitySloCompiler
{
    public const SCHEMA = 'atlas.self_construction.maestro.drain_continuity_slo.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_CRITICAL = 'critical';

    private const CLAIM_LATENCY_SLO_SECONDS = 30.0;

    private const GIVE_BACK_RATE_RISK_THRESHOLD = 0.3;

    private const DRAIN_ETA_AT_RISK_HOURS = 24.0;

    public const VERDICT_SUFFICIENT_DEPTH = 'sufficient_depth';
    public const VERDICT_WATCH = 'watch';
    public const VERDICT_REPLENISH_SOON = 'replenish_soon';
    public const VERDICT_TELEMETRY_BLIND = 'telemetry_blind';

    /** claimable_per_active_worker at/below this is a worker-floor breach. */
    private const PROJECTION_WORKER_FLOOR_THRESHOLD = 2.0;

    /** claimable_per_active_worker at/above this is comfortably sufficient. */
    private const PROJECTION_SUFFICIENT_DEPTH_THRESHOLD = 5.0;

    public const REASON_WORKER_FLOOR = 'worker_floor';

    public const ORIGINATOR_ACTION_ORIGINATE_NOW = 'originate_now';
    public const ORIGINATOR_ACTION_FIX_TELEMETRY = 'fix_telemetry';
    public const ORIGINATOR_ACTION_REPAIR_GIVE_BACK_LOOP = 'repair_give_back_loop';
    public const ORIGINATOR_ACTION_MONITOR = 'monitor';

    private const MINIMUM_TASK_QUALITY_FLOOR = 0.7;

    private const RECOVERABLE_LEASE_PRESSURE_THRESHOLD = 1;

    private const MALFORMED_PRESSURE_THRESHOLD = 1;

    /**
     * Consumes Maestro projection facts (claimable_per_active_worker, active_workers,
     * telemetry_confidence) and compiles a single, actionable SLO verdict — without adding
     * a new policy layer. Priority: telemetry trust comes first (a number you can't trust
     * is worse than no number), then the worker floor, then comfortable depth, else watch.
     *
     * @param  array{claimable_per_active_worker?: float, active_workers?: int, telemetry_confidence?: string|float}  $projectionFacts
     * @return array{schema_version:string, verdict:string, reason:?string, claimable_per_active_worker:?float, active_workers:int}
     */
    public function compileFromProjection(array $projectionFacts): array
    {
        $activeWorkers = max(0, (int) ($projectionFacts['active_workers'] ?? 0));
        $telemetryConfidence = $projectionFacts['telemetry_confidence'] ?? null;
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $projectionFacts) && $projectionFacts['claimable_per_active_worker'] !== null
            ? (float) $projectionFacts['claimable_per_active_worker']
            : null;

        $isTelemetryBlind = $activeWorkers > 0 && (string) $telemetryConfidence === 'blind';

        if ($isTelemetryBlind) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_TELEMETRY_BLIND,
                'reason' => 'telemetry_confidence_blind_with_active_workers',
                'claimable_per_active_worker' => $claimablePerActiveWorker,
                'active_workers' => $activeWorkers,
            ];
        }

        if ($claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::PROJECTION_WORKER_FLOOR_THRESHOLD) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_REPLENISH_SOON,
                'reason' => self::REASON_WORKER_FLOOR,
                'claimable_per_active_worker' => $claimablePerActiveWorker,
                'active_workers' => $activeWorkers,
            ];
        }

        if ($claimablePerActiveWorker !== null && $claimablePerActiveWorker >= self::PROJECTION_SUFFICIENT_DEPTH_THRESHOLD) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_SUFFICIENT_DEPTH,
                'reason' => null,
                'claimable_per_active_worker' => $claimablePerActiveWorker,
                'active_workers' => $activeWorkers,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => self::VERDICT_WATCH,
            'reason' => null,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
            'active_workers' => $activeWorkers,
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function compile(array $facts): array
    {
        $queueDepth = max(0, (int) ($facts['queue_depth'] ?? 0));
        $servableNow = max(0, (int) ($facts['servable_now'] ?? 0));
        $activeWorkers = max(0, (int) ($facts['active_workers'] ?? 0));
        $throughputSamples = array_values(array_filter(
            (array) ($facts['throughput_samples'] ?? []),
            static fn (mixed $v): bool => is_numeric($v),
        ));
        $ewmaAlpha = max(0.01, min(0.99, (float) ($facts['ewma_alpha'] ?? 0.3)));
        $avgThroughput = self::ewma($throughputSamples, $ewmaAlpha);
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['give_back_rate'] ?? 0.0)));
        $claimLatencySeconds = max(0.0, (float) ($facts['claim_latency_seconds'] ?? 0.0));
        $telemetryConfidence = $facts['telemetry_confidence'] ?? null;
        $minimumTaskQuality = max(0.0, min(1.0, (float) ($facts['minimum_task_quality'] ?? 1.0)));
        $recoverableLeases = max(0, (int) ($facts['recoverable_leases'] ?? $facts['recoverable_lease_count'] ?? 0));
        $malformedCount = max(0, (int) ($facts['malformed_count'] ?? $facts['malformed_tasks'] ?? 0));
        $isTelemetryBlind = $activeWorkers > 0 && (string) $telemetryConfidence === 'blind';
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $facts) && $facts['claimable_per_active_worker'] !== null
            ? (float) $facts['claimable_per_active_worker']
            : null;
        $isWorkerFloorBreach = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::PROJECTION_WORKER_FLOOR_THRESHOLD;

        $blockers = [];
        $verdicts = [];

        // 1. Servable supply: can workers always find claimable work?
        if ($queueDepth > 0 && $servableNow === 0) {
            $supplyVerdict = 'starved';
            $blockers[] = 'queue_starved_no_servable_work';
        } elseif ($activeWorkers > 0 && $servableNow < $activeWorkers) {
            $supplyVerdict = 'at_risk';
        } else {
            $supplyVerdict = 'healthy';
        }
        $verdicts['servable_supply'] = $supplyVerdict;

        // 2. Drain ETA: roughly when will the current backlog be cleared at observed throughput.
        if ($avgThroughput > 0.0) {
            $etaHours = $queueDepth / $avgThroughput;
            $drainVerdict = $etaHours > self::DRAIN_ETA_AT_RISK_HOURS ? 'at_risk' : 'healthy';
            if ($drainVerdict === 'at_risk') {
                $blockers[] = 'drain_eta_exceeds_slo_window';
            }
        } elseif ($queueDepth > 0) {
            $etaHours = null;
            $drainVerdict = 'unknown_zero_throughput';
            $blockers[] = 'zero_throughput_with_pending_queue';
        } else {
            $etaHours = 0.0;
            $drainVerdict = 'healthy';
        }
        $verdicts['drain_eta'] = $drainVerdict;
        $verdicts['drain_eta_hours'] = $etaHours;

        // 3. Give_back amplification: a high give_back rate re-queues work and inflates the
        //    effective backlog beyond what queue_depth alone shows.
        $giveBackVerdict = $giveBackRate >= self::GIVE_BACK_RATE_RISK_THRESHOLD ? 'amplifying_risk' : 'healthy';
        if ($giveBackVerdict === 'amplifying_risk') {
            $blockers[] = 'give_back_rate_amplifying_queue';
        }
        $verdicts['give_back_amplification'] = $giveBackVerdict;

        // 4. Claim latency SLO.
        $claimVerdict = $claimLatencySeconds > self::CLAIM_LATENCY_SLO_SECONDS ? 'breached' : 'within_slo';
        if ($claimVerdict === 'breached') {
            $blockers[] = 'claim_latency_slo_breached';
        }
        $verdicts['claim_latency'] = $claimVerdict;

        $qualityVerdict = $minimumTaskQuality >= self::MINIMUM_TASK_QUALITY_FLOOR ? 'meets_floor' : 'below_floor';
        if ($qualityVerdict === 'below_floor') {
            $blockers[] = 'minimum_task_quality_below_floor';
        }
        $verdicts['minimum_task_quality'] = $qualityVerdict;
        $verdicts['minimum_task_quality_floor'] = self::MINIMUM_TASK_QUALITY_FLOOR;

        $recoverablePressure = $recoverableLeases >= self::RECOVERABLE_LEASE_PRESSURE_THRESHOLD ? 'present' : 'clear';
        if ($recoverablePressure === 'present') {
            $blockers[] = 'recoverable_lease_pressure';
        }
        $verdicts['recoverable_lease_pressure'] = $recoverablePressure;

        $malformedPressure = $malformedCount >= self::MALFORMED_PRESSURE_THRESHOLD ? 'present' : 'clear';
        if ($malformedPressure === 'present') {
            $blockers[] = 'malformed_pressure';
        }
        $verdicts['malformed_pressure'] = $malformedPressure;

        if ($isWorkerFloorBreach) {
            $blockers[] = 'worker_floor_breach';
        }
        if ($isTelemetryBlind) {
            $blockers[] = 'telemetry_confidence_blind_with_active_workers';
        }

        $status = match (true) {
            $supplyVerdict === 'starved' => self::STATUS_CRITICAL,
            $blockers !== [] => self::STATUS_AT_RISK,
            default => self::STATUS_HEALTHY,
        };

        // hours_to_starvation: how long until servable_now drops to zero at current drain rate
        $hoursToStarvation = $this->computeHoursToStarvation($servableNow, $avgThroughput, $activeWorkers);

        // evidence_confidence: downgraded when throughput telemetry is sparse or self-reported
        $evidenceConfidence = $this->computeEvidenceConfidence($throughputSamples, $telemetryConfidence, $facts);

        // recommended_replenish_window: when to replenish based on hours_to_starvation
        $recommendedReplenishWindow = $this->computeRecommendedReplenishWindow($hoursToStarvation);

        // slo_status: derived from status + evidence_confidence
        $sloStatus = $this->computeSloStatus($status, $evidenceConfidence);

        $originatorAction = match (true) {
            $isWorkerFloorBreach || $supplyVerdict === 'starved' => self::ORIGINATOR_ACTION_ORIGINATE_NOW,
            $giveBackVerdict === 'amplifying_risk' => self::ORIGINATOR_ACTION_REPAIR_GIVE_BACK_LOOP,
            $isTelemetryBlind => self::ORIGINATOR_ACTION_FIX_TELEMETRY,
            default => self::ORIGINATOR_ACTION_MONITOR,
        };

        return [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'slo_status' => $sloStatus,
            'hours_to_starvation' => $hoursToStarvation,
            'evidence_confidence' => $evidenceConfidence,
            'recommended_replenish_window' => $recommendedReplenishWindow,
            'verdicts' => $verdicts,
            'blockers' => $blockers,
            'originator_action' => $originatorAction,
            'continuity' => [
                'drain_horizon_hours' => $etaHours,
                'minimum_task_quality' => $minimumTaskQuality,
                'minimum_task_quality_floor' => self::MINIMUM_TASK_QUALITY_FLOOR,
                'recoverable_lease_pressure' => $recoverablePressure,
                'malformed_pressure' => $malformedPressure,
                'worker_count' => $activeWorkers,
                'recommended_action' => $originatorAction,
            ],
        ];
    }

    /**
     * Compute hours until servable_now drops to zero.
     */
    private function computeHoursToStarvation(int $servableNow, float $avgThroughput, int $activeWorkers): ?float
    {
        if ($servableNow <= 0) {
            return 0.0;
        }

        // Net drain rate: throughput minus worker consumption (workers consume ~1 task each)
        $netDrainRate = $avgThroughput - $activeWorkers;
        if ($netDrainRate <= 0) {
            // Workers are consuming faster than throughput replenishes — starvation imminent
            return $avgThroughput > 0 ? $servableNow / $avgThroughput : null;
        }

        return round($servableNow / $netDrainRate, 2);
    }

    /**
     * Compute evidence confidence from throughput telemetry quality.
     *
     * @param  list<float>  $throughputSamples
     * @param  mixed  $telemetryConfidence
     * @param  array<string, mixed>  $facts
     */
    private function computeEvidenceConfidence(array $throughputSamples, mixed $telemetryConfidence, array $facts): string
    {
        // Telemetry is blind — lowest confidence
        if ((string) $telemetryConfidence === 'blind') {
            return 'blind';
        }

        // Self-reported only — low confidence
        $isSelfReported = (bool) ($facts['throughput_self_reported'] ?? false);
        if ($isSelfReported && $throughputSamples === []) {
            return 'self_reported_only';
        }

        // Sparse samples (< 3) — moderate confidence
        if (count($throughputSamples) < 3) {
            return 'sparse';
        }

        // Good sample count — high confidence
        return 'high';
    }

    /**
     * Compute recommended replenish window from hours_to_starvation.
     */
    private function computeRecommendedReplenishWindow(?float $hoursToStarvation): string
    {
        if ($hoursToStarvation === null) {
            return 'unknown_insufficient_data';
        }

        if ($hoursToStarvation <= 0) {
            return 'immediate';
        }

        if ($hoursToStarvation <= 2) {
            return 'within_2_hours';
        }

        if ($hoursToStarvation <= 8) {
            return 'within_8_hours';
        }

        return 'within_24_hours';
    }

    /**
     * Compute SLO status from base status and evidence confidence.
     */
    private function computeSloStatus(string $status, string $evidenceConfidence): string
    {
        // Blind or self-reported evidence downgrades any status
        if ($evidenceConfidence === 'blind') {
            return 'slo_unverifiable';
        }

        if ($evidenceConfidence === 'self_reported_only') {
            return 'slo_low_confidence';
        }

        if ($evidenceConfidence === 'sparse') {
            return 'slo_moderate_confidence';
        }

        // High confidence — trust the base status
        return match ($status) {
            self::STATUS_CRITICAL => 'slo_breached',
            self::STATUS_AT_RISK => 'slo_at_risk',
            default => 'slo_met',
        };
    }

    /**
     * Exponentially-weighted moving average over a time-ordered series.
     * Recent samples dominate the estimate. Uses alpha=0.3 by default.
     *
     * @param  list<float|int>  $samples  time-ordered: index 0 = oldest, last = most recent
     */
    public static function ewma(array $samples, float $alpha = 0.3): float
    {
        if ($samples === []) {
            return 0.0;
        }
        $ewma = (float) $samples[0];
        $count = count($samples);
        for ($i = 1; $i < $count; $i++) {
            $ewma = $alpha * (float) $samples[$i] + (1 - $alpha) * $ewma;
        }

        return round($ewma, 2);
    }
}
