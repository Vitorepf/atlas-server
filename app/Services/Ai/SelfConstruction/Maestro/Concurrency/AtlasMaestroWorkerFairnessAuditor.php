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
 *
 * outcomeFacts (optional, AC2): the underlying AtlasMaestroWorkerFleetProbe only exposes raw lease
 * counts (in_flight/completed), never outcome quality — so audit() accepts an OPTIONAL list of
 * {client_id, outcome: success|give_back|poison|poison_detected, is_hard_task?} rows a caller
 * already has (e.g. from the task ledger) to compute success_share/give_back_share/poison_share
 * and hard-task concentration per client. Omitted entirely, every existing zero-arg call site is
 * byte-identical to before.
 *
 * assignment_share_histogram (AC2) is the share of TOTAL assigned work (in_flight + completed) per
 * client, distinct from completed_share_histogram (completed only).
 *
 * hard_task_concentration alert (AC3) fires when one client absorbs > 70% of all hard-task
 * assignments across the fleet. Every fairness alert now carries repair_advice (AC3).
 *
 * specialization_classification (AC4) distinguishes healthy_specialization (high assignment share
 * backed by high success_share — a competent specialist earning more work) from unfair_routing
 * (high assignment share with high give_back/poison rates — routing, not merit).
 */
final class AtlasMaestroWorkerFairnessAuditor
{
    public function __construct(private readonly AtlasMaestroWorkerFleetProbe $probe) {}

    /**
     * @param  list<array{client_id?:string, outcome?:string, is_hard_task?:bool}>  $outcomeFacts
     * @return array{workers:int, total_in_flight:int, total_completed:int, in_flight_histogram:array<string,int>, completed_share_histogram:array<string,float>, gini_coefficient:float, max_share_client_id:?string, max_share_value:float}
     */
    public function audit(array $outcomeFacts = []): array
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

        $assignmentShare = $this->assignmentShareHistogram($clientOrder, $inFlightHistogram, $completedHistogram);
        $outcomeStats = $this->aggregateOutcomes($outcomeFacts, $clientOrder);

        $hardTaskAlert = $this->hardTaskConcentrationAlert($clientOrder, $outcomeStats);
        if ($hardTaskAlert !== null) {
            $fairnessAlerts[] = $hardTaskAlert;
        }
        $fairnessAlerts = $this->attachRepairAdvice($fairnessAlerts);

