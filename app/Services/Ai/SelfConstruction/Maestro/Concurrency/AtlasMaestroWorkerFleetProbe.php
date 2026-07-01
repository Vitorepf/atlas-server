<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Concurrency;

use Throwable;

/**
 * READ-ONLY FACT collector. Given the same agent_client_id surface used by the task-queue lease envelopes,
 * derives per-worker FACTS:
 *   - last_seen_at                    : most recent lease touch
 *   - in_flight_count                 : active leases not yet released
 *   - lifetime_throughput             : completed packets per worker since wall_clock_origin
 *   - median_lease_duration_seconds   : median of released-lease durations
 *
 * ANTI-GOODHART: ZERO scoring/ranking. The probe emits raw counts only — no `best`, no `rank`, no `winner`,
 * no composite weight. Operators (or downstream code) decide what to do with the FACTS.
 *
 * PURE READ: the probe takes the lease snapshot via an injected source callable; it NEVER writes. A test
 * wraps the source in a write-tripwire to prove probe() triggers zero schema mutations.
 */
final class AtlasMaestroWorkerFleetProbe
{
    public const CLASS_ACTIVE = 'active_worker';

    public const CLASS_IDLE = 'idle_worker';

    public const CLASS_STALE = 'stale_worker';

    public const CLASS_GHOST = 'ghost_worker_signal';

    /** In-flight lease age (seconds) at/above which the lease is a ghost — claimed but never freed. */
    private const DEFAULT_GHOST_THRESHOLD_SECONDS = 3600;

    /** @var callable():iterable<array{client_id:string, opened_at:int, released_at:?int, packet_id?:string, outcome?:?string}> */
    private $leaseSource;

    /**
     * @param  callable():iterable<array{client_id:string, opened_at:int, released_at:?int, packet_id?:string, outcome?:?string}>  $leaseSource
     *         Production binds this to the AgentControlPlaneClaimLeaseRepository (read-only iterator over the
     *         lease envelopes). Tests inject a synthetic iterable.
     */
    public function __construct(callable $leaseSource)
    {
        $this->leaseSource = $leaseSource;
    }

    /**
     * Fleet-level summary: active/stale worker counts, median in-flight lease age, claims-per-worker,
     * and a categorical overload_signal. Pure read — never mutates leases or queues.
     *
     * @return array{active_workers:int, stale_workers:int, median_lease_age_seconds:float, claims_per_worker:float, overload_signal:string}
     */
    public function fleetSummary(int $now, int $staleThresholdSeconds = 300): array
    {
        $staleThresholdSeconds = max(1, $staleThresholdSeconds);
        $activeThreshold = $now - $staleThresholdSeconds;

        $lastSeenByWorker = [];
        $inFlightByWorker = [];
        $inFlightAges = [];

        try {
            foreach (($this->leaseSource)() as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clientId = (string) ($row['client_id'] ?? '');
                if ($clientId === '') {
                    continue;
                }
                $opened = (int) ($row['opened_at'] ?? 0);
                $released = array_key_exists('released_at', $row) && $row['released_at'] !== null
                    ? (int) $row['released_at']
                    : null;

                $touchedAt = max($opened, $released ?? 0);
                $lastSeenByWorker[$clientId] = max($lastSeenByWorker[$clientId] ?? 0, $touchedAt);

                if ($released === null) {
                    $inFlightByWorker[$clientId] = ($inFlightByWorker[$clientId] ?? 0) + 1;
                    $inFlightAges[] = max(0, $now - $opened);
                }
            }
        } catch (Throwable) {
        }

        $activeWorkers = 0;
        $staleWorkers = 0;
        $totalInFlight = 0;

        foreach ($lastSeenByWorker as $clientId => $lastSeen) {
            if ($lastSeen >= $activeThreshold) {
                $activeWorkers++;
            } else {
                $staleWorkers++;
            }
            $totalInFlight += $inFlightByWorker[$clientId] ?? 0;
        }

        $claimsPerWorker = $activeWorkers > 0 ? round($totalInFlight / $activeWorkers, 4) : 0.0;

        $classifications = $this->workerClassifications($now, $staleThresholdSeconds);
        $ghostCount = 0;
        $staleCount = 0;
        foreach ($classifications as $c) {
            if ($c['classification'] === self::CLASS_GHOST) {
                $ghostCount++;
            } elseif ($c['classification'] === self::CLASS_STALE) {
                $staleCount++;
            }
        }

        $hints = [];
        if ($ghostCount > 0) {
            $hints[] = $ghostCount.' worker(s) show ghost_worker_signal: lease claimed but never released beyond ghost threshold';
        }
        if ($staleCount > 0) {
            $hints[] = $staleCount.' worker(s) are stale_worker: no lease activity within '.$staleThresholdSeconds.'s';
        }

        return [
            'active_workers' => $activeWorkers,
            'stale_workers' => $staleWorkers,
            'median_lease_age_seconds' => $this->median($inFlightAges),
            'claims_per_worker' => $claimsPerWorker,
            'overload_signal' => $this->overloadSignal($claimsPerWorker, $staleWorkers, $activeWorkers),
            'worker_classifications' => $classifications,
            'ghost_worker_count' => $ghostCount,
            'coordination_hints' => $hints,
        ];
    }

