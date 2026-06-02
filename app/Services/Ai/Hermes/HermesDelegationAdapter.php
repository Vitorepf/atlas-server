<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use Illuminate\Support\Str;

/**
 * Governed multi-agent delegation boundary for the Hermes Executive Runtime.
 *
 * Hermes ships a `delegate_task` tool (enabled via the `delegation` toolset)
 * that lets an orchestrator agent spawn child agents. Atlas — not Hermes —
 * decides IF delegation is permitted, clamps every cap to a config ceiling,
 * forces a FLAT spawn depth by default, and ALWAYS strips the leaf-blocked
 * child toolsets (delegation, clarify, memory, code_execution, send_message)
 * so a child can never re-delegate, persist memory, run code, or message out.
 *
 * This adapter is fail-closed: delegation is enabled ONLY when the operator
 * policy is the Atlas adapter, the `delegation` capability is present in the
 * passed capability manifest (read model), the permission mode is write/danger
 * (never read), and an ATLS trace is present. It returns a config PLAN — it
 * never writes `~/.hermes/config.yaml` inline. `delegation_authority` is always
 * Atlas and `hermes_delegation_can_decide` is always false. No raw child goals
 * or prompts ever reach the receipt — hashes only.
 */
class HermesDelegationAdapter
{
    use HermesAdapterReceipt;

    /**
     * @var array<int,string>
     */
    private const BLOCKED_CHILD_TOOLSETS = ['delegation', 'clarify', 'memory', 'code_execution', 'send_message'];

