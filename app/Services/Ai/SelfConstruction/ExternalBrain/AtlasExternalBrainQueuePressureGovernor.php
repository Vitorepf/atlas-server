<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides when the originator may enqueue, must defer, should consolidate,
 * or must stop based on live queue pressure metrics.
 *
 * Inputs (all optional; missing → treated as zero / safe default):
 *   queue_state.claimable_depth  int    — tasks in the queue eligible to be claimed
 *   queue_state.servable_depth   int    — tasks currently servable
 *   queue_state.active_leases    int    — tasks being actively worked
 *   candidate.leverage_score     float  — aggregate leverage of the candidate spec
 *   candidate.category           string — task category (for blocked-family check)
 *   candidate.task_class         string — 'normal'|'malformed'|'collision'|'lease_leak'
 *   context.blocked_families     string[] — categories temporarily suspended
 *   context.worker_pressure      string — 'low'|'normal'|'high'
 *
 * Decisions:
 *   enqueue_now  — healthy pressure, prerequisites met, enqueue immediately
 *   defer        — pressure elevated or blocked family, wait for digest
 *   consolidate  — medium pressure, batch down to minimum viable work
 *   stop         — critical pressure, stop authoring until queue drains
 *
 * Safety rule (never overridden):
 *   urgent repair classes (malformed, collision, lease_leak) always → enqueue_now
 *   regardless of pressure.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainQueuePressureGovernor
{
    public const SCHEMA = 'atlas.external_brain.queue_pressure_governor.v1';

    public const DECISION_ENQUEUE_NOW  = 'enqueue_now';
    public const DECISION_DEFER        = 'defer';
    public const DECISION_CONSOLIDATE  = 'consolidate';
    public const DECISION_STOP         = 'stop';

    /** Task classes that bypass all pressure checks — queue health trumps throughput. */
    private const URGENT_REPAIR_CLASSES = ['malformed', 'collision', 'lease_leak'];

    private const HIGH_CLAIMABLE_DEPTH = 30;
    private const HIGH_ACTIVE_LEASES   = 15;

    /** Live ratio: servable work queued per active worker. Catches a deep EFFECTIVE queue (e.g.
     *  100+ servable tasks across a handful of workers) even when raw claimable_depth alone is
     *  below HIGH_CLAIMABLE_DEPTH. */
    private const HIGH_SERVABLE_PER_WORKER_RATIO = 5.0;

    private const HIGH_LEVERAGE    = 0.75;
    private const MEDIUM_LEVERAGE  = 0.50;

    /**
     * Evaluate queue pressure and return a dispatch decision for the candidate spec.
     *
     * @param  array<string,mixed>  $input
     * @return array{
     *     schema:         string,
     *     decision:       string,
     *     reason:         string,
     *     urgent_override: bool,
     * }
     */
    public function decide(array $input): array
    {
        $queueState     = (array) ($input['queue_state'] ?? []);
        $candidate      = (array) ($input['candidate'] ?? []);
        $context        = (array) ($input['context'] ?? []);

        $claimableDepth = (int) ($queueState['claimable_depth'] ?? 0);
        $activeLeases   = (int) ($queueState['active_leases']   ?? 0);
        $servableDepth  = (int) ($queueState['servable_depth']  ?? 0);

        $leverageScore        = (float) ($candidate['leverage_score'] ?? 0.0);
        $dependencyUnlockScore = (float) ($candidate['dependency_unlock_score'] ?? 0.0);
        $effectiveLeverage     = max($leverageScore, $dependencyUnlockScore);
        $category       = trim((string) ($candidate['category']   ?? ''));
        $taskClass      = strtolower(trim((string) ($candidate['task_class'] ?? 'normal')));

        // Live ratio: how much servable work is queued PER active worker — a deep effective
        // queue can exist even when raw claimable_depth alone looks moderate.
        $servablePerWorker = $activeLeases > 0 ? $servableDepth / $activeLeases : (float) $servableDepth;
        $highServableRatio = $servablePerWorker >= self::HIGH_SERVABLE_PER_WORKER_RATIO;

        $blockedFamilies = array_map('strtolower', (array) ($context['blocked_families'] ?? []));
        $workerPressure  = strtolower(trim((string) ($context['worker_pressure'] ?? 'normal')));

        // --- Urgent repair bypass (unconditional) ---
        if (in_array($taskClass, self::URGENT_REPAIR_CLASSES, true)) {
            return $this->result(self::DECISION_ENQUEUE_NOW, "urgent repair class '{$taskClass}' bypasses all pressure checks", urgentOverride: true);
        }

        $highClaimable = $claimableDepth >= self::HIGH_CLAIMABLE_DEPTH;
        $highLeases    = $activeLeases   >= self::HIGH_ACTIVE_LEASES;
        $queueDeep     = $highClaimable || $highServableRatio;

        // --- Critical pressure: both dimensions saturated → stop ---
        if ($queueDeep && $highLeases) {
            $ratioNote = $highServableRatio ? " live servable_per_worker_ratio={$servablePerWorker}" : '';

            return $this->result(self::DECISION_STOP, "critical pressure: claimable_depth={$claimableDepth} and active_leases={$activeLeases} both at ceiling{$ratioNote}", liveRatioReason: $highServableRatio ? (string) $servablePerWorker : null);
        }

        // --- High pressure on either dimension (including the live servable-per-worker ratio) ---
        if ($queueDeep || $highLeases) {
            if ($effectiveLeverage >= self::HIGH_LEVERAGE) {
                $bypassReason = $dependencyUnlockScore > $leverageScore
                    ? "high dependency_unlock_score={$dependencyUnlockScore} admitted despite elevated pressure (live servable_per_worker_ratio={$servablePerWorker})"
                    : "high-leverage prerequisite (score={$leverageScore}) admitted despite elevated pressure";

                return $this->result(self::DECISION_ENQUEUE_NOW, $bypassReason, underPressure: true);
            }

            $ratioNote = $highServableRatio ? " live servable_per_worker_ratio={$servablePerWorker}" : '';

            return $this->result(self::DECISION_DEFER, "queue pressure elevated (claimable={$claimableDepth}, leases={$activeLeases}){$ratioNote}; low-leverage batch deferred", underPressure: true, liveRatioReason: $highServableRatio ? (string) $servablePerWorker : null);
        }

        // --- Blocked family check ---
        if ($category !== '' && in_array(strtolower($category), $blockedFamilies, true)) {
            return $this->result(self::DECISION_DEFER, "category '{$category}' is in blocked_families");
        }

        // --- Worker pressure ---
        if ($workerPressure === 'high') {
            if ($leverageScore >= self::MEDIUM_LEVERAGE) {
                return $this->result(self::DECISION_CONSOLIDATE, "worker pressure high; consolidate to minimum viable batch (leverage={$leverageScore})", underPressure: true);
            }

            return $this->result(self::DECISION_DEFER, "worker pressure high and leverage below threshold (score={$leverageScore})", underPressure: true);
        }

        // --- Healthy ---
        return $this->result(self::DECISION_ENQUEUE_NOW, "queue pressure nominal; leverage={$leverageScore}");
    }

    /** @return array{schema:string,decision:string,reason:string,urgent_override:bool,under_pressure:bool,live_ratio_reason:?string,batch_budget:array<string,mixed>} */
    private function result(string $decision, string $reason, bool $urgentOverride = false, bool $underPressure = false, ?string $liveRatioReason = null): array
    {
        return [
            'schema'             => self::SCHEMA,
            'decision'           => $decision,
            'reason'             => $reason,
            'urgent_override'    => $urgentOverride,
            'under_pressure'     => $underPressure,
            'live_ratio_reason'  => $liveRatioReason,
            'batch_budget'       => $this->buildBatchBudget($decision, $urgentOverride, $underPressure, $liveRatioReason),
        ];
    }

    /** @return array{max_tasks:int, minimum_leverage_score:float, evidence_floor:float, reason:string} */
    private function buildBatchBudget(string $decision, bool $urgentOverride, bool $underPressure, ?string $liveRatioReason = null): array
    {
        if ($urgentOverride) {
            return [
                'max_tasks'              => 1,
                'minimum_leverage_score' => 0.0,
                'evidence_floor'         => 0.0,
                'reason'                 => 'urgent repair bypass: no leverage or evidence floor applies',
            ];
        }

        $ratioSuffix = $liveRatioReason !== null ? " (live servable_per_worker_ratio={$liveRatioReason})" : '';

        return match ($decision) {
            self::DECISION_ENQUEUE_NOW => $underPressure
                ? ['max_tasks' => 3,  'minimum_leverage_score' => 0.75, 'evidence_floor' => 0.50, 'reason' => 'high-leverage bypass under pressure: tight batch of 3']
                : ['max_tasks' => 10, 'minimum_leverage_score' => 0.30, 'evidence_floor' => 0.20, 'reason' => 'healthy queue: standard batch of 10'],
            self::DECISION_CONSOLIDATE => [
                'max_tasks'              => 3,
                'minimum_leverage_score' => 0.50,
                'evidence_floor'         => 0.40,
                'reason'                 => 'worker pressure high: minimum viable batch of 3',
            ],
            self::DECISION_DEFER => [
                'max_tasks'              => 1,
                'minimum_leverage_score' => 0.75,
                'evidence_floor'         => 0.60,
                'reason'                 => 'elevated pressure: at most 1 high-leverage task per digest cycle'.$ratioSuffix,
            ],
            self::DECISION_STOP => [
                'max_tasks'              => 0,
                'minimum_leverage_score' => 1.0,
                'evidence_floor'         => 1.0,
                'reason'                 => 'critical pressure: no new tasks may be enqueued'.$ratioSuffix,
            ],
            default => [
                'max_tasks'              => 1,
                'minimum_leverage_score' => 0.50,
                'evidence_floor'         => 0.30,
                'reason'                 => 'unknown decision: conservative fallback',
            ],
        };
    }
}
