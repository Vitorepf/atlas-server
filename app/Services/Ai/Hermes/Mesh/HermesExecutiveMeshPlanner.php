<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesAdapterReceipt;
use Illuminate\Support\Str;

/**
 * Pure, fail-closed planner for the Atlas Executive Mesh.
 *
 * A parent `atlas.hermes.executive_mission.v1` can be decomposed into an ordered
 * list of child subtasks that Hermes would fan out across worktrees. Atlas — not
 * Hermes — decides IF that fan-out is permitted and HOW WIDE it may run. This
 * planner is a side-effect-free, provider-free policy/parser: it turns the parent
 * mission + subtasks into a sealed `atlas.hermes.mesh_plan.v1` and NOTHING ELSE.
 * It never calls a provider/model, never writes config, never decides policy.
 *
 * Fail-closed contract: `mesh_enabled` and `dispatch_allowed_now` are TRUE only
 * when the operator policy is the Atlas adapter (`$policy['enabled'] === true`,
 * i.e. `config('atlas.ai.providers.hermes_cli.mesh.policy') === 'atlas_adapter'`
 * was resolved by the caller), the subtask list is non-empty, AND every retained
 * child carries a non-empty objective. Any other state yields a plan with
 * `mesh_enabled=false`, `dispatch_allowed_now=false`, and an explicit
 * `blocked_reason`. The worker fan-out is clamped to a config ceiling and the
 * child count is capped to a config maximum with the truncation recorded in the
 * receipt (never silently dropped). Raw objectives never reach the receipt — only
 * their sha256 hash via the sealing trait's {@see hashValue()}.
 */
class HermesExecutiveMeshPlanner
{
    use HermesAdapterReceipt;

    private const DEFAULT_MAX_PARALLEL_WORKERS = 8;

    private const DEFAULT_MAX_CHILDREN = 64;

    /**
     * @var array<int,string>
     */
    private const LEAF_BLOCKED_TOOLSETS = ['delegation', 'clarify', 'memory', 'code_execution', 'send_message'];

