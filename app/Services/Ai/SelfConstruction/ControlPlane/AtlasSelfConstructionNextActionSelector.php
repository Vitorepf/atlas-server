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
 *   3. knowledge_sync degraded but rest ready                ⇒ run_knowledge_sync
 *   4. autonomy_mode = 'observe' (never executes)           ⇒ hold_position
 *   5. open verifications exist                              ⇒ verify_candidates
 *   6. candidates ready to promote                           ⇒ prepare_merge
 *   7. tasks pending workers                                 ⇒ schedule_workers
 *   8. backlog has acceptance work but no tasks              ⇒ create_task_packets
 *   9. otherwise                                             ⇒ hold_position
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

        // 3. KNOWLEDGE-SYNC DEGRADED — refresh before anything else continues.
        foreach ($degraded as $row) {
            $organ = is_array($row) ? (string) ($row['organ'] ?? '') : (string) $row;
            if ($organ === 'knowledge_sync') {
                return $this->envelope(self::ACTION_RUN_KNOWLEDGE_SYNC, ['knowledge_sync_degraded'], $scopeGate);
            }
        }

        // 4. OBSERVE MODE — never executes; the cycle observes only.
        if ($mode === 'observe') {
            return $this->envelope(self::ACTION_HOLD_POSITION, ['autonomy_mode_observe_only'], $scopeGate);
        }

        // 5..8 — work-queue driven (execute mode).
        if ((int) ($workQueue['open_verifications'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_VERIFY_CANDIDATES, ['open_verifications_present'], $scopeGate);
        }
        if ((int) ($workQueue['ready_to_promote'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_PREPARE_MERGE, ['ready_to_promote_present'], $scopeGate);
        }
        if ((int) ($workQueue['tasks_pending_workers'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_SCHEDULE_WORKERS, ['tasks_pending_workers'], $scopeGate);
        }
        if ((int) ($workQueue['backlog_acceptance_items'] ?? 0) > 0) {
            return $this->envelope(self::ACTION_CREATE_TASK_PACKETS, ['backlog_acceptance_items_present'], $scopeGate);
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
