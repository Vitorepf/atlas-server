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
 *
 * NEGATIVE OUTCOME ROUTING (upgrade):
 *   Negative muscle outcomes are first-class evidence — high give_back or gate_rejected rates for the
 *   specific task_family or task_class DEMOTE or AVOID workers even when they have broad success:
 *
 *   give_back_rate = give_back / max(1, success + give_back)
 *   poison_rate    = gate_rejected / max(1, served)
 *
 *   >= GIVE_BACK_DEMOTION_THRESHOLD (0.40) → demoted (ranked to the back of the ordered list)
 *   >= GIVE_BACK_AVOIDANCE_THRESHOLD (0.65) — in routePacket for task_family → avoided (excluded)
 *   >= POISON_AVOIDANCE_THRESHOLD    (0.30) — in routePacket for task_family → avoided
 *
 *   When all non-conflict workers are avoided → ROUTE_AVOIDED with reason negative_outcome_evidence.
 *   A clean worker with lower broad success is preferred over an unsafe demoted one.
 *   routing_explanation and negative_outcome_evidence fields explain every routing decision.
 */
final class AtlasMaestroWorkerAffinityRouter
{
    public const ROUTE_ABSTAIN  = 'route_abstain';
    public const ROUTED         = 'routed';
    public const ROUTE_CONFLICT = 'route_conflict';
    public const ROUTE_AVOIDED  = 'route_avoided';

    private const GIVE_BACK_DEMOTION_THRESHOLD  = 0.40;
    private const GIVE_BACK_AVOIDANCE_THRESHOLD = 0.65;
    private const POISON_AVOIDANCE_THRESHOLD    = 0.30;

    public function __construct(private readonly ?AtlasMaestroWorkerBehaviorLedger $ledger = null)
    {
    }

    /**
     * @param  list<string>  $eligibleWorkers  client_ids already certified eligible
     * @return array{status:string, worker?:string, ordered?:list<string>, facts?:array<string,int>, reason?:string, task_class:string, min_success:int, routing_explanation?:string, negative_outcome_evidence?:array<string,mixed>|null}
     */
    public function route(string $taskClass, array $eligibleWorkers): array
    {
        $minSuccess = max(0, (int) config('atlas.maestro.adaptive.router_min_success', 3));
        $ledger     = $this->ledger ?? new AtlasMaestroWorkerBehaviorLedger;

        $candidates = [];
        foreach ($eligibleWorkers as $worker) {
            $worker = (string) $worker;
            if ($worker === '') {
                continue;
            }
            $facts    = $ledger->recall($worker, $taskClass);
            $success  = (int) ($facts['success']       ?? 0);
            $giveBack = (int) ($facts['give_back']      ?? 0);
            $served   = (int) ($facts['served']         ?? 0);
            $gateRej  = (int) ($facts['gate_rejected']  ?? 0);

            if ($success < $minSuccess) {
                continue;
            }

            $giveBackRate = $giveBack / max(1, $success + $giveBack);
            $poisonRate   = $gateRej  / max(1, $served);
            $demoted      = $giveBackRate >= self::GIVE_BACK_DEMOTION_THRESHOLD
                || $poisonRate >= self::POISON_AVOIDANCE_THRESHOLD;

            $candidates[] = [
                'client_id'       => $worker,
                'success_count'   => $success,
                'give_back_count' => $giveBack,
                'give_back_rate'  => $giveBackRate,
                'poison_rate'     => $poisonRate,
                'demoted'         => $demoted,
            ];
        }

        if ($candidates === []) {
            return [
                'status'     => self::ROUTE_ABSTAIN,
                'reason'     => 'insufficient_success_evidence',
                'task_class' => $taskClass,
                'min_success' => $minSuccess,
            ];
        }

        // Non-demoted first; within each group: success DESC, give_back ASC, client_id ASC.
        usort($candidates, static fn (array $a, array $b): int =>
            [(int) $a['demoted'], -$a['success_count'], $a['give_back_count'], $a['client_id']]
            <=> [(int) $b['demoted'], -$b['success_count'], $b['give_back_count'], $b['client_id']]
        );

        $winner    = $candidates[0];
        $allDemoted = array_sum(array_column($candidates, 'demoted')) === count($candidates);

        $explanation = match (true) {
            $winner['demoted'] && $allDemoted => 'all_candidates_have_negative_outcome_evidence_routed_to_least_negative',
            $winner['demoted']                => 'routed_with_negative_outcome_demotion',
            default                           => 'routed',
        };

        return [
            'status'     => self::ROUTED,
            'worker'     => $winner['client_id'],
            'ordered'    => array_column($candidates, 'client_id'),
            'facts'      => ['success_count' => $winner['success_count'], 'give_back_count' => $winner['give_back_count']],
            'task_class' => $taskClass,
            'min_success' => $minSuccess,
            'routing_explanation'      => $explanation,
            'negative_outcome_evidence' => $winner['demoted'] ? [
                'give_back_rate' => round($winner['give_back_rate'], 4),
                'poison_rate'    => round($winner['poison_rate'],    4),
                'reason'         => 'high_task_family_give_back_or_poison',
            ] : null,
        ];
    }