    /**
     * Plan an executive mesh fan-out for $parentMission over $subtasks.
     *
     * @param  array<string,mixed>  $parentMission  shaped as `atlas.hermes.executive_mission.v1`
     * @param  array<int,array<string,mixed>>  $subtasks  ordered child specs
     * @param  array<string,mixed>  $policy  resolved operator policy; `enabled === true` only when Atlas adapter
     * @return array<string,mixed>  sealed `atlas.hermes.mesh_plan.v1`
     */
    public function plan(AiJob $job, array $parentMission, array $subtasks, array $policy): array
    {
        $policyEnabled = ($policy['enabled'] ?? null) === true;
        $tracePresent = is_string($job->trace_id) && trim($job->trace_id) !== '';

        $maxChildren = $this->maxChildrenCeiling();
        $workerCeiling = $this->workerCeiling();

        $requestedSubtaskCount = count($subtasks);
        $truncated = $requestedSubtaskCount > $maxChildren;
        $capped = $truncated ? array_slice(array_values($subtasks), 0, $maxChildren) : array_values($subtasks);

        [$children, $allChildrenHaveObjective] = $this->buildChildren($capped);

        $childCount = count($children);
        $maxParallelWorkers = $childCount > 0
            ? min($childCount, $workerCeiling)
            : 0;
        $concurrencyClamped = $childCount > $workerCeiling;

        $receipt = [
            'schema_version' => 'atlas.hermes.mesh_plan.v1',
            'planner' => 'hermes_executive_mesh_planner',
            'authority' => 'atlas',
            'mesh_authority' => 'atlas',
            'hermes_mesh_can_decide' => false,
            'mesh_policy' => $this->string($policy['policy'] ?? null, 60),
            'mesh_enabled' => false,
            'dispatch_allowed_now' => false,
            'fail_closed' => true,
            'parent_mission_id' => $this->string($parentMission['mission_id'] ?? null, 190),
            'parent_mission_hash' => $this->string($parentMission['mission_hash'] ?? null, 190),
            'trace_present' => $tracePresent,
            'requested_subtask_count' => $requestedSubtaskCount,
            'child_count' => $childCount,
            'max_children_ceiling' => $maxChildren,
            'truncated' => $truncated,
            'truncated_count' => $truncated ? $requestedSubtaskCount - $maxChildren : 0,
            'worker_ceiling' => $workerCeiling,
            'max_parallel_workers' => $maxParallelWorkers,
            'concurrency_clamped' => $concurrencyClamped,
            'concurrency_clamp_detail' => $concurrencyClamped
                ? ['requested' => $childCount, 'applied' => $maxParallelWorkers, 'ceiling' => $workerCeiling]
                : null,
            'leaf_blocked_toolsets' => self::LEAF_BLOCKED_TOOLSETS,
            'children' => $children,
            'blocked_reason' => null,
            'status' => 'mesh_disabled_by_policy',
        ];

        if (! $policyEnabled) {
            return $this->blocked($receipt, 'mesh_policy_not_atlas_adapter', 'mesh_disabled_by_policy');
        }

        if ($childCount === 0) {
            return $this->blocked($receipt, 'no_subtasks', 'mesh_blocked_no_subtasks');
        }

        if (! $allChildrenHaveObjective) {
            return $this->blocked($receipt, 'subtask_objective_missing', 'mesh_blocked_invalid_subtask');
        }

        // Fully approved path: policy is the Atlas adapter, there is at least one
        // child, and every retained child carries a non-empty objective.
        $receipt['mesh_enabled'] = true;
        $receipt['dispatch_allowed_now'] = true;
        $receipt['status'] = $truncated ? 'mesh_planned_truncated' : 'mesh_planned';

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function blocked(array $receipt, string $reason, string $status): array
    {
        // Fail-closed: regardless of how far validation progressed, dispatch is
        // never permitted and no worker may run on a blocked plan.
        $receipt['mesh_enabled'] = false;
        $receipt['dispatch_allowed_now'] = false;
        $receipt['max_parallel_workers'] = 0;
        $receipt['concurrency_clamped'] = false;
        $receipt['concurrency_clamp_detail'] = null;
        $receipt['blocked_reason'] = $reason;
        $receipt['status'] = $status;

        return $this->withReceiptHash($receipt);
    }

    /**
     * Build the per-child plan rows. Objectives are hashed, never stored raw.
     *
     * @param  array<int,array<string,mixed>>  $subtasks
     * @return array{0:array<int,array<string,mixed>>,1:bool}  [children, everyChildHasObjective]
     */
    private function buildChildren(array $subtasks): array
    {
        $children = [];
        $allHaveObjective = true;

        foreach (array_values($subtasks) as $index => $subtask) {
            $subtask = is_array($subtask) ? $subtask : [];

            $objective = $this->string($subtask['objective'] ?? null, 8000);
            $hasObjective = $objective !== null;
            if (! $hasObjective) {
                $allHaveObjective = false;
            }

            $children[] = [
                'index' => $index,
                'role' => $this->string($subtask['role'] ?? null, 120) ?? 'worker',
                'objective_present' => $hasObjective,
                // NEVER the raw objective — hash only, so the receipt is safe to seal.
                'objective_hash' => $hasObjective ? $this->hashValue([$objective]) : null,
                'assigned_worktree' => ($subtask['worktree'] ?? false) === true,
                'checkpoints' => $this->checkpointsFor($subtask),
                'requested_toolsets' => $this->toolsetList($subtask['toolsets'] ?? null),
                'success_criteria_count' => count($this->stringList($subtask['success_criteria'] ?? null)),
            ];
        }

        return [$children, $allHaveObjective];
    }

    /**
     * Checkpoints are on by default for any worktree-isolated child so its work
     * is independently verifiable; otherwise honour an explicit flag.
     *
     * @param  array<string,mixed>  $subtask
     */
    private function checkpointsFor(array $subtask): bool
    {
        if (array_key_exists('checkpoints', $subtask)) {
            return ($subtask['checkpoints'] ?? false) === true;
        }

        return ($subtask['worktree'] ?? false) === true;
    }

    private function workerCeiling(): int
    {
        return $this->ceiling('mesh.max_parallel_workers', self::DEFAULT_MAX_PARALLEL_WORKERS);
    }

    private function maxChildrenCeiling(): int
    {
        return $this->ceiling('mesh.max_children', self::DEFAULT_MAX_CHILDREN);
    }

    private function ceiling(string $key, int $default): int
    {
        $value = config('atlas.ai.providers.hermes_cli.'.$key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    /**
     * Strip the always-blocked leaf toolsets so a planned mesh child can never be
     * given delegation/memory/code-exec/etc. capabilities through this surface.
     *
     * @return array<int,string>
     */
    private function toolsetList(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : (is_string($value) ? (preg_split('/\s*,\s*/', $value) ?: []) : []);

        return collect($items)
            ->map(fn (mixed $item): ?string => $this->string($item, 120))
            ->filter()
            ->reject(fn (string $toolset): bool => in_array($toolset, self::LEAF_BLOCKED_TOOLSETS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item): ?string => $this->string($item, 2000))
            ->filter()
            ->values()
            ->all();
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
