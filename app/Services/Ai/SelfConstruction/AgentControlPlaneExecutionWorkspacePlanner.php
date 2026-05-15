<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Plans an isolated execution workspace for a task packet without creating
 * worktrees, branches, directories or files.
 */
final class AgentControlPlaneExecutionWorkspacePlanner
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_execution_workspace_plan.v1';

    public const MODE = 'read_only_agent_control_plane_execution_workspace_plan';

    /** @return array<string, mixed> */
    public function plan(array $taskPacket = [], array $options = []): array
    {
        $taskPacketId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $writeSet = $this->normalizeList((array) data_get($taskPacket, 'normalized_scope.allowed_files', $options['write_set'] ?? []));
        $readSet = $this->normalizeList((array) data_get($taskPacket, 'normalized_scope.scope_in', $options['read_set'] ?? []));
        $workspacePolicy = (string) ($taskPacket['workspace_policy'] ?? $options['workspace_policy'] ?? 'isolated_worktree_required');
        $workspaceId = 'workspace-'.substr(hash('sha256', $taskPacketId.'|'.$workspacePolicy.'|'.implode('|', $writeSet)), 0, 16);
        $blockingReasons = [];

        if ($writeSet === []) {
            $blockingReasons[] = 'write_set_empty';
        }
        if (! in_array($workspacePolicy, ['isolated_worktree_required', 'existing_workspace_read_only', 'sandbox_preview_only'], true)) {
            $blockingReasons[] = 'workspace_policy_not_allowed';
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