    /**
     * Extended routing for final-brain packets: filters by allowed_files conflict first, then routes
     * by lane affinity (using 'lane:{lane}' as the ledger key), falling back to task_class routing.
     *
     * @param  array{task_class?:string, lane?:string, risk_level?:string, allowed_files?:list<string>, task_family?:string}  $packet
     * @param  list<string>  $eligibleWorkers  client_ids already certified eligible
     * @param  list<array{worker_id:string, claimed_files:list<string>}>  $activeClaims  live lease snapshots
     * @return array{status:string, worker?:string, reason?:string, conflict_workers?:list<string>, routing_key?:string, routing_explanation?:string}
     */
    public function routePacket(array $packet, array $eligibleWorkers, array $activeClaims = []): array
    {
        $allowedFiles = is_array($packet['allowed_files'] ?? null)
            ? array_values(array_filter(array_map('strval', $packet['allowed_files']), static fn (string $f): bool => $f !== ''))
            : [];

        $maxActiveClaims = max(1, (int) config('atlas.maestro.adaptive.max_active_claims', 3));

        // Build map: worker_id → claimed files, and count active claims per worker (pressure).
        $claimedByWorker = [];
        $claimCount      = [];
        foreach ($activeClaims as $claim) {
            $wid   = (string) ($claim['worker_id']    ?? '');
            $files = is_array($claim['claimed_files'] ?? null) ? $claim['claimed_files'] : [];
            if ($wid !== '') {
                $claimedByWorker[$wid] = $files;
                $claimCount[$wid]      = ($claimCount[$wid] ?? 0) + 1;
            }
        }

        // Partition workers: scope-collision → overloaded → clean.
        $conflictWorkers   = [];
        $overloadedWorkers = [];
        $nonConflict       = [];
        foreach ($eligibleWorkers as $worker) {
            $worker  = (string) $worker;
            if ($worker === '') {
                continue;
            }
            $claimed = $claimedByWorker[$worker] ?? [];
            if ($allowedFiles !== [] && array_intersect($allowedFiles, $claimed) !== []) {
                $conflictWorkers[] = $worker;
            } elseif (($claimCount[$worker] ?? 0) >= $maxActiveClaims) {
                $overloadedWorkers[] = $worker;
            } else {
                $nonConflict[] = $worker;
            }
        }

        if ($nonConflict === []) {
            if ($conflictWorkers !== []) {
                return [
                    'status'             => self::ROUTE_CONFLICT,
                    'reason'             => 'all_eligible_workers_have_allowed_files_conflict',
                    'conflict_workers'   => $conflictWorkers,
                    'overloaded_workers' => $overloadedWorkers,
                ];
            }

            return [
                'status'             => self::ROUTE_ABSTAIN,
                'reason'             => 'all_eligible_workers_overloaded',
                'overloaded_workers' => $overloadedWorkers,
                'conflict_workers'   => [],
            ];
        }

        $taskClass  = (string) ($packet['task_class']  ?? '');
        $lane       = trim((string) ($packet['lane']       ?? ''));
        $riskLevel  = trim((string) ($packet['risk_level'] ?? ''));
        $taskFamily = trim((string) ($packet['task_family'] ?? ''));

        // Negative outcome check per task_family: avoid workers with high give_back or poison rates.
        $available       = $nonConflict;
        $avoidedWorkers  = [];
        if ($taskFamily !== '') {
            $ledger    = $this->ledger ?? new AtlasMaestroWorkerBehaviorLedger;
            $available = [];
            foreach ($nonConflict as $worker) {
                $facts      = $ledger->recall($worker, 'family:'.$taskFamily);
                $gb         = (int) ($facts['give_back']     ?? 0);
                $succ       = (int) ($facts['success']       ?? 0);
                $gateRej    = (int) ($facts['gate_rejected'] ?? 0);
                $srv        = (int) ($facts['served']        ?? 0);
                $gbRate     = $gb     / max(1, $succ + $gb);
                $poisonRate = $gateRej / max(1, $srv);

                if ($gbRate >= self::GIVE_BACK_AVOIDANCE_THRESHOLD || $poisonRate >= self::POISON_AVOIDANCE_THRESHOLD) {
                    $avoidedWorkers[] = $worker;
                } else {
                    $available[] = $worker;
                }
            }
        }

        if ($available === [] && $avoidedWorkers !== []) {
            return [
                'status'             => self::ROUTE_AVOIDED,
                'reason'             => 'negative_outcome_evidence',
                'routing_explanation' => 'all_non_conflict_workers_avoided_for_task_family_negative_outcomes',
                'avoided_workers'    => $avoidedWorkers,
                'conflict_workers'   => $conflictWorkers,
                'overloaded_workers' => $overloadedWorkers,
            ];
        }

        // Routing priority: lane → risk tier → task family → task class.
        $steps = [];
        if ($lane !== '') {
            $steps[] = ['key' => 'lane:'.$lane];
        }
        if ($riskLevel !== '') {
            $steps[] = ['key' => 'risk:'.$riskLevel];
        }
        if ($taskFamily !== '') {
            $steps[] = ['key' => 'family:'.$taskFamily];
        }
        if ($taskClass !== '') {
            $steps[] = ['key' => $taskClass];
        }

        foreach ($steps as $step) {
            $result = $this->route($step['key'], $available);
            if ($result['status'] === self::ROUTED) {
                $result['routing_key']       = $step['key'];
                $result['conflict_workers']  = $conflictWorkers;
                $result['overloaded_workers'] = $overloadedWorkers;
                $result['avoided_workers']   = $avoidedWorkers;

                return $result;
            }
        }

        $firstKey = $steps !== [] ? $steps[0]['key'] : '';

        return [
            'status'              => self::ROUTE_ABSTAIN,
            'reason'              => 'insufficient_success_evidence',
            'conflict_workers'    => $conflictWorkers,
            'overloaded_workers'  => $overloadedWorkers,
            'avoided_workers'     => $avoidedWorkers,
            'routing_key'         => $firstKey,
            'routing_explanation' => 'abstained_no_evidence_in_any_routing_key',
        ];
    }
}
