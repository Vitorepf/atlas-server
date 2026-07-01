<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

/**
 * Pure in-memory ledger that records and recalls outcome rates by client,
 * task family and root cause. Gives Maestro a compact source of truth for
 * routing and poison avoidance.
 *
 * Recall returns conservative defaults for unseen workers — never optimistic.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasMaestroWorkerBehaviorLedger
{
    public const SCHEMA = 'atlas.maestro.worker_behavior_ledger.v1';

    /** @var array<string,array{success:int,give_back:int,weak_green:int}> indexed by client_id|task_family */
    private array $stats = [];

    /** @var array<string,int> indexed by root_cause_family */
    private array $giveBackRootCauses = [];

    /**
     * Record an outcome event.
     *
     * @param  array{
     *   client_id?:string,
     *   task_family?:string,
     *   outcome?:string,
     *   root_cause_family?:string,
     * }  $event
     */
    public function record(array $event): void
    {
        $clientId = (string) ($event['client_id'] ?? 'unknown');
        $family = (string) ($event['task_family'] ?? 'unknown');
        $outcome = (string) ($event['outcome'] ?? '');
        $key = $clientId . '|' . $family;

        if (! isset($this->stats[$key])) {
            $this->stats[$key] = ['success' => 0, 'give_back' => 0, 'weak_green' => 0];
        }

        switch ($outcome) {
            case 'success':
            case 'resolved':
            case 'completed_dry_run':
                $this->stats[$key]['success']++;
                break;
            case 'give_back':
                $this->stats[$key]['give_back']++;
                $rootCause = (string) ($event['root_cause_family'] ?? 'unspecified');
                $rcKey = $family . ':' . $rootCause;
                $this->giveBackRootCauses[$rcKey] = ($this->giveBackRootCauses[$rcKey] ?? 0) + 1;
                break;
            case 'weak_green':
                $this->stats[$key]['weak_green']++;
                break;
        }
    }

    /**
     * Recall outcome rates for a client+family.
     * Returns conservative defaults for unseen workers.
     *
     * @return array{
     *   client_id:string,
     *   task_family:string,
     *   success_rate:float,
     *   give_back_rate:float,
     *   weak_green_rate:float,
     *   total_events:int,
     *   seen:bool,
     * }
     */
    public function recall(string $clientId, string $family): array
    {
        $key = $clientId . '|' . $family;
        $row = $this->stats[$key] ?? null;

        if ($row === null) {
            // Conservative defaults for unseen workers — never optimistic.
            return [
                'client_id' => $clientId,
                'task_family' => $family,
                'success_rate' => 0.0,
                'give_back_rate' => 0.0,
                'weak_green_rate' => 0.0,
                'total_events' => 0,
                'seen' => false,
            ];
        }

        $total = $row['success'] + $row['give_back'] + $row['weak_green'];
        $total = max(1, $total);

        return [
            'client_id' => $clientId,
            'task_family' => $family,
            'success_rate' => round($row['success'] / $total, 4),
            'give_back_rate' => round($row['give_back'] / $total, 4),
            'weak_green_rate' => round($row['weak_green'] / $total, 4),
            'total_events' => $row['success'] + $row['give_back'] + $row['weak_green'],
            'seen' => true,
        ];
    }

    /**
     * Get top give_back classes with root cause family counts.
     *
     * @param  int  $limit
     * @return list<array{root_cause_key:string,count:int}>
     */
    public function topGiveBackCauses(int $limit = 10): array
    {
        $causes = [];
        foreach ($this->giveBackRootCauses as $key => $count) {
            $causes[] = ['root_cause_key' => $key, 'count' => $count];
        }
        usort($causes, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($causes, 0, $limit);
    }

    /**
     * Get aggregated stats for all recorded client+family combinations.
     *
     * @return array<string,array{success:int,give_back:int,weak_green:int}>
     */
    public function allStats(): array
    {
        return $this->stats;
    }
}
