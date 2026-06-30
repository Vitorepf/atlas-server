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
 *
 * routePacket() extends routing with lane, risk, file-family, and active-claim conflict detection:
 * workers whose active claims overlap the packet's allowed_files are excluded before scoring (ROUTE_CONFLICT
 * when all eligible workers are excluded).
 */
final class AtlasMaestroWorkerAffinityRouter
{
    public const ROUTE_ABSTAIN = 'route_abstain';

    public const ROUTED = 'routed';

    public const ROUTE_CONFLICT = 'route_conflict';

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

    /**
     * Extended routing for final-brain packets: filters by allowed_files conflict first, then routes
     * by lane affinity (using 'lane:{lane}' as the ledger key), falling back to task_class routing.
     *
     * @param  array{task_class?:string, lane?:string, risk_level?:string, allowed_files?:list<string>}  $packet
     * @param  list<string>  $eligibleWorkers  client_ids already certified eligible
     * @param  list<array{worker_id:string, claimed_files:list<string>}>  $activeClaims  live lease snapshots
     * @return array{status:string, worker?:string, reason?:string, conflict_workers?:list<string>, routing_key?:string}
     */
    public function routePacket(array $packet, array $eligibleWorkers, array $activeClaims = []): array
    {
        $allowedFiles = is_array($packet['allowed_files'] ?? null)
            ? array_values(array_filter(array_map('strval', $packet['allowed_files']), static fn (string $f): bool => $f !== ''))
            : [];

        // Build map: worker_id → claimed files.
        $claimedByWorker = [];
        foreach ($activeClaims as $claim) {
            $wid = (string) ($claim['worker_id'] ?? '');
            $files = is_array($claim['claimed_files'] ?? null) ? $claim['claimed_files'] : [];
            if ($wid !== '') {
                $claimedByWorker[$wid] = $files;
            }
        }

        // Remove workers whose claimed files overlap the packet's allowed_files.
        $conflictWorkers = [];
        $nonConflict = [];
        foreach ($eligibleWorkers as $worker) {
            $worker = (string) $worker;
            if ($worker === '') {
                continue;
            }
            $claimed = $claimedByWorker[$worker] ?? [];
            if ($allowedFiles !== [] && array_intersect($allowedFiles, $claimed) !== []) {
                $conflictWorkers[] = $worker;
            } else {
                $nonConflict[] = $worker;
            }
        }

        if ($nonConflict === []) {
            return [
                'status' => self::ROUTE_CONFLICT,
                'reason' => 'all_eligible_workers_have_allowed_files_conflict',
                'conflict_workers' => $conflictWorkers,
            ];
        }

        $taskClass = (string) ($packet['task_class'] ?? '');
        $lane = trim((string) ($packet['lane'] ?? ''));

        // Try lane-affinity routing first.
        if ($lane !== '') {
            $laneResult = $this->route('lane:'.$lane, $nonConflict);
            if ($laneResult['status'] === self::ROUTED) {
                $laneResult['routing_key'] = 'lane:'.$lane;
                $laneResult['conflict_workers'] = $conflictWorkers;

                return $laneResult;
            }
        }

        // Fall back to task_class routing.
        if ($taskClass !== '') {
            $classResult = $this->route($taskClass, $nonConflict);
            if ($classResult['status'] === self::ROUTED) {
                $classResult['routing_key'] = $taskClass;
                $classResult['conflict_workers'] = $conflictWorkers;

                return $classResult;
            }
        }

        return [
            'status' => self::ROUTE_ABSTAIN,
            'reason' => 'insufficient_success_evidence',
            'conflict_workers' => $conflictWorkers,
            'routing_key' => $lane !== '' ? 'lane:'.$lane : $taskClass,
        ];
    }
}