    /**
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @return array<string,mixed>
     */
    public function authorize(AiJob $job, array $mission, array $invocation, string $delegationPolicy, string $permissionMode, array $capabilityManifest = []): array
    {
        $tracePresent = is_string($job->trace_id) && trim($job->trace_id) !== '';
        $capabilityPresent = $this->capabilityPresent($capabilityManifest);
        $permissionMode = $this->permissionMode($permissionMode);
        $missionId = $this->string($mission['mission_id'] ?? null, 190);
        $missionHash = $this->string($mission['mission_hash'] ?? null, 190);
        $cliInvocationHash = $this->hashValue($invocation);

        $requestedCaps = $this->requestedCaps($job, $mission);
        $orchestratorAuthorized = $this->orchestratorAuthorized($job, $mission);

        [$caps, $clamped] = $this->clampToPolicy($requestedCaps, $orchestratorAuthorized);

        $requestedToolsets = $this->requestedChildToolsets($job, $mission);
        $allowedChildToolsets = $this->allowedChildToolsets($requestedToolsets, $capabilityManifest);

        $receipt = [
            'schema_version' => 'atlas.hermes.delegation_adapter_receipt.v1',
            'adapter' => 'hermes_delegation_adapter',
            'delegation_policy' => $delegationPolicy,
            'delegation_authority' => 'atlas',
            'hermes_delegation_can_delegate' => true,
            'hermes_delegation_can_decide' => false,
            'capability_present' => $capabilityPresent,
            'delegation_enabled' => false,
            'permission_mode' => $permissionMode,
            'caps' => $caps,
            'requested_caps' => $requestedCaps,
            'clamped' => $clamped,
            'allowed_child_toolsets' => $allowedChildToolsets,
            'blocked_child_toolsets' => self::BLOCKED_CHILD_TOOLSETS,
            'orchestrator_authorized' => $orchestratorAuthorized,
            'depth_gt_1_blocked' => false,
            'blocked_reason' => null,
            'child_runs_evidence_tracked' => true,
            'subagent_stop_hook_required' => true,
            'config_plan' => $this->configPlan($caps),
            'toolset_mutation' => $this->toolsetMutation($requestedToolsets, false),
            'mission_id' => $missionId,
            'mission_hash' => $missionHash,
            'cli_invocation_hash' => $cliInvocationHash,
            'status' => 'delegation_disabled_by_policy',
        ];

        if ($delegationPolicy !== 'atlas_adapter') {
            $receipt['blocked_reason'] = 'delegation_policy_not_atlas_adapter';

            return $this->finalizeBlocked($receipt, $caps, $requestedToolsets, $orchestratorAuthorized, 'delegation_disabled_by_policy');
        }

        if (! $capabilityPresent) {
            $receipt['blocked_reason'] = 'delegation_capability_unavailable';

            return $this->finalizeBlocked($receipt, $caps, $requestedToolsets, $orchestratorAuthorized, 'capability_unavailable');
        }

        if ($permissionMode === 'read') {
            $receipt['blocked_reason'] = 'permission_mode_read';

            return $this->finalizeBlocked($receipt, $caps, $requestedToolsets, $orchestratorAuthorized, 'delegation_blocked');
        }

        if (! in_array($permissionMode, ['write', 'danger'], true)) {
            $receipt['blocked_reason'] = 'permission_mode_not_write_or_danger';

            return $this->finalizeBlocked($receipt, $caps, $requestedToolsets, $orchestratorAuthorized, 'delegation_blocked');
        }

        if (! $tracePresent) {
            $receipt['blocked_reason'] = 'atls_trace_missing';

            return $this->finalizeBlocked($receipt, $caps, $requestedToolsets, $orchestratorAuthorized, 'delegation_blocked');
        }

        // Delegation is authorized. Depth has already been clamped to the
        // ceiling only when both orchestrator-authority flags are present; flag
        // any requested depth > 1 that was forced back to flat.
        $depthGtOneBlocked = ! $orchestratorAuthorized && (int) ($requestedCaps['max_spawn_depth']) > 1;

        $receipt['delegation_enabled'] = true;
        $receipt['depth_gt_1_blocked'] = $depthGtOneBlocked;
        $receipt['toolset_mutation'] = $this->toolsetMutation($requestedToolsets, true);

        if ($depthGtOneBlocked) {
            $receipt['blocked_reason'] = 'orchestrator_authority_required_for_depth_gt_1';
        }

        $receipt['status'] = ($orchestratorAuthorized && (int) ($caps['max_spawn_depth']) > 1)
            ? 'delegation_authorized_orchestrated'
            : 'delegation_authorized_flat';

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $caps
     * @param  array<int,string>  $requestedToolsets
     * @return array<string,mixed>
     */
    private function finalizeBlocked(array $receipt, array $caps, array $requestedToolsets, bool $orchestratorAuthorized, string $status): array
    {
        $receipt['delegation_enabled'] = false;
        $receipt['status'] = $status;
        // Fail-closed: never append the delegation toolset, force flat depth in
        // the plan, and keep orchestration disabled regardless of request.
        $caps['max_spawn_depth'] = 1;
        $caps['orchestrator_enabled'] = false;
        $receipt['caps'] = $caps;
        $receipt['config_plan'] = $this->configPlan($caps);
        $receipt['toolset_mutation'] = $this->toolsetMutation($requestedToolsets, false);
        $receipt['depth_gt_1_blocked'] = $receipt['blocked_reason'] === 'orchestrator_authority_required_for_depth_gt_1';

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     */
    private function capabilityPresent(array $capabilityManifest): bool
    {
        foreach ($capabilityManifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $this->string($entry['id'] ?? null, 190);
            $class = $this->string($entry['capability_class'] ?? null, 40);
            $key = $this->string($entry['capability_key'] ?? null, 190);
            $matchesDelegation = $id === 'delegation:supported'
                || ($class === 'delegation' && $key === 'supported');

            if ($matchesDelegation && (bool) ($entry['supported'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     */
    private function toolsetPresentInManifest(string $toolset, array $capabilityManifest): bool
    {
        foreach ($capabilityManifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $class = $this->string($entry['capability_class'] ?? null, 40);
            $key = $this->string($entry['capability_key'] ?? null, 190);
            if ($class === 'toolset' && $key === $toolset && (bool) ($entry['supported'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $mission
     * @return array<string,int>
     */
    private function requestedCaps(AiJob $job, array $mission): array
    {
        $fromMission = data_get($mission, 'delegation', []);
        $fromMission = is_array($fromMission) ? $fromMission : [];
        $fromJob = data_get($job->payload, 'hermes.delegation', []);
        $fromJob = is_array($fromJob) ? $fromJob : [];
        $source = $fromMission !== [] ? $fromMission : $fromJob;

        return [
            'max_concurrent_children' => $this->intOr($source['max_concurrent_children'] ?? null, $this->concurrencyCeiling()),
            'max_spawn_depth' => $this->intOr($source['max_spawn_depth'] ?? null, 1),
            'child_timeout_seconds' => $this->intOr($source['child_timeout_seconds'] ?? null, $this->childTimeoutCeiling()),
            'max_iterations' => $this->intOr($source['max_iterations'] ?? null, $this->maxIterationsCeiling()),
        ];
    }

    /**
     * @param  array<string,int>  $requestedCaps
     * @return array{0:array<string,mixed>,1:array<int,array<string,int>>}
     */
    private function clampToPolicy(array $requestedCaps, bool $orchestratorAuthorized): array
    {
        $concurrencyCeiling = $this->concurrencyCeiling();
        $depthCeiling = $this->depthCeiling();
        $timeoutCeiling = $this->childTimeoutCeiling();
        $iterationsCeiling = $this->maxIterationsCeiling();

        // Depth: forced flat (1) unless orchestrator authority is granted, then
        // it may rise up to the ceiling.
        $requestedDepth = (int) $requestedCaps['max_spawn_depth'];
        $depthApplied = $orchestratorAuthorized
            ? min(max($requestedDepth, 1), $depthCeiling)
            : 1;

        $concurrencyApplied = min((int) $requestedCaps['max_concurrent_children'], $concurrencyCeiling);
        $timeoutApplied = min((int) $requestedCaps['child_timeout_seconds'], $timeoutCeiling);
        $iterationsApplied = min((int) $requestedCaps['max_iterations'], $iterationsCeiling);

        $clamped = [];
        if ($concurrencyApplied !== (int) $requestedCaps['max_concurrent_children']) {
            $clamped[] = ['field' => 'max_concurrent_children', 'requested' => (int) $requestedCaps['max_concurrent_children'], 'applied' => $concurrencyApplied, 'ceiling' => $concurrencyCeiling];
        }
        if ($depthApplied !== $requestedDepth) {
            $clamped[] = ['field' => 'max_spawn_depth', 'requested' => $requestedDepth, 'applied' => $depthApplied, 'ceiling' => $depthCeiling];
        }
        if ($timeoutApplied !== (int) $requestedCaps['child_timeout_seconds']) {
            $clamped[] = ['field' => 'child_timeout_seconds', 'requested' => (int) $requestedCaps['child_timeout_seconds'], 'applied' => $timeoutApplied, 'ceiling' => $timeoutCeiling];
        }
        if ($iterationsApplied !== (int) $requestedCaps['max_iterations']) {
            $clamped[] = ['field' => 'max_iterations', 'requested' => (int) $requestedCaps['max_iterations'], 'applied' => $iterationsApplied, 'ceiling' => $iterationsCeiling];
        }

        $caps = [
            'max_concurrent_children' => $concurrencyApplied,
            'max_spawn_depth' => $depthApplied,
            'orchestrator_enabled' => $orchestratorAuthorized && $depthApplied > 1,
            'child_timeout_seconds' => $timeoutApplied,
            'max_iterations' => $iterationsApplied,
            'model' => null,
            'provider' => null,
        ];

        return [$caps, $clamped];
    }

    /**
     * @param  array<string,mixed>  $caps
     * @return array<string,mixed>
     */
    private function configPlan(array $caps): array
    {
        return [
            'schema_version' => 'atlas.hermes.delegation_config_plan.v1',
            'delegation' => [
                'max_concurrent_children' => (int) $caps['max_concurrent_children'],
                'max_spawn_depth' => (int) $caps['max_spawn_depth'],
                'orchestrator_enabled' => (bool) $caps['orchestrator_enabled'],
                'child_timeout_seconds' => (int) $caps['child_timeout_seconds'],
                'max_iterations' => (int) $caps['max_iterations'],
                'model' => $caps['model'] ?? null,
                'provider' => $caps['provider'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $requestedToolsets
     * @return array<string,mixed>
     */
    private function toolsetMutation(array $requestedToolsets, bool $enabled): array
    {
        $before = $requestedToolsets;
        $appended = $enabled && ! in_array('delegation', $before, true);
        $after = $before;
        if ($appended) {
            $after[] = 'delegation';
        }

        return [
            'before' => array_values($before),
            'after' => array_values($after),
            'delegation_appended' => $appended,
        ];
    }

    /**
     * Requested child toolsets MINUS the always-blocked leaf set MINUS any
     * toolset that is not present in the capability manifest. When the manifest
     * carries no toolset entries at all (the common stateless test case), the
     * manifest filter is skipped so the leaf-block remains the binding control.
     *
     * @param  array<int,string>  $requestedToolsets
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @return array<int,string>
     */
    private function allowedChildToolsets(array $requestedToolsets, array $capabilityManifest): array
    {
        $manifestHasToolsets = $this->manifestHasToolsetEntries($capabilityManifest);

        return collect($requestedToolsets)
            ->reject(fn (string $toolset): bool => in_array($toolset, self::BLOCKED_CHILD_TOOLSETS, true))
            ->reject(fn (string $toolset): bool => $manifestHasToolsets && ! $this->toolsetPresentInManifest($toolset, $capabilityManifest))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     */
    private function manifestHasToolsetEntries(array $capabilityManifest): bool
    {
        foreach ($capabilityManifest as $entry) {
            if (is_array($entry) && $this->string($entry['capability_class'] ?? null, 40) === 'toolset') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $mission
     * @return array<int,string>
     */
    private function requestedChildToolsets(AiJob $job, array $mission): array
    {
        $raw = data_get($mission, 'delegation.child_toolsets');
        if ($raw === null) {
            $raw = data_get($job->payload, 'hermes.delegation.child_toolsets');
        }

        return $this->toolsetList($raw);
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    private function orchestratorAuthorized(AiJob $job, array $mission): bool
    {
        return data_get($mission, 'delegation.orchestrator_authorized') === true
            && data_get($job->payload, 'hermes.delegation.orchestrator_authorized') === true;
    }

    private function permissionMode(string $permissionMode): string
    {
        $mode = strtolower(trim($permissionMode));

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }

    /**
     * @return array<int,string>
     */
    private function toolsetList(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : (is_string($value) ? preg_split('/\s*,\s*/', $value) ?: [] : []);

        return collect($items)
            ->map(fn (mixed $item): ?string => $this->string($item, 120))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function intOr(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function concurrencyCeiling(): int
    {
        return $this->ceiling('delegation_max_concurrent_children', 3);
    }

    private function depthCeiling(): int
    {
        return $this->ceiling('delegation_spawn_depth_ceiling', 1);
    }

    private function childTimeoutCeiling(): int
    {
        return $this->ceiling('delegation_child_timeout_seconds_max', 600);
    }

    private function maxIterationsCeiling(): int
    {
        return $this->ceiling('delegation_max_iterations_max', 50);
    }

    private function ceiling(string $key, int $default): int
    {
        $value = config('atlas.ai.providers.hermes_cli.'.$key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
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
