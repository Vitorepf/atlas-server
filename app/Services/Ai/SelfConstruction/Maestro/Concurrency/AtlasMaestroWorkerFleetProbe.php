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
