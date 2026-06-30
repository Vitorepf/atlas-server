<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure Control Plane gate. Bounds each autonomous cycle by OWNER SCOPE, RISK CLASS, TASK COUNT and
 * COST BUDGET before any work is scheduled. NEVER schedules / executes / shells / gits / mutates.
 *
 * INPUT FACTS:
 *   { requested_scope:list<string>,
 *     risk_class ∈ {low,medium,high,hardest},
 *     task_budget:int,
 *     cost_budget_units:int,
 *     project_lane:{project_id:string, allowed_scope_roots:list<string>},
 *     forbidden_organs:list<string>,           // organs that touched_organs must NOT include
 *     touched_organs:list<string>,
 *     rollback_ready?:bool,                    // required when risk_class >= high
 *     failure_rate?:float,                     // observed failure fraction; must stay ≤ MAX_FAILURE_RATE
 *     give_back_rate?:float,                   // observed give_back fraction; must stay ≤ MAX_GIVE_BACK_RATE
 *     cycle_window?:{remaining_cycles:int} }   // required and must have remaining_cycles > 0 for high/hardest
 *
 * OUTPUT:
 *   { schema, allowed:bool, blockers:list<string>,
 *     normalized_scope:list<string>, max_tasks:int, max_cost_units:int, risk_floor:string }
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (blockers sorted; normalized_scope sorted).
 *   - PURE.
 *   - allowed=true ONLY when no blocker triggers.
 */
final class AtlasSelfConstructionScopeRiskBudgetGate
{
    public const SCHEMA = 'atlas.controlplane.scope_risk_budget_gate.v1';

    public const RISK_FLOOR_DEFAULT = 'low';

    public const RISKS = ['low', 'medium', 'high', 'hardest'];

    public const HIGH_RISKS = ['high', 'hardest'];

    public const MAX_FAILURE_RATE = 0.3;

    public const MAX_GIVE_BACK_RATE = 0.5;

    /**
     * @param  array{
     *     requested_scope?:list<string>,
     *     risk_class?:string,
     *     task_budget?:int,
     *     cost_budget_units?:int,
     *     project_lane?:array{project_id?:string, allowed_scope_roots?:list<string>},
     *     forbidden_organs?:list<string>,
     *     touched_organs?:list<string>,
     *     rollback_ready?:bool
     * }  $facts
     * @return array{schema:string, allowed:bool, blockers:list<string>, normalized_scope:list<string>, max_tasks:int, max_cost_units:int, risk_floor:string}
     */
    public function evaluate(array $facts): array
    {
        $blockers = [];

        $requested = is_array($facts['requested_scope'] ?? null) ? array_values(array_map('strval', $facts['requested_scope'])) : [];
        $risk = (string) ($facts['risk_class'] ?? self::RISK_FLOOR_DEFAULT);
        $taskBudget = (int) ($facts['task_budget'] ?? 0);
        $costBudget = (int) ($facts['cost_budget_units'] ?? 0);
        $lane = is_array($facts['project_lane'] ?? null) ? $facts['project_lane'] : [];
        $laneRoots = is_array($lane['allowed_scope_roots'] ?? null) ? array_map('strval', $lane['allowed_scope_roots']) : [];

        if ($requested === []) {
            $blockers[] = 'empty_requested_scope';
        }

        $projectId = trim((string) ($lane['project_id'] ?? ''));
        if ($projectId === '') {
            $blockers[] = 'project_lane_id_missing';
        }
        $forbidden = is_array($facts['forbidden_organs'] ?? null) ? array_values(array_map('strval', $facts['forbidden_organs'])) : [];
        $touched = is_array($facts['touched_organs'] ?? null) ? array_values(array_map('strval', $facts['touched_organs'])) : [];
        $rollbackReady = (bool) ($facts['rollback_ready'] ?? false);
        $failureRate = isset($facts['failure_rate']) && is_numeric($facts['failure_rate']) ? (float) $facts['failure_rate'] : null;
        $giveBackRate = isset($facts['give_back_rate']) && is_numeric($facts['give_back_rate']) ? (float) $facts['give_back_rate'] : null;
        $cycleWindow = is_array($facts['cycle_window'] ?? null) ? $facts['cycle_window'] : null;

        if (! in_array($risk, self::RISKS, true)) {
            $blockers[] = 'invalid_risk_class:'.($risk === '' ? 'missing' : $risk);
            $risk = self::RISK_FLOOR_DEFAULT;
        }
        if ($taskBudget <= 0) {
            $blockers[] = 'empty_task_budget';
        }
        if ($costBudget <= 0) {
            $blockers[] = 'empty_cost_budget';
        }

        // Scope expansion check.
        $normalizedScope = [];
        if ($laneRoots === []) {
            $blockers[] = 'missing_project_lane_scope_roots';
        } else {
            foreach ($requested as $path) {
                if ($this->insideAnyRoot($path, $laneRoots)) {
                    $normalizedScope[] = $path;
                } else {
                    $blockers[] = 'scope_outside_lane:'.$path;
                }
            }
        }

        // Forbidden organ mutation.
        $hits = array_values(array_intersect($touched, $forbidden));
        if ($hits !== []) {
            foreach ($hits as $h) {
                $blockers[] = 'forbidden_organ_touched:'.$h;
            }
        }

        // Burn-rate checks.
        if ($failureRate !== null && $failureRate > self::MAX_FAILURE_RATE) {
            $blockers[] = 'burn_rate_failure_rate_exceeded';
        }
        if ($giveBackRate !== null && $giveBackRate > self::MAX_GIVE_BACK_RATE) {
            $blockers[] = 'burn_rate_give_back_rate_exceeded';
        }

        // High-risk requires rollback ready.
        if (in_array($risk, self::HIGH_RISKS, true) && ! $rollbackReady) {
            $blockers[] = 'high_risk_requires_rollback_ready';
        }

        // High-risk requires a valid cycle window.
        if (in_array($risk, self::HIGH_RISKS, true)) {
            if ($cycleWindow === null) {
                $blockers[] = 'high_risk_requires_cycle_window';
            } elseif ((int) ($cycleWindow['remaining_cycles'] ?? 0) <= 0) {
                $blockers[] = 'cycle_window_exhausted';
            }
        }

        sort($blockers, SORT_STRING);
        $normalizedScope = array_values(array_unique($normalizedScope));
        sort($normalizedScope, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'allowed' => $blockers === [],
            'blockers' => $blockers,
            'normalized_scope' => $normalizedScope,
            'max_tasks' => max(0, $taskBudget),
            'max_cost_units' => max(0, $costBudget),
            'risk_floor' => $risk,
        ];
    }

    /**
     * @param  list<string>  $laneRoots
     */
    private function insideAnyRoot(string $path, array $laneRoots): bool
    {
        foreach ($laneRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                return true;
            }
        }

        return false;
    }
}
