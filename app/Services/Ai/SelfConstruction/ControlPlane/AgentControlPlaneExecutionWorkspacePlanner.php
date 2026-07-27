<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Plans an isolated execution workspace for a task packet without creating
 * worktrees, branches, directories or files.
 */
final class AgentControlPlaneExecutionWorkspacePlanner
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_execution_workspace_plan.v1';

    public const MODE = 'read_only_agent_control_plane_execution_workspace_plan';

    /** Workspace policies that are safe for isolated execution. */
    private const ALLOWED_WORKSPACE_POLICIES = [
        'isolated_worktree_required',
        'existing_workspace_read_only',
        'sandbox_preview_only',
    ];

    /** Path prefixes that grant broad root write access — never safe for a task packet. */
    private const UNSAFE_ROOT_WRITE_PREFIXES = [
        '/',
        '*',
        '.',
        'app/',
        'vendor/',
        'config/',
        'routes/',
        'database/',
        'resources/',
        'bootstrap/',
        'public/',
    ];

    /** @return array<string, mixed> */
    public function plan(array $taskPacket = [], array $options = []): array
    {
        $taskPacketId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $writeSet = $this->normalizeList((array) data_get($taskPacket, 'normalized_scope.allowed_files', $options['write_set'] ?? []));
        $readSet = $this->normalizeList((array) data_get($taskPacket, 'normalized_scope.scope_in', $options['read_set'] ?? []));
        $workspacePolicy = (string) ($taskPacket['workspace_policy'] ?? $options['workspace_policy'] ?? 'isolated_worktree_required');
        $workspaceId = 'workspace-'.substr(hash('sha256', json_encode([
            'task_packet_id' => $taskPacketId,
            'workspace_policy' => $workspacePolicy,
            'write_set' => $writeSet,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
        $blockingReasons = [];

        if ($writeSet === []) {
            $blockingReasons[] = 'write_set_empty';
        }
        if (! in_array($workspacePolicy, self::ALLOWED_WORKSPACE_POLICIES, true)) {
            $blockingReasons[] = 'workspace_policy_not_allowed';
        }
        // Detect broad root writes — any write_set entry that is a root-level wildcard or
        // a top-level directory that grants access to the whole project.
        if ($this->hasBroadRootWrites($writeSet)) {
            $blockingReasons[] = 'broad_root_write_detected';
        }
        // Detect unsafe workspace mutation requirements.
        $mutationRequirements = is_array($taskPacket['workspace_mutation_requirements'] ?? null)
            ? $taskPacket['workspace_mutation_requirements']
            : [];
        if (in_array('force_push_main', $mutationRequirements, true)) {
            $blockingReasons[] = 'unsafe_workspace_mutation_force_push_main';
        }
        if (in_array('reset_hard_shared_branch', $mutationRequirements, true)) {
            $blockingReasons[] = 'unsafe_workspace_mutation_reset_hard_shared_branch';
        }

        $plan = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $blockingReasons === [] ? 'workspace_plan_ready' : 'workspace_plan_blocked',
            'workspace_plan_id' => $workspaceId,
            'task_packet_id' => $taskPacketId,
            'workspace_policy' => $workspacePolicy,
            'write_set' => $writeSet,
            'read_set' => $readSet,
            'staging_root' => 'storage/app/atlas/self-construction/agent-control-plane/execution-workspaces/'.$workspaceId,
            'branch_name_hint' => 'atlas/self-construction/'.$workspaceId,
            'allowed_files_boundary' => $writeSet,
            'temp_artifact_policy' => $this->tempArtifactPolicy($workspaceId),
            'cleanup_policy' => $this->cleanupPolicy(),
            'shared_main_safety_notes' => $this->sharedMainSafetyNotes(),
            'checkout_lock_required' => true,
            'scope_lock_required' => true,
            'rollback_plan_required' => true,
            'diff_preview_required' => true,
            'worktree_create_allowed' => false,
            'real_file_write_allowed' => false,
            'provider_call_allowed' => false,
            'dispatch_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'blocking_reasons' => $blockingReasons,
            'runtime_safety' => $this->runtimeSafety(),
        ];
        $plan['workspace_plan_hash'] = $this->stableHash($plan);

        return $plan;
    }

    /**
     * Temp artifact policy — where the workspace may write temporary artifacts
     * without touching the real source tree.
     *
     * @return array<string, mixed>
     */
    private function tempArtifactPolicy(string $workspaceId): array
    {
        return [
            'temp_root' => 'storage/app/atlas/self-construction/agent-control-plane/execution-workspaces/'.$workspaceId.'/tmp/',
            'temp_artifacts_allowed' => true,
            'temp_artifacts_isolated_from_source' => true,
            'temp_artifacts_cleaned_on_teardown' => true,
            'source_tree_write_allowed' => false,
        ];
    }

    /**
     * Cleanup policy — how the workspace must be torn down after task resolution.
     *
     * @return array<string, mixed>
     */
    private function cleanupPolicy(): array
    {
        return [
            'cleanup_required_after_resolution' => true,
            'cleanup_removes_temp_artifacts' => true,
            'cleanup_removes_staging_diffs' => true,
            'cleanup_preserves_committed_evidence' => true,
            'cleanup_never_resets_shared_main' => true,
            'cleanup_never_force_pushes' => true,
        ];
    }

    /**
     * Safety notes for operating against the shared main branch.
     *
     * @return list<string>
     */
    private function sharedMainSafetyNotes(): array
    {
        return [
            'all_writes_must_go_through_scoped_commit_report',
            'no_git_reset_on_shared_main',
            'no_global_checkout_operations',
            'no_cross_worker_file_theft_tolerated',
            'scope_lock_must_be_held_for_entire_write_window',
            'evidence_receipt_must_be_captured_before_cleanup',
        ];
    }

    /**
     * Detect write-set entries that grant broad root access.
     *
     * @param  list<string>  $writeSet
     */
    private function hasBroadRootWrites(array $writeSet): bool
    {
        foreach ($writeSet as $path) {
            $normalized = trim(str_replace('\\', '/', trim($path)), '/');
            // Wildcard root
            if ($normalized === '*' || $normalized === '/*' || $normalized === '**') {
                return true;
            }
            // Bare root
            if ($normalized === '' || $normalized === '.') {
                return true;
            }
            // Top-level project directory that grants broad access
            foreach (self::UNSAFE_ROOT_WRITE_PREFIXES as $prefix) {
                if ($prefix !== '/' && $prefix !== '*' && $prefix !== '.') {
                    $cleanPrefix = trim($prefix, '/');
                    // Only flag if the path IS the directory (not a deeper file).
                    if ($normalized === $cleanPrefix) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return array<string, bool> */
    public function runtimeSafety(): array
    {
        return [
            'runtime_safety_all_false' => true,
            'worktree_create_allowed' => false,
            'real_file_write_allowed' => false,
            'provider_call_allowed' => false,
            'dispatch_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'no_git_reset_on_shared_main' => true,
            'no_global_checkout_operations' => true,
            'scoped_commit_report_only' => true,
            'no_cross_worker_file_theft' => true,
            'scope_lock_required_for_writes' => true,
            'rollback_plan_required_before_writes' => true,
        ];
    }

    /** @return list<string> */
    private function normalizeList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['workspace_plan_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
