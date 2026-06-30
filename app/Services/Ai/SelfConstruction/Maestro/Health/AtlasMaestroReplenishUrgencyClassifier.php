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
        $activeClaimedWorkers = $this->intFact($idle, 'active_claimed_workers');
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
        if ($reasons !== []) {
            return $this->result('HIGH', $reasons, $inputs);
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
            return $this->result('MID', $reasons, $inputs);
        }

        return $this->result('LOW', ['no_replenish_pressure'], $inputs);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,int|float|null>  $inputs
     * @return array{schema:string, urgency:string, reasons:list<string>, inputs:array<string,int|float|null>}
     */
    private function result(string $urgency, array $reasons, array $inputs): array
    {
        return [
            'schema' => self::SCHEMA,
            'urgency' => $urgency,
            'reasons' => array_values($reasons),
            'inputs' => $inputs,
        ];
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
