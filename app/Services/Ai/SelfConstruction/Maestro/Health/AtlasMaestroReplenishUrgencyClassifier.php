<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

final class AtlasMaestroReplenishUrgencyClassifier
{
    public const SCHEMA = 'atlas.maestro.health.replenish_urgency.v1';

    public function __construct(
        private readonly ?object $queueAgeHistogram = null,
        private readonly ?object $leaseLifetimeHistogram = null,
        private readonly ?object $workerIdlePredictor = null,
        private readonly int $thresholdHighSeconds = 300,
        private readonly int $thresholdMidSeconds = 1800,
        private readonly int $thresholdStaleClaimableAgeSeconds = 3600,
        private readonly int $thresholdLowClaimableDepth = 5,
        private readonly int $thresholdMinActiveWorkers = 2,
        private readonly int $thresholdHighStuckLeases = 3,
        private readonly float $thresholdWorkerRatioHigh = 2.0,
    ) {}

    /**
     * @return array{
     *     schema:string,
     *     urgency:string,
     *     reasons:list<string>,
     *     inputs:array<string,int|float|null>
     * }
     */
    public function classify(): array
    {
        $queueAge = $this->facts($this->queueAgeObject(), 'histogram');
        $leaseLifetime = $this->facts($this->leaseLifetimeObject(), 'histogram');
        $idle = $this->facts($this->workerIdleObject(), 'project');

        $claimableDepth = $this->intFact($idle, 'claimable_depth', $this->intFact($queueAge, 'total_claimable'));
        $secondsUntilDry = $this->nullableIntFact($idle, 'seconds_until_dry');
        $p95ClaimableAge = $this->intFact($queueAge, 'p95_seconds');
        $suspectedStuckLeases = $this->intFact($leaseLifetime, 'suspected_stuck_count');
        // Accept 'active_workers' when 'active_claimed_workers' is absent, so the live active-worker
        // count is never silently read as zero just because the caller used the other key name.
        $activeClaimedWorkers = array_key_exists('active_claimed_workers', $idle)
            ? $this->intFact($idle, 'active_claimed_workers')
            : $this->intFact($idle, 'active_workers');
        $poisonPressure = $this->intFact($idle, 'poison_pressure');

        $inputs = [
            'claimable_depth' => $claimableDepth,
            'serve_rate_per_minute' => $this->floatFact($idle, 'serve_rate_per_minute'),
            'seconds_until_dry' => $secondsUntilDry,
            'oldest_claimable_seconds' => $this->intFact($queueAge, 'oldest_seconds'),
            'p95_claimable_age_seconds' => $p95ClaimableAge,
            'p95_lease_lifetime_seconds' => $this->intFact($leaseLifetime, 'p95_seconds'),
            'suspected_stuck_leases' => $suspectedStuckLeases,
            'active_claimed_workers' => $activeClaimedWorkers,
            'poison_pressure' => $poisonPressure,
            'threshold_high_seconds' => $this->thresholdHighSeconds,
            'threshold_mid_seconds' => $this->thresholdMidSeconds,
            'threshold_stale_claimable_age_seconds' => $this->thresholdStaleClaimableAgeSeconds,
        ];

        $reasons = [];
        if ($claimableDepth === 0) {
            $reasons[] = 'queue_dry';
        }
        if ($secondsUntilDry !== null && $secondsUntilDry < $this->thresholdHighSeconds) {
            $reasons[] = 'seconds_until_dry_below_threshold_high';
        }
        // Low claimable depth while workers are actively competing → muscles will starve before dry.
        if ($claimableDepth > 0 && $claimableDepth <= $this->thresholdLowClaimableDepth && $activeClaimedWorkers >= $this->thresholdMinActiveWorkers) {
            $reasons[] = 'low_claimable_depth_with_active_worker_pressure';
        }
        // Many stuck leases directly choke throughput.
        if ($suspectedStuckLeases >= $this->thresholdHighStuckLeases) {
            $reasons[] = 'high_stuck_lease_threat';
        }
        // Primary starvation signal: claimable supply per active worker. Even when the raw
        // claimable_depth looks fine, near-1-per-worker means active muscles are about to run
        // out of claimable work before any seconds_until_dry/p95-age signal catches up.
        if ($activeClaimedWorkers > 0 && ($claimableDepth / $activeClaimedWorkers) <= $this->thresholdWorkerRatioHigh) {
            $reasons[] = 'claimable_depth_near_one_per_active_worker';
        }
        if ($reasons !== []) {
            return $this->resultWithAction('HIGH', $this->nextAction($reasons, $suspectedStuckLeases, $poisonPressure, $claimableDepth, $secondsUntilDry), $reasons, $inputs);
        }

        if ($secondsUntilDry !== null && $secondsUntilDry < $this->thresholdMidSeconds) {
            $reasons[] = 'seconds_until_dry_below_threshold_mid';
        }
        if ($p95ClaimableAge > $this->thresholdStaleClaimableAgeSeconds) {
            $reasons[] = 'p95_claimable_age_above_threshold_stale';
        }
        // Any stuck lease or poison packet reduces effective throughput → surface at MID.
        if ($suspectedStuckLeases > 0 && $suspectedStuckLeases < $this->thresholdHighStuckLeases) {
            $reasons[] = 'suspected_stuck_leases_threaten_throughput';
        }
        if ($poisonPressure > 0) {
            $reasons[] = 'poison_pressure_detected';
        }
        if ($reasons !== []) {
            return $this->resultWithAction('MID', $this->nextAction($reasons, $suspectedStuckLeases, $poisonPressure, $claimableDepth, $secondsUntilDry), $reasons, $inputs);
        }

        return $this->resultWithAction('LOW', 'monitor_idle_supply', ['no_replenish_pressure'], $inputs);
    }

