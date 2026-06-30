<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure Control-Plane selector. Chooses the next SAFE government action from:
 *   - organ readiness (output of AtlasSelfConstructionOrganReadinessComposer)
 *   - scope gate verdict ({allowed:bool, reasons?:list<string>})
 *   - autonomy mode fact ({mode:'off'|'observe'|'execute', reasons?:list<string>})
 *
 * Output (FACTS only):
 *   {schema_version, action, reasons, scope_state}
 *
 * Action set: repair_organs | create_task_packets | schedule_workers | verify_candidates |
 *             prepare_merge | run_knowledge_sync | hold_position
 *
 * Precedence (deterministic):
 *   0. autonomy_mode = 'off'                                ⇒ hold_position
 *   1. scope gate not allowed                               ⇒ hold_position
 *   2. critical organs missing/blocked                      ⇒ repair_organs
 *   3. malformed_count > 0 or poison_packets > 0            ⇒ repair_queue
 *   4. knowledge_sync degraded but rest ready                ⇒ run_knowledge_sync
 *   5. autonomy_mode = 'observe' (never executes)           ⇒ hold_position
 *   6. open verifications exist                              ⇒ verify_candidates
 *   7. candidates ready to promote                           ⇒ prepare_merge
 *   8. tasks pending workers                                 ⇒ schedule_workers
 *   9. backlog has acceptance work but no tasks              ⇒ create_task_packets
 *  10. otherwise                                             ⇒ hold_position
 *
 * Pure: NEVER enqueues, dispatches, executes, merges, or writes ledgers.
 */
final class AtlasSelfConstructionNextActionSelector
{
    public const SCHEMA = 'atlas.self_construction.control_plane_next_action.v1';

    public const ACTION_REPAIR_ORGANS = 'repair_organs';

    public const ACTION_CREATE_TASK_PACKETS = 'create_task_packets';

    public const ACTION_SCHEDULE_WORKERS = 'schedule_workers';

    public const ACTION_VERIFY_CANDIDATES = 'verify_candidates';

    public const ACTION_PREPARE_MERGE = 'prepare_merge';

    public const ACTION_RUN_KNOWLEDGE_SYNC = 'run_knowledge_sync';

    public const ACTION_REPAIR_QUEUE = 'repair_queue';

    public const ACTION_HOLD_POSITION = 'hold_position';

