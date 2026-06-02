<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesAdapterReceipt;
use App\Services\Ai\Hermes\HermesDelegationAdapter;

/**
 * Atlas Executive Mesh — the sovereign orchestrator that turns ONE mission into
 * a governed many-agent Hermes fleet.
 *
 * It composes the pure leaf components (planner, profile resolver, checkpoint
 * policy) + the delegation cap adapter into a single sealed composed plan, then
 * dispatches the fleet with bounded concurrency through an injected worker seam
 * (MeshWorkerHandle), collects each child's result_packet, and reconciles them.
 *
 * Sovereignty is absolute: this service NEVER decides policy and NEVER calls a
 * model — it plans, clamps, pools, and reconciles. `hermes_mesh_can_decide` is
 * always false; raw objectives never leave the leaf hashing (only the worker
 * closure, built by the operator surface, ever holds raw text — transiently).
 * Fail-closed: when the plan is not dispatch_allowed_now, nothing is launched.
 */
class HermesExecutiveMeshService
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly HermesExecutiveMeshPlanner $planner,
        private readonly HermesProfileResolver $profiles,
        private readonly HermesCheckpointPolicy $checkpoints,
        private readonly HermesMeshReconciler $reconciler,
        private readonly HermesDelegationAdapter $delegation,
        private readonly HermesMeshCheckpointExecutor $checkpointExecutor = new HermesMeshCheckpointExecutor(),
    ) {}

    /**
     * Compose a governed, ready-to-dispatch mesh plan. Pure: builds the plan,
     * resolves a profile per child, applies the checkpoint policy, authorizes
     * the delegation caps. Launches nothing.
     *
     * @param  array<string,mixed>  $parentMission
     * @param  array<int,array<string,mixed>>  $subtasks
     * @param  array<string,mixed>|null  $policy
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @return array<string,mixed>
     */
    public function plan(AiJob $job, array $parentMission, array $subtasks, ?array $policy = null, array $capabilityManifest = []): array
    {
        $policy = $policy ?? ['enabled' => config('atlas.ai.providers.hermes_cli.mesh.policy') === 'atlas_adapter'];
        $plan = $this->planner->plan($job, $parentMission, $subtasks, $policy);

        $permissionMode = $this->permissionMode($parentMission);

        $children = [];
        foreach (($plan['children'] ?? []) as $i => $child) {
            $role = is_string($child['role'] ?? null) ? (string) $child['role'] : '';
            $profile = $this->profiles->resolve($role, $capabilityManifest);
            $checkpoint = $this->checkpoints->decide($parentMission, $permissionMode);

            $children[] = [
                'index' => (int) ($child['index'] ?? $i),
                'role' => $role,
                'objective_hash' => $child['objective_hash'] ?? null,
                'assigned_worktree' => (bool) ($child['assigned_worktree'] ?? false),
                'profile' => $profile['resolved'] ?? [],
                'profile_role_known' => (bool) ($profile['role_known'] ?? false),
                'profile_dropped_toolsets' => $profile['dropped_toolsets'] ?? [],
                'checkpoint_enabled' => (bool) ($checkpoint['enabled'] ?? false),
                'checkpoint_before' => (bool) ($checkpoint['checkpoint_before'] ?? false),
                'rollback_on_failed_verifier' => (bool) ($checkpoint['rollback_on_failed_verifier'] ?? false),
                'requested_toolsets' => $child['requested_toolsets'] ?? [],
            ];
        }

        // Delegation caps bound the fleet's spawn boundary (fail-closed); a
        // disabled policy simply yields a clamped, flat, disabled plan.
        $delegationPolicy = (string) config('atlas.ai.providers.hermes_cli.delegation_policy', 'off');
        $delegationReceipt = $this->delegation->authorize($job, $parentMission, [], $delegationPolicy, $permissionMode, $capabilityManifest);

        $composed = [
            'schema_version' => 'atlas.hermes.mesh_composed_plan.v1',
            'authority' => 'atlas',
            'hermes_mesh_can_decide' => false,
            'mesh_enabled' => ($plan['mesh_enabled'] ?? null) === true,
            'dispatch_allowed_now' => ($plan['dispatch_allowed_now'] ?? null) === true,
            'blocked_reason' => $plan['blocked_reason'] ?? null,
            'max_parallel_workers' => max(0, (int) ($plan['max_parallel_workers'] ?? 0)),
            'permission_mode' => $permissionMode,
            'child_count' => count($children),
            'children' => $children,
            'plan_receipt_hash' => $plan['receipt_hash'] ?? null,
            'delegation_enabled' => (bool) ($delegationReceipt['delegation_enabled'] ?? false),
            'delegation_caps' => $delegationReceipt['caps'] ?? [],
            'delegation_receipt_hash' => $delegationReceipt['receipt_hash'] ?? null,
        ];

        return $this->withReceiptHash($composed);
    }

    /**
     * Dispatch the planned fleet with bounded concurrency. $worker STARTS one
     * child and returns a MeshWorkerHandle. Atlas owns the pool: never more than
     * max_parallel_workers run at once. Fail-closed: when the composed plan is
     * not dispatch_allowed_now, nothing is started and an empty packet list is
     * returned.
     *
     * @param  array<string,mixed>  $composedPlan
     * @param  callable(array<string,mixed>):MeshWorkerHandle  $worker
     * @return array<int,array<string,mixed>>  result packets, child-index ordered
     */
    public function dispatch(array $composedPlan, callable $worker): array
    {
        if (($composedPlan['dispatch_allowed_now'] ?? null) !== true) {
            return [];
        }

        $queue = array_values($composedPlan['children'] ?? []);
        if ($queue === []) {
            return [];
        }

        $limit = max(1, (int) ($composedPlan['max_parallel_workers'] ?? 1));
        $pollMicroseconds = $this->pollInterval();

        /** @var array<int,MeshWorkerHandle> $running */
        $running = [];
        /** @var array<int,array<string,mixed>> $packets */
        $packets = [];
        $next = 0;

        while ($queue !== [] || $running !== []) {
            while (count($running) < $limit && $queue !== []) {
                $child = array_shift($queue);
                $idx = (int) ($child['index'] ?? $next);
                $next++;
                $running[$idx] = $worker($child);
            }

            foreach ($running as $idx => $handle) {
                if ($handle->isFinished()) {
                    $packets[$idx] = $handle->result();
                    unset($running[$idx]);
                }
            }

            if ($running !== [] && $pollMicroseconds > 0) {
                usleep($pollMicroseconds);
            }
        }

        ksort($packets);

        return array_values($packets);
    }

    /**
     * @param  array<string,mixed>  $composedPlan
     * @param  array<int,array<string,mixed>>  $resultPackets
     * @return array<string,mixed>
     */
    public function reconcile(array $composedPlan, array $resultPackets): array
    {
        return $this->reconciler->reconcile($composedPlan, $resultPackets);
    }

    /**
     * End-to-end: plan -> dispatch -> reconcile, sealed into a mesh_run receipt.
     *
     * @param  array<string,mixed>  $parentMission
     * @param  array<int,array<string,mixed>>  $subtasks
     * @param  callable(array<string,mixed>):MeshWorkerHandle  $worker
     * @param  array<string,mixed>|null  $policy
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @param  null|callable(array<string,mixed>,array<string,mixed>):array<string,mixed>  $verifier
     *         optional verifier: ($packet, $child) => ['passed' => bool]. When a
     *         child fails verification, the governed checkpoint executor decides
     *         (fail-closed) whether a rollback is armed.
     * @return array<string,mixed>
     */
    public function run(AiJob $job, array $parentMission, array $subtasks, callable $worker, ?array $policy = null, array $capabilityManifest = [], ?callable $verifier = null): array
    {
        $plan = $this->plan($job, $parentMission, $subtasks, $policy, $capabilityManifest);
        $packets = $this->dispatch($plan, $worker);
        $reconciliation = $this->reconcile($plan, $packets);
        $checkpointActions = $verifier !== null
            ? $this->evaluateRollbacks($plan, $packets, $verifier)
            : [];

        $run = [
            'schema_version' => 'atlas.hermes.mesh_run.v1',
            'authority' => 'atlas',
            'hermes_mesh_can_decide' => false,
            'mesh_enabled' => (bool) ($plan['mesh_enabled'] ?? false),
            'dispatched_count' => count($packets),
            'rollbacks_armed' => count(array_filter($checkpointActions, static fn (array $a): bool => ($a['rollback_now'] ?? false) === true)),
            'plan_receipt_hash' => $plan['receipt_hash'] ?? null,
            'reconciliation_receipt_hash' => $reconciliation['receipt_hash'] ?? null,
            'aggregate_status' => $reconciliation['aggregate_status'] ?? 'empty',
            'reconciliation_allowed_now' => (bool) ($reconciliation['reconciliation_allowed_now'] ?? false),
        ];

        return [
            'run' => $this->withReceiptHash($run),
            'plan' => $plan,
            'result_packets' => $packets,
            'reconciliation' => $reconciliation,
            'checkpoint_actions' => $checkpointActions,
        ];
    }

    /**
     * Per-child rollback-on-failed-verifier decision loop. Reconstructs each
     * child's governed checkpoint decision from the plan and asks the executor
     * (fail-closed) whether a rollback is armed for a failed verification.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<int,array<string,mixed>>  $packets
     * @param  callable(array<string,mixed>,array<string,mixed>):array<string,mixed>  $verifier
     * @return array<int,array<string,mixed>>
     */
    private function evaluateRollbacks(array $plan, array $packets, callable $verifier): array
    {
        $children = is_array($plan['children'] ?? null) ? array_values($plan['children']) : [];
        $permissionMode = (string) ($plan['permission_mode'] ?? 'read');
        $actions = [];

        foreach ($children as $i => $child) {
            $packet = $packets[$i] ?? null;
            if ($packet === null) {
                continue;
            }

            $verifierResult = $verifier($packet, $child);
            $decision = [
                'enabled' => (bool) ($child['checkpoint_enabled'] ?? false),
                'checkpoint_before' => (bool) ($child['checkpoint_before'] ?? false),
                'rollback_on_failed_verifier' => (bool) ($child['rollback_on_failed_verifier'] ?? false),
                'permission_mode' => $permissionMode,
            ];

            $action = $this->checkpointExecutor->evaluate($decision, is_array($verifierResult) ? $verifierResult : []);
            $action['child_index'] = (int) ($child['index'] ?? $i);
            $actions[] = $action;
        }

        return $actions;
    }

    /**
     * @param  array<string,mixed>  $parentMission
     */
    private function permissionMode(array $parentMission): string
    {
        $mode = data_get($parentMission, 'scope.permission_mode', 'read');
        $mode = is_string($mode) ? strtolower(trim($mode)) : 'read';

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }

    private function pollInterval(): int
    {
        $value = config('atlas.ai.providers.hermes_cli.mesh.poll_interval_microseconds', 50000);

        return is_numeric($value) ? max(0, (int) $value) : 50000;
    }
}