    /**
     * Determine the recommended next action from the collected reasons and facts.
     *
     * Priority: drain_poison → unblock → originate → monitor.
     *
     * Stale p95 claimable age ALONE — no stuck leases, no known dry ETA, and a healthy (non-low)
     * claimable depth — is MID-urgency VISIBILITY only. It must not trigger 'originate': an old
     * backlog that is otherwise healthy needs draining/monitoring, not more origination pressure.
     */
    private function nextAction(array $reasons, int $stuckLeases, int $poisonPressure, int $claimableDepth, ?int $secondsUntilDry): string
    {
        if ($poisonPressure > 0 && (in_array('poison_pressure_detected', $reasons, true) || $poisonPressure >= 3)) {
            return 'drain_poison';
        }
        if ($stuckLeases > 0 || in_array('high_stuck_lease_threat', $reasons, true) || in_array('suspected_stuck_leases_threaten_throughput', $reasons, true)) {
            return 'unblock';
        }
        if (in_array('queue_dry', $reasons, true)
            || in_array('low_claimable_depth_with_active_worker_pressure', $reasons, true)
            || in_array('claimable_depth_near_one_per_active_worker', $reasons, true)
            || in_array('seconds_until_dry_below_threshold_high', $reasons, true)
            || in_array('seconds_until_dry_below_threshold_mid', $reasons, true)) {
            return 'originate';
        }

        $onlyStaleAgeReason = $reasons === ['p95_claimable_age_above_threshold_stale'];
        if ($onlyStaleAgeReason
            && $stuckLeases === 0
            && $secondsUntilDry === null
            && $claimableDepth > $this->thresholdLowClaimableDepth) {
            return 'monitor_idle_supply';
        }

        return 'originate';
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,int|float|null>  $inputs
     * @return array{schema:string, urgency:string, next_action:string, reasons:list<string>, inputs:array<string,int|float|null>}
     */
    private function resultWithAction(string $urgency, string $nextAction, array $reasons, array $inputs): array
    {
        return [
            'schema' => self::SCHEMA,
            'urgency' => $urgency,
            'next_action' => $nextAction,
            'replenish_action' => $this->replenishAction($urgency),
            'reasons' => array_values($reasons),
            'inputs' => $inputs,
        ];
    }

    /**
     * Normalize the internal HIGH/MID/LOW urgency tier into the worker-floor vocabulary.
     * LOW is explicitly monitoring, never permission for an originator to stop creating
     * high-leverage work.
     */
    private function replenishAction(string $urgency): string
    {
        return match ($urgency) {
            'HIGH' => 'replenish_urgently',
            'MID' => 'replenish_soon',
            default => 'monitor_idle_supply',
        };
    }

    /** Claimable-per-active-lease floor: at or below this, passive monitoring can never be returned. */
    public const WORKER_FLOOR_THRESHOLD = 2.0;

    public const REASON_WORKER_FLOOR = 'worker_floor';

    /**
     * Pure, facts-only worker-floor override: passive monitoring can never be returned when active
     * leases exist and the claimable supply per active lease is at or below the worker
     * safety floor — workers holding those leases risk draining to no_claimable_task.
     * With zero active leases the existing hold behavior is preserved as monitoring, not stopping.
     *
     * @param  array{active_leases?: int, claimable_per_active_worker?: float}  $facts
     * @return array{schema:string, replenish_action:string, reason:?string}
     */
    public function classifyWorkerFloor(array $facts): array
    {
        $activeLeases = max(0, (int) ($facts['active_leases'] ?? 0));
        $claimablePerActiveWorker = isset($facts['claimable_per_active_worker'])
            ? (float) $facts['claimable_per_active_worker']
            : PHP_FLOAT_MAX;

        if ($activeLeases > 0 && $claimablePerActiveWorker <= self::WORKER_FLOOR_THRESHOLD) {
            $replenishAction = $claimablePerActiveWorker <= 0.0 ? 'replenish_urgently' : 'replenish_soon';

            return ['schema' => self::SCHEMA, 'replenish_action' => $replenishAction, 'reason' => self::REASON_WORKER_FLOOR];
        }

        return ['schema' => self::SCHEMA, 'replenish_action' => 'monitor_idle_supply', 'reason' => null];
    }

    public const DECISION_ORIGINATE_MORE = 'originate_more';

    public const DECISION_HOLD_OR_REPAIR = 'hold_or_repair';

    public const DECISION_SUFFICIENT = 'sufficient';

    /**
     * Pure, facts-only replenish decision that looks past static claimable depth: active muscle
     * drain, a quality/malformed/poison safety gate, and high-value unqueued target availability
     * all factor in — a queue that "looks" deep enough can still need origination if the muscles
     * working it are about to drain below the worker floor, and a queue must never get MORE work
     * piled onto it while the safety gate is unsafe.
     *
     * Priority: safety gate (hold_or_repair) → queue_dry → drain-threatens-floor → sufficient.
     *
     * @param  array{
     *     active_muscle_count?: int, drain_forecast?: int, worker_floor?: int,
     *     high_value_unqueued_targets?: int, claimable_depth?: int,
     *     task_quality_floor_breached?: bool, malformed_risk_unsafe?: bool, poison_risk_unsafe?: bool,
     * }  $facts
     * @return array{schema:string, decision:string, reason:?string}
     */
    public function classifyReplenishDecision(array $facts): array
    {
        $qualityFloorBreached = (bool) ($facts['task_quality_floor_breached'] ?? false);
        $malformedRiskUnsafe = (bool) ($facts['malformed_risk_unsafe'] ?? false);
        $poisonRiskUnsafe = (bool) ($facts['poison_risk_unsafe'] ?? false);

        // Safety gate wins over everything else — never pile more origination onto a pipeline
        // that can't be trusted to process it correctly.
        if ($qualityFloorBreached || $malformedRiskUnsafe || $poisonRiskUnsafe) {
            $reason = match (true) {
                $poisonRiskUnsafe => 'poison_risk_unsafe',
                $malformedRiskUnsafe => 'malformed_risk_unsafe',
                default => 'task_quality_floor_breached',
            };

            return ['schema' => self::SCHEMA, 'decision' => self::DECISION_HOLD_OR_REPAIR, 'reason' => $reason];
        }

        $claimableDepth = max(0, (int) ($facts['claimable_depth'] ?? 0));
        if ($claimableDepth === 0) {
            return ['schema' => self::SCHEMA, 'decision' => self::DECISION_ORIGINATE_MORE, 'reason' => 'queue_dry'];
        }

        $activeMuscleCount = max(0, (int) ($facts['active_muscle_count'] ?? 0));
        $drainForecast = max(0, (int) ($facts['drain_forecast'] ?? 0));
        $workerFloor = max(0, (int) ($facts['worker_floor'] ?? 0));
        $highValueUnqueuedTargets = max(0, (int) ($facts['high_value_unqueued_targets'] ?? 0));

        $projectedActiveAfterDrain = $activeMuscleCount - $drainForecast;

        // Even when current depth "looks" sufficient, active muscles about to drain below the
        // worker floor — with real high-value work waiting to be queued — must trigger origination.
        if ($projectedActiveAfterDrain <= $workerFloor && $highValueUnqueuedTargets > 0) {
            return ['schema' => self::SCHEMA, 'decision' => self::DECISION_ORIGINATE_MORE, 'reason' => 'drain_threatens_worker_floor_with_valuable_targets'];
        }

        return ['schema' => self::SCHEMA, 'decision' => self::DECISION_SUFFICIENT, 'reason' => null];
    }

    /** @return array<string,mixed> */
    private function facts(object $source, string $method): array
    {
        return method_exists($source, $method) ? (array) $source->{$method}() : [];
    }

    /** @param array<string,mixed> $facts */
    private function intFact(array $facts, string $key, int $default = 0): int
    {
        return isset($facts[$key]) && is_numeric($facts[$key]) ? (int) $facts[$key] : $default;
    }

    /** @param array<string,mixed> $facts */
    private function nullableIntFact(array $facts, string $key): ?int
    {
        return array_key_exists($key, $facts) && is_numeric($facts[$key]) ? (int) $facts[$key] : null;
    }

    /** @param array<string,mixed> $facts */
    private function floatFact(array $facts, string $key): float
    {
        return isset($facts[$key]) && is_numeric($facts[$key]) ? (float) $facts[$key] : 0.0;
    }

    private function queueAgeObject(): object
    {
        return $this->queueAgeHistogram ?? new AtlasMaestroQueueAgeHistogram;
    }

    private function leaseLifetimeObject(): object
    {
        return $this->leaseLifetimeHistogram ?? new AtlasMaestroLeaseLifetimeHistogram;
    }

    private function workerIdleObject(): object
    {
        return $this->workerIdlePredictor ?? new AtlasMaestroWorkerIdlePredictor;
    }
}