    /**
     * @param  array<string,mixed>  $organReadiness
     * @param  array<string,mixed>  $scopeGate
     * @param  array<string,mixed>  $autonomyMode
     * @param  array<string,mixed>  $workQueue {open_verifications:int, ready_to_promote:int, tasks_pending_workers:int, backlog_acceptance_items:int}
     * @return array<string,mixed>
     */
    public function select(array $organReadiness, array $scopeGate, array $autonomyMode, array $workQueue): array
    {
        $mode = (string) ($autonomyMode['mode'] ?? 'off');
        $scopeAllowed = (bool) ($scopeGate['allowed'] ?? false);

        // 0. AUTONOMY OFF — never execute.
        if ($mode === 'off') {
            return $this->envelope(self::ACTION_HOLD_POSITION, ['autonomy_mode_off'], $scopeGate);
        }
        // 1. SCOPE GATE — never act when scope is not allowed.
        if (! $scopeAllowed) {
            $reasons = ['scope_gate_not_allowed'];
            foreach ((array) ($scopeGate['reasons'] ?? []) as $r) {
                $reasons[] = 'scope:'.(string) $r;
            }

            return $this->envelope(self::ACTION_HOLD_POSITION, $reasons, $scopeGate);
        }

        $missing = (array) ($organReadiness['missing_organs'] ?? []);
        $blocked = (array) ($organReadiness['blocked_organs'] ?? []);
        $degraded = (array) ($organReadiness['degraded_organs'] ?? []);

        // 2. CRITICAL ORGAN REPAIR — priority over any feature expansion.
        if ($missing !== [] || $blocked !== []) {
            $reasons = [];
            foreach ($missing as $organ) {
                $reasons[] = 'missing_organ:'.(string) $organ;
            }
            foreach ($blocked as $row) {
                $organ = is_array($row) ? (string) ($row['organ'] ?? '') : (string) $row;
                $reasons[] = 'blocked_organ:'.$organ;
            }

            return $this->envelope(self::ACTION_REPAIR_ORGANS, $reasons, $scopeGate);
        }

        // 3. QUEUE HYGIENE — repair malformed, poison, or stale-lease packets before scheduling more work.
        $malformed = (int) ($workQueue['malformed_count'] ?? 0);
        $poison = (int) ($workQueue['poison_packets'] ?? 0);
        $staleLeases = (int) ($workQueue['stale_active_leases'] ?? 0);
        if ($malformed > 0 || $poison > 0 || $staleLeases > 0) {
            $reasons = [];
            if ($malformed > 0) {
                $reasons[] = 'malformed_queue_packets:'.$malformed;
            }
            if ($poison > 0) {
                $reasons[] = 'poison_queue_packets:'.$poison;
            }
            if ($staleLeases > 0) {
                $reasons[] = 'stale_active_leases:'.$staleLeases;
            }

            return $this->envelope(self::ACTION_REPAIR_QUEUE, $reasons, $scopeGate);
        }

        // 4. KNOWLEDGE-SYNC DEGRADED — refresh before anything else continues.
        foreach ($degraded as $row) {
            $organ = is_array($row) ? (string) ($row['organ'] ?? '') : (string) $row;
            if ($organ === 'knowledge_sync') {
                return $this->envelope(self::ACTION_RUN_KNOWLEDGE_SYNC, ['knowledge_sync_degraded'], $scopeGate);
            }
        }

        // 5. OBSERVE MODE — never executes; the cycle observes only.
        if ($mode === 'observe') {
            return $this->envelope(self::ACTION_HOLD_POSITION, ['autonomy_mode_observe_only'], $scopeGate);
        }

        // 6..9 — work-queue driven (execute mode).
        if ((int) ($workQueue['open_verifications'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_VERIFY_CANDIDATES, ['open_verifications_present'], $scopeGate);
        }
        if ((int) ($workQueue['ready_to_promote'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_PREPARE_MERGE, ['ready_to_promote_present'], $scopeGate);
        }
        if ((int) ($workQueue['tasks_pending_workers'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_SCHEDULE_WORKERS, ['tasks_pending_workers'], $scopeGate);
        }
        // 8.5. IDLE WORKERS with claimable tasks — schedule before inventing new work.
        $idleWorkers = (int) ($workQueue['idle_workers'] ?? 0);
        $claimableDepth = (int) ($workQueue['claimable_depth'] ?? 0);
        if ($idleWorkers > 0 && $claimableDepth > 0) {
            return $this->envelope(self::ACTION_SCHEDULE_WORKERS, ['idle_workers_with_claimable_tasks'], $scopeGate);
        }
        $backlog = (int) ($workQueue['backlog_acceptance_items'] ?? 0);
        if ($backlog > 0) {
            $reasons = ['backlog_acceptance_items_present'];
            $servableNow = (int) ($workQueue['servable_now'] ?? 0);
            $servabilityFloor = (int) ($workQueue['servability_floor'] ?? 0);
            if ($servabilityFloor > 0 && $servableNow < $servabilityFloor) {
                $reasons[] = 'starvation:servable_now_'.$servableNow.'_below_floor_'.$servabilityFloor;
            }

            return $this->envelope(self::ACTION_CREATE_TASK_PACKETS, $reasons, $scopeGate);
        }

        return $this->envelope(self::ACTION_HOLD_POSITION, ['queue_idle'], $scopeGate);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $scopeGate
     * @return array<string,mixed>
     */
    private function envelope(string $action, array $reasons, array $scopeGate): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'action' => $action,
            'reasons' => array_values($reasons),
            'scope_state' => [
                'allowed' => (bool) ($scopeGate['allowed'] ?? false),
                'reasons' => array_values((array) ($scopeGate['reasons'] ?? [])),
            ],
        ];
    }
}