    /**
     * Per-worker classification — pure FACT labels, never a block/kill decision. A caller may
     * choose to act on `ghost_worker_signal`/`stale_worker`, but healthy workers are always
     * classified `active_worker`/`idle_worker` and are never held back by this probe.
     *
     * @return list<array{client_id:string, classification:string}>
     */
    public function workerClassifications(
        int $now,
        int $staleThresholdSeconds = 300,
        int $ghostThresholdSeconds = self::DEFAULT_GHOST_THRESHOLD_SECONDS,
    ): array {
        $staleThresholdSeconds = max(1, $staleThresholdSeconds);
        $ghostThresholdSeconds = max(1, $ghostThresholdSeconds);
        $activeThreshold = $now - $staleThresholdSeconds;

        $lastSeenByWorker = [];
        $maxInFlightAgeByWorker = [];
        $inFlightCountByWorker = [];

        try {
            foreach (($this->leaseSource)() as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clientId = (string) ($row['client_id'] ?? '');
                if ($clientId === '') {
                    continue;
                }
                $opened = (int) ($row['opened_at'] ?? 0);
                $released = array_key_exists('released_at', $row) && $row['released_at'] !== null
                    ? (int) $row['released_at']
                    : null;

                $touchedAt = max($opened, $released ?? 0);
                $lastSeenByWorker[$clientId] = max($lastSeenByWorker[$clientId] ?? 0, $touchedAt);

                if ($released === null) {
                    $age = max(0, $now - $opened);
                    $maxInFlightAgeByWorker[$clientId] = max($maxInFlightAgeByWorker[$clientId] ?? 0, $age);
                    $inFlightCountByWorker[$clientId] = ($inFlightCountByWorker[$clientId] ?? 0) + 1;
                }
            }
        } catch (Throwable) {
        }

        $out = [];
        foreach ($lastSeenByWorker as $clientId => $lastSeen) {
            $maxAge = $maxInFlightAgeByWorker[$clientId] ?? 0;
            $inFlight = $inFlightCountByWorker[$clientId] ?? 0;

            if ($maxAge >= $ghostThresholdSeconds) {
                $classification = self::CLASS_GHOST;
            } elseif ($lastSeen < $activeThreshold) {
                $classification = self::CLASS_STALE;
            } elseif ($inFlight === 0) {
                $classification = self::CLASS_IDLE;
            } else {
                $classification = self::CLASS_ACTIVE;
            }

            $out[] = ['client_id' => (string) $clientId, 'classification' => $classification];
        }
        usort($out, static fn (array $a, array $b): int => $a['client_id'] <=> $b['client_id']);

        return $out;
    }

    /**
     * @return list<array{client_id:string, last_seen_at:int, in_flight_count:int, lifetime_throughput:int, median_lease_duration_seconds:float}>
     */
    public function probe(): array
    {
        $leases = [];
        try {
            foreach (($this->leaseSource)() as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $leases[] = $row;
            }
        } catch (Throwable) {
            return [];
        }

        $byClient = [];
        foreach ($leases as $lease) {
            $clientId = (string) ($lease['client_id'] ?? '');
            if ($clientId === '') {
                continue;
            }
            $opened = (int) ($lease['opened_at'] ?? 0);
            $released = array_key_exists('released_at', $lease) && $lease['released_at'] !== null ? (int) $lease['released_at'] : null;

            $byClient[$clientId] ??= ['last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 0, 'durations' => []];
            $byClient[$clientId]['last_seen_at'] = max($byClient[$clientId]['last_seen_at'], $opened, $released ?? 0);
            if ($released === null) {
                $byClient[$clientId]['in_flight_count']++;
            } else {
                $byClient[$clientId]['lifetime_throughput']++;
                $byClient[$clientId]['durations'][] = max(0, $released - $opened);
            }
        }

        $out = [];
        foreach ($byClient as $clientId => $agg) {
            $out[] = [
                'client_id' => (string) $clientId,
                'last_seen_at' => (int) $agg['last_seen_at'],
                'in_flight_count' => (int) $agg['in_flight_count'],
                'lifetime_throughput' => (int) $agg['lifetime_throughput'],
                'median_lease_duration_seconds' => $this->median($agg['durations']),
            ];
        }
        usort($out, static fn (array $x, array $y): int => $x['client_id'] <=> $y['client_id']);

        return $out;
    }

    private function overloadSignal(float $claimsPerWorker, int $staleWorkers, int $activeWorkers): string
    {
        if ($activeWorkers === 0 && $staleWorkers > 0) {
            return 'red';
        }
        if ($claimsPerWorker >= 3.0) {
            return 'red';
        }
        if ($claimsPerWorker >= 1.5 || $staleWorkers > 0) {
            return 'yellow';
        }

        return 'green';
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = (int) floor($n / 2);
        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }

        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }
}
