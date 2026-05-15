<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Certifies the read-only execution workspace runtime surface.
 */
final class AgentControlPlaneExecutionWorkspaceCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_execution_workspace_certification.v1';

    public const MODE = 'read_only_agent_control_plane_execution_workspace_certification';

    public function __construct(
        private readonly AgentControlPlaneExecutionWorkspacePlanner $workspacePlanner = new AgentControlPlaneExecutionWorkspacePlanner,
        private readonly AgentControlPlaneWorkProductManifestPlanner $manifestPlanner = new AgentControlPlaneWorkProductManifestPlanner,
        private readonly AgentControlPlaneDiffArtifactPreviewBuilder $diffPreviewBuilder = new AgentControlPlaneDiffArtifactPreviewBuilder,
        private readonly AgentControlPlaneRollbackPlanBuilder $rollbackBuilder = new AgentControlPlaneRollbackPlanBuilder,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $task = $this->sampleTaskPacket();
        $workspace = $this->workspacePlanner->plan($task, $options);
        $manifest = $this->manifestPlanner->plan($task, ['write_set' => $workspace['write_set'] ?? []]);
        $diff = $this->diffPreviewBuilder->preview($workspace, $manifest);
        $rollback = $this->rollbackBuilder->build($workspace, $diff);

        $invariants = [
            $this->inv('workspace_plan_ready', ($workspace['status'] ?? '') === 'workspace_plan_ready', 'workspace planner must produce an isolated plan for a valid task'),
            $this->inv('manifest_plan_present', preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['work_product_manifest_hash'] ?? '')) === 1, 'manifest planner must emit a stable hash'),
            $this->inv('diff_preview_ready', ($diff['status'] ?? '') === 'diff_preview_ready', 'diff preview must be available for write-set outputs'),
            $this->inv('rollback_plan_ready', ($rollback['status'] ?? '') === 'rollback_plan_ready', 'rollback plan must be available before any promotion'),
            $this->inv('workspace_does_not_create_worktree', ($workspace['worktree_create_allowed'] ?? true) === false, 'workspace plan must not create worktrees'),
            $this->inv('diff_does_not_apply_patch', ($diff['patch_apply_allowed'] ?? true) === false, 'diff preview must not apply patches'),
            $this->inv('rollback_does_not_execute', ($rollback['automatic_rollback_allowed'] ?? true) === false, 'rollback plan must not execute automatically'),
            $this->inv('runtime_safety_all_false', $this->runtimeSafetyAllFalse($workspace, $diff, $rollback), 'all runtime safety flags must remain false'),
        ];
        $violations = array_values(array_filter($invariants, static fn (array $i): bool => $i['ok'] === false));
        $allTrue = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allTrue ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'workspace_plan' => $workspace,
            'work_product_manifest_plan' => $manifest,
            'diff_artifact_preview' => $diff,
            'rollback_plan' => $rollback,
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => count($violations),
            'violations' => $violations,
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'worktree_create_allowed' => false,
                'real_file_write_allowed' => false,
                'patch_apply_allowed' => false,
                'automatic_rollback_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'ledger_write_allowed' => false,
                'self_programming_allowed' => false,
                'completion_claim_allowed' => false,
            ],
            'next_action' => $allTrue
                ? 'keep_execution_workspace_runtime_read_only_until_signed_workspace_activation_gate'
                : 'repair_execution_workspace_runtime_invariants_before_promotion',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function sampleTaskPacket(): array
    {
        return [
            'task_packet_id' => 'workspace-runtime-sample',
            'status' => 'planned',
            'workspace_policy' => 'isolated_worktree_required',
            'normalized_scope' => [
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ExampleRuntimeSlice.php',
                    'tests/Feature/Ai/SelfConstruction/ExampleRuntimeSliceTest.php',
                ],
                'scope_in' => ['app/Services/Ai/SelfConstruction'],
            ],
        ];
    }

    private function inv(string $name, bool $ok, string $observation): array
    {
        return ['name' => $name, 'ok' => $ok, 'observation' => $observation];
    }

    private function runtimeSafetyAllFalse(array $workspace, array $diff, array $rollback): bool
    {
        foreach (['dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'ledger_write_allowed', 'self_programming_allowed'] as $flag) {
            if (($workspace[$flag] ?? null) !== false || ($diff[$flag] ?? null) !== false || ($rollback[$flag] ?? null) !== false) {
                return false;
            }
        }

        return ($workspace['worktree_create_allowed'] ?? true) === false
            && ($workspace['real_file_write_allowed'] ?? true) === false
            && ($diff['patch_apply_allowed'] ?? true) === false
            && ($rollback['automatic_rollback_allowed'] ?? true) === false;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
