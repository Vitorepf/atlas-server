<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Concurrency;

/**
 * Worker-fairness auditor. Consumes {@see AtlasMaestroWorkerFleetProbe::probe()} output and reports a FACT
 * distribution histogram: "is one worker hogging the queue?". FACTS only — never recommends rebalancing, never
 * mutates the queue (same anti-Goodhart contract as `atlas:loop:comprehend`).
 *
 * Output shape:
 *   {
 *     workers: int, total_in_flight: int, total_completed: int,
 *     in_flight_histogram: { client_id => count },
 *     completed_share_histogram: { client_id => fraction[0..1] },
 *     gini_coefficient: float,
 *     max_share_client_id: ?string,
 *     max_share_value: float
 *   }
 *
 * Gini is computed over the completed counts using the canonical sorted-cumulative formula.
 */
final class AtlasMaestroWorkerFairnessAuditor
{
    public function __construct(private readonly AtlasMaestroWorkerFleetProbe $probe) {}

    /**
     * @return array{workers:int, total_in_flight:int, total_completed:int, in_flight_histogram:array<string,int>, completed_share_histogram:array<string,float>, gini_coefficient:float, max_share_client_id:?string, max_share_value:float}
     */
    public function audit(): array
    {
        $rows = $this->probe->probe();

        $inFlightHistogram = [];
        $completedHistogram = [];
        $clientOrder = [];

        foreach ($rows as $row) {
            $client = (string) ($row['client_id'] ?? '');
            if ($client === '') {
                continue;
            }
            $inFlight = (int) ($row['in_flight_count'] ?? 0);
            $completed = (int) ($row['lifetime_throughput'] ?? 0);

            $inFlightHistogram[$client] = ($inFlightHistogram[$client] ?? 0) + $inFlight;
            $completedHistogram[$client] = ($completedHistogram[$client] ?? 0) + $completed;
            if (! in_array($client, $clientOrder, true)) {
                $clientOrder[] = $client;
            }
        }

        $totalInFlight = array_sum($inFlightHistogram);
        $totalCompleted = array_sum($completedHistogram);

        $completedShare = [];
        foreach ($completedHistogram as $client => $count) {
            $completedShare[$client] = $totalCompleted > 0 ? ($count / $totalCompleted) : 0.0;
        }

        [$maxClient, $maxShare] = $this->maxShare($completedShare, $clientOrder);
        $gini = $this->gini(array_values($completedHistogram));
        $fairnessAlerts = $this->fairnessAlerts($clientOrder, $inFlightHistogram, $completedHistogram, (int) $totalCompleted, $maxClient, $maxShare);

        return [
            'workers' => count($clientOrder),
            'total_in_flight' => (int) $totalInFlight,
            'total_completed' => (int) $totalCompleted,
            'in_flight_histogram' => $inFlightHistogram,
            'completed_share_histogram' => $completedShare,
            'gini_coefficient' => $gini,
            'max_share_client_id' => $maxClient,
            'max_share_value' => $maxShare,
            'fairness_alerts' => $fairnessAlerts,
        ];
    }

    /**
     * @param  list<string>  $clientOrder
     * @param  array<string,int>  $inFlightHistogram
     * @param  array<string,int>  $completedHistogram
     * @return list<array<string,mixed>>
     */
    private function fairnessAlerts(array $clientOrder, array $inFlightHistogram, array $completedHistogram, int $totalCompleted, ?string $maxClient, float $maxShare): array
    {
        $alerts = [];

        if (count($clientOrder) > 1 && $maxShare > 0.5 && $maxClient !== null) {
            $alerts[] = ['alert' => 'hogging', 'max_share_client_id' => $maxClient, 'max_share_value' => $maxShare];
        }

        if ($totalCompleted > 0) {
            $starved = array_values(array_filter($clientOrder, fn (string $c): bool => ($completedHistogram[$c] ?? 0) === 0));
            sort($starved, SORT_STRING);
            if ($starved !== []) {
                $alerts[] = ['alert' => 'starvation', 'starved_client_ids' => $starved];
            }
        }

        $highInflightZeroThru = array_values(array_filter(
            $clientOrder,
            fn (string $c): bool => ($inFlightHistogram[$c] ?? 0) >= 2 && ($completedHistogram[$c] ?? 0) === 0,
        ));
        sort($highInflightZeroThru, SORT_STRING);
        if ($highInflightZeroThru !== []) {
            $alerts[] = ['alert' => 'high_in_flight_low_throughput', 'client_ids' => $highInflightZeroThru];
        }

        return $alerts;
    }

    /**
     * @param  array<string,float>  $shares
     * @param  list<string>  $insertionOrder  ties broken by first-seen client_id
     * @return array{0:?string, 1:float}
     */
    private function maxShare(array $shares, array $insertionOrder): array
    {
        if ($shares === []) {
            return [null, 0.0];
        }
        $maxClient = null;
        $maxValue = -INF;
        $maxIdx = PHP_INT_MAX;
        $idx = array_flip($insertionOrder);
        foreach ($shares as $client => $value) {
            $clientIdx = $idx[$client] ?? PHP_INT_MAX;
            if ($value > $maxValue || ($value === $maxValue && $clientIdx < $maxIdx)) {
                $maxValue = $value;
                $maxClient = $client;
                $maxIdx = $clientIdx;
            }
        }

        return [$maxClient, $maxValue === -INF ? 0.0 : (float) $maxValue];
    }

    /**
     * Standard Gini coefficient over a list of non-negative counts:
     *   G = (sum_i (2i - n - 1) * x_i_sorted_asc) / (n * sum(x))
     *
     * Zero or empty input → 0.0 (no inequality to report).
     *
     * @param  list<int>  $values
     */
    private function gini(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $sum = (float) array_sum($values);
        if ($sum <= 0.0) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $weighted = 0.0;
        foreach ($values as $i => $value) {
            $weighted += (2 * ($i + 1) - $n - 1) * (float) $value;
        }

        return (float) ($weighted / ($n * $sum));
    }
}
