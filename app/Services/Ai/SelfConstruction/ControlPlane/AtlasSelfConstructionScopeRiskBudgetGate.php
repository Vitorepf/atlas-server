<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\Support\AiValueNormalizer;

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
 *     normalized_scope:list<string>, max_tasks:int, max_cost_units:int, risk_floor:string,
 *     risk_budget_remaining:int, blocking_factors:list<string> }
 *
 * NEW (unconditional, not gated behind supply pressure): a scope broader than
 * BROAD_SCOPE_UNCONDITIONAL_THRESHOLD files with no proof coverage (implementation+test scope,
 * runnable acceptance) is rejected regardless of supply pressure — broad_scope_without_proof_coverage.
 *
 * NEW: optional active_scopes (list<list<string>>) names OTHER concurrently-running cycles' scope.
 * A requested path already touched by an active cycle is a real collision —
 * scope_collision_with_active_cycle:<path>. A requested scope that overlaps NONE of them is
 * parallel-safe and is admitted on the same terms as any other request within budget.
 *
 * risk_budget_remaining (new): cost_budget_units minus (risk-weighted normalized scope size),
 * floored at 0 — a coarse signal of how much budget this request would consume.
 *
 * blocking_factors (new): every blocker classified into one of collision_risk | file_breadth |
 * proof_coverage | worker_capacity | other, deduplicated — a caller can react to the DIMENSION
 * of the problem without parsing every raw blocker string.
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

    /** servable_now below active_leases * this multiplier triggers supply-pressure admission tightening. */
    public const SUPPLY_PRESSURE_MULTIPLIER = 10;

    /** Under supply pressure, normalized scope wider than this is rejected as broad. */
    public const MAX_SCOPE_BREADTH_UNDER_PRESSURE = 2;

    /** Unconditional (no supply-pressure gate): scope wider than this without proof coverage is rejected. */
    public const BROAD_SCOPE_UNCONDITIONAL_THRESHOLD = 3;

    /** @var array<string,int> */
    private const RISK_WEIGHT = ['low' => 1, 'medium' => 2, 'high' => 4, 'hardest' => 8];

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
        $failureRate = AiValueNormalizer::finiteFloatOrNull($facts['failure_rate'] ?? null);
        $giveBackRate = AiValueNormalizer::finiteFloatOrNull($facts['give_back_rate'] ?? null);
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

        $normalizedScope = array_values(array_unique($normalizedScope));
        sort($normalizedScope, SORT_STRING);

        $hasImplementationScope = (bool) ($facts['has_implementation_scope'] ?? false);
        $hasTestScope = (bool) ($facts['has_test_scope'] ?? false);
        $hasRunnableAcceptance = (bool) ($facts['has_runnable_acceptance'] ?? false);
        $hasProofCoverage = $hasImplementationScope && $hasTestScope && $hasRunnableAcceptance;

        // Unconditional broad-scope-without-proof-coverage check (not gated behind supply
        // pressure) — a wide scope with no proof plan is a real risk on any cycle, not only a
        // thin-supply one.
        if (count($normalizedScope) > self::BROAD_SCOPE_UNCONDITIONAL_THRESHOLD && ! $hasProofCoverage) {
            $blockers[] = 'broad_scope_without_proof_coverage';
        }

        // Parallel-safety: a requested path already touched by another active cycle is a real
        // collision risk; a scope that overlaps none of them is parallel-safe.
        $activeScopes = is_array($facts['active_scopes'] ?? null) ? $facts['active_scopes'] : [];
        $activeScopePaths = [];
        foreach ($activeScopes as $scope) {
            if (is_array($scope)) {
                foreach ($scope as $path) {
                    $activeScopePaths[(string) $path] = true;
                }
            }
        }
        foreach ($normalizedScope as $path) {
            if (isset($activeScopePaths[$path])) {
                $blockers[] = 'scope_collision_with_active_cycle:'.$path;
            }
        }

        // Supply-pressure admission tightening: when servable_now is thin relative to
        // active_leases, only high-confidence packets (concrete impl+test scope, runnable
        // acceptance, narrow scope) are admitted — broad/ambiguous packets get starved workers
        // stuck mid-task instead of being caught early.
        $servableNow = isset($facts['servable_now']) && is_numeric($facts['servable_now']) ? (int) $facts['servable_now'] : null;
        $activeLeases = isset($facts['active_leases']) && is_numeric($facts['active_leases']) ? (int) $facts['active_leases'] : null;
        $supplyPressure = $servableNow !== null && $activeLeases !== null
            && $servableNow < $activeLeases * self::SUPPLY_PRESSURE_MULTIPLIER;

        if ($supplyPressure) {
            if (! $hasProofCoverage) {
                $blockers[] = 'supply_pressure_requires_high_confidence_packet';
            }

            if (count($normalizedScope) > self::MAX_SCOPE_BREADTH_UNDER_PRESSURE) {
                $blockers[] = 'supply_pressure_scope_too_broad';
            }

            foreach ($normalizedScope as $path) {
                if (! str_contains(basename($path), '.')) {
                    $blockers[] = 'supply_pressure_ambiguous_scope:'.$path;
                }
            }
        }

        // 24h+ autonomous cycles touching medium+ risk or more than a single-file scope must
        // declare a burn-window budget (failure and give_back ceilings) up front, else an
        // unattended run has no circuit breaker if it starts burning tasks.
        $autonomyWindowHours = AiValueNormalizer::finiteFloatOrNull($facts['autonomy_window_hours'] ?? null) ?? 0.0;
        $scopeIsBroadOrRisky = count($normalizedScope) > 1 || in_array($risk, ['medium', 'high', 'hardest'], true);
        if ($autonomyWindowHours >= 24.0 && $scopeIsBroadOrRisky) {
            $burnWindow = is_array($facts['burn_window'] ?? null) ? $facts['burn_window'] : null;
            $hasFailureCeiling = $burnWindow !== null && is_numeric($burnWindow['max_failure_rate'] ?? null);
            $hasGiveBackCeiling = $burnWindow !== null && is_numeric($burnWindow['max_give_back_rate'] ?? null);
            if (! $hasFailureCeiling || ! $hasGiveBackCeiling) {
                $blockers[] = 'autonomy_window_requires_burn_budget';
            }
        }

        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        $riskWeight = self::RISK_WEIGHT[$risk] ?? self::RISK_WEIGHT[self::RISK_FLOOR_DEFAULT];
        $riskBudgetRemaining = max(0, max(0, $costBudget) - ($riskWeight * count($normalizedScope)));

        $blockingFactors = array_values(array_unique(array_map(
            fn (string $b): string => $this->classifyBlocker($b),
            $blockers,
        )));
        sort($blockingFactors, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'allowed' => $blockers === [],
            'blockers' => $blockers,
            'normalized_scope' => $normalizedScope,
            'max_tasks' => max(0, $taskBudget),
            'max_cost_units' => max(0, $costBudget),
            'risk_floor' => $risk,
            'risk_budget_remaining' => $riskBudgetRemaining,
            'blocking_factors' => $blockingFactors,
        ];
    }

    /**
     * @param  list<string>  $laneRoots
     */
    private function insideAnyRoot(string $path, array $laneRoots): bool
    {
        // Refuse path traversal: '..' in a requested scope path is never inside a lane root.
        // A path like 'laneRoot/../otherLane/x.php' strips the leading component via '..' and
        // textually starts with 'laneRoot/' — the old str_starts_with check would accept it,
        // allowing a requested scope to escape the project lane. Reject all such paths.
        if (str_contains($path, '..')) {
            return false;
        }

        foreach ($laneRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                return true;
            }
        }

        return false;
    }

    /** Classifies a raw blocker string into one of the four canonical risk dimensions. */
    private function classifyBlocker(string $blocker): string
    {
        return match (true) {
            str_starts_with($blocker, 'scope_collision_with_active_cycle') => 'collision_risk',
            str_starts_with($blocker, 'forbidden_organ_touched') => 'collision_risk',
            str_starts_with($blocker, 'scope_outside_lane') => 'collision_risk',
            str_starts_with($blocker, 'broad_scope_without_proof_coverage') => 'proof_coverage',
            str_starts_with($blocker, 'supply_pressure_requires_high_confidence_packet') => 'proof_coverage',
            str_starts_with($blocker, 'supply_pressure_scope_too_broad') => 'file_breadth',
            str_starts_with($blocker, 'supply_pressure_ambiguous_scope') => 'file_breadth',
            str_starts_with($blocker, 'empty_requested_scope') => 'file_breadth',
            str_starts_with($blocker, 'empty_task_budget') => 'worker_capacity',
            str_starts_with($blocker, 'empty_cost_budget') => 'worker_capacity',
            str_starts_with($blocker, 'burn_rate_') => 'worker_capacity',
            str_starts_with($blocker, 'high_risk_requires_rollback_ready') => 'worker_capacity',
            str_starts_with($blocker, 'high_risk_requires_cycle_window') => 'worker_capacity',
            str_starts_with($blocker, 'cycle_window_exhausted') => 'worker_capacity',
            str_starts_with($blocker, 'autonomy_window_requires_burn_budget') => 'worker_capacity',
            default => 'other',
        };
    }
}
