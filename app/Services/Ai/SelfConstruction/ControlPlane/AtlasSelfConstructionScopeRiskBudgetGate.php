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
 *     rollback_ready?:bool }                   // required when risk_class >= high
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
        $forbidden = is_array($facts['forbidden_organs'] ?? null) ? array_values(array_map('strval', $facts['forbidden_organs'])) : [];
        $touched = is_array($facts['touched_organs'] ?? null) ? array_values(array_map('strval', $facts['touched_organs'])) : [];
        $rollbackReady = (bool) ($facts['rollback_ready'] ?? false);

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

        // High-risk requires rollback ready.
        if (in_array($risk, self::HIGH_RISKS, true) && ! $rollbackReady) {
            $blockers[] = 'high_risk_requires_rollback_ready';
        }

        sort($blockers, SORT_STRING);
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
