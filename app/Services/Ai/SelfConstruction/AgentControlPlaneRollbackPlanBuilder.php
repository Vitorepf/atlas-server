<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Builds a rollback plan for an execution workspace preview without
 * executing rollback operations.
 */
final class AgentControlPlaneRollbackPlanBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_rollback_plan.v1';

    public const MODE = 'read_only_agent_control_plane_rollback_plan';

    /** @return array<string, mixed> */
    public function build(array $workspacePlan, array $diffPreview): array
    {
        $steps = [];
        foreach ((array) ($diffPreview['artifacts'] ?? []) as $artifact) {
            $path = (string) ($artifact['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $steps[] = [
                'path' => $path,
                'rollback_action' => 'restore_from_operator_reviewed_baseline',
                'requires_human_approval' => true,
                'automatic_rollback_allowed' => false,
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $steps === [] ? 'rollback_plan_empty' : 'rollback_plan_ready',
            'workspace_plan_id' => (string) ($workspacePlan['workspace_plan_id'] ?? ''),
            'step_count' => count($steps),
            'steps' => $steps,
            'automatic_rollback_allowed' => false,
            'real_file_write_allowed' => false,
            'patch_apply_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['rollback_plan_hash'] = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }
}
