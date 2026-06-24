<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

/**
 * MAESTRO WORKER-AFFINITY ROUTER — re-orders the ALREADY-ELIGIBLE worker set for a packet's task_class by the
 * workers' proven track record (from AtlasMaestroWorkerBehaviorLedger FACTS): the worker with the strictly
 * highest success_count for that class wins (ties broken by lowest give_back_count, then lexicographic
 * client_id for determinism).
 *
 * It NEVER overrides the fail-closed eligibility gate — it only picks within the supplied eligible set, and it
 * ABSTAINS (ROUTE_ABSTAIN, reason=insufficient_success_evidence) when no eligible worker has at least
 * router_min_success (default 3) successes for the class, so serving falls back to the existing eligibility
 * ordering. FACTS-only, no scalar score.
 */
final class AtlasMaestroWorkerAffinityRouter
{
    public const ROUTE_ABSTAIN = 'route_abstain';

    public const ROUTED = 'routed';

    public function __construct(private readonly ?AtlasMaestroWorkerBehaviorLedger $ledger = null)
    {
    }

    /**
     * @param  list<string>  $eligibleWorkers  client_ids already certified eligible
     * @return array{status:string, worker?:string, ordered?:list<string>, facts?:array<string,int>, reason?:string, task_class:string, min_success:int}
     */
    public function route(string $taskClass, array $eligibleWorkers): array
    {
        $minSuccess = max(0, (int) config('atlas.maestro.adaptive.router_min_success', 3));
        $ledger = $this->ledger ?? new AtlasMaestroWorkerBehaviorLedger;

        $candidates = [];
        foreach ($eligibleWorkers as $worker) {
            $worker = (string) $worker;
            if ($worker === '') {
                continue;
            }
            $facts = $ledger->recall($worker, $taskClass);
            $success = (int) ($facts['success'] ?? 0);
            if ($success >= $minSuccess) {
                $candidates[] = ['client_id' => $worker, 'success_count' => $success, 'give_back_count' => (int) ($facts['give_back'] ?? 0)];
            }
        }

        if ($candidates === []) {
            return [
                'status' => self::ROUTE_ABSTAIN,
                'reason' => 'insufficient_success_evidence',
                'task_class' => $taskClass,
                'min_success' => $minSuccess,
            ];
        }

        // success_count DESC, give_back_count ASC, client_id ASC — a total order ⇒ deterministic.
        usort($candidates, static fn (array $a, array $b): int => [-$a['success_count'], $a['give_back_count'], $a['client_id']]
            <=> [-$b['success_count'], $b['give_back_count'], $b['client_id']]);

        return [
            'status' => self::ROUTED,
            'worker' => $candidates[0]['client_id'],
            'ordered' => array_column($candidates, 'client_id'),
            'facts' => ['success_count' => $candidates[0]['success_count'], 'give_back_count' => $candidates[0]['give_back_count']],
            'task_class' => $taskClass,
            'min_success' => $minSuccess,
        ];
    }
}