        $specialization = $this->classifySpecialization($clientOrder, $assignmentShare, $outcomeStats);

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
            'assignment_share_histogram' => $assignmentShare,
            'outcome_stats_by_client' => $outcomeStats,
            'specialization_classification' => $specialization,
        ];
    }

    /**
     * @param  list<string>  $clientOrder
     * @param  array<string,int>  $inFlightHistogram
     * @param  array<string,int>  $completedHistogram
     * @return array<string,float>
     */
    private function assignmentShareHistogram(array $clientOrder, array $inFlightHistogram, array $completedHistogram): array
    {
        $totals = [];
        foreach ($clientOrder as $client) {
            $totals[$client] = ($inFlightHistogram[$client] ?? 0) + ($completedHistogram[$client] ?? 0);
        }
        $grandTotal = array_sum($totals);

        $shares = [];
        foreach ($clientOrder as $client) {
            $shares[$client] = $grandTotal > 0 ? round($totals[$client] / $grandTotal, 4) : 0.0;
        }

        return $shares;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomeFacts
     * @param  list<string>  $clientOrder
     * @return array<string,array{success_count:int,give_back_count:int,poison_count:int,hard_task_count:int,total_outcomes:int,success_share:float,give_back_share:float,poison_share:float}>
     */
    private function aggregateOutcomes(array $outcomeFacts, array $clientOrder): array
    {
        $raw = [];
        foreach ($outcomeFacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $client = (string) ($row['client_id'] ?? '');
            if ($client === '') {
                continue;
            }
            $outcome = (string) ($row['outcome'] ?? '');
            $isHard = (bool) ($row['is_hard_task'] ?? false);
            $raw[$client] ??= ['success_count' => 0, 'give_back_count' => 0, 'poison_count' => 0, 'hard_task_count' => 0, 'total_outcomes' => 0];
            $raw[$client]['total_outcomes']++;
            if ($isHard) {
                $raw[$client]['hard_task_count']++;
            }
            match ($outcome) {
                'success' => $raw[$client]['success_count']++,
                'give_back' => $raw[$client]['give_back_count']++,
                'poison', 'poison_detected' => $raw[$client]['poison_count']++,
                default => null,
            };
        }

        $out = [];
        foreach ($clientOrder as $client) {
            $agg = $raw[$client] ?? ['success_count' => 0, 'give_back_count' => 0, 'poison_count' => 0, 'hard_task_count' => 0, 'total_outcomes' => 0];
            $total = $agg['total_outcomes'];
            $out[$client] = $agg + [
                'success_share' => $total > 0 ? round($agg['success_count'] / $total, 4) : 0.0,
                'give_back_share' => $total > 0 ? round($agg['give_back_count'] / $total, 4) : 0.0,
                'poison_share' => $total > 0 ? round($agg['poison_count'] / $total, 4) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $clientOrder
     * @param  array<string,array<string,mixed>>  $outcomeStatsByClient
     */
    private function hardTaskConcentrationAlert(array $clientOrder, array $outcomeStatsByClient): ?array
    {
        if (count($clientOrder) < 2) {
            return null;
        }
        $totalHard = 0;
        foreach ($outcomeStatsByClient as $stats) {
            $totalHard += $stats['hard_task_count'];
        }
        if ($totalHard === 0) {
            return null;
        }
        foreach ($clientOrder as $client) {
            $share = $outcomeStatsByClient[$client]['hard_task_count'] / $totalHard;
            if ($share > 0.7) {
                return [
                    'alert' => 'hard_task_concentration',
                    'client_id' => $client,
                    'hard_task_share' => round($share, 4),
                ];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $alerts
     * @return list<array<string,mixed>>
     */
    private function attachRepairAdvice(array $alerts): array
    {
        return array_map(static function (array $alert): array {
            $alert['repair_advice'] = match ((string) ($alert['alert'] ?? '')) {
                'hogging' => sprintf('reduce assignment weight for %s and redirect surplus tasks to idle/under-served clients', $alert['max_share_client_id'] ?? 'the dominant client'),
                'starvation' => 'assign at least one claimable task to each starved client before the next batch',
                'high_in_flight_low_throughput' => 'investigate stalled leases for these clients; they are claiming work without completing any',
                'hard_task_concentration' => sprintf('redistribute hard-task assignment away from %s toward less-loaded clients', $alert['client_id'] ?? 'the concentrated client'),
                default => 'review fairness distribution for this alert',
            };

            return $alert;
        }, $alerts);
    }

    /**
     * @param  list<string>  $clientOrder
     * @param  array<string,float>  $assignmentShare
     * @param  array<string,array<string,mixed>>  $outcomeStatsByClient
     * @return array<string,string>
     */
    private function classifySpecialization(array $clientOrder, array $assignmentShare, array $outcomeStatsByClient): array
    {
        $out = [];
        foreach ($clientOrder as $client) {
            $share = $assignmentShare[$client] ?? 0.0;
            $stats = $outcomeStatsByClient[$client] ?? null;
            $hasOutcomeData = $stats !== null && $stats['total_outcomes'] > 0;

            $out[$client] = match (true) {
                ! $hasOutcomeData => 'insufficient_data',
                $share > 0.5 && $stats['success_share'] >= 0.7 => 'healthy_specialization',
                $share > 0.5 && ($stats['give_back_share'] > 0.3 || $stats['poison_share'] > 0.1) => 'unfair_routing',
                default => 'balanced',
            };
        }

        return $out;
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
