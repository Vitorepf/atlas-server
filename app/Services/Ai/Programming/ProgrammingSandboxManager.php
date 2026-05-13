<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Facades\File;

class ProgrammingSandboxManager
{
    /**
     * @return array<string,mixed>
     */
    public function plan(string $workspace, string $risk = 'medium', bool $write = true): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $strong = $write && in_array($risk, ['high', 'critical', 'forge', 'strict'], true);

        return [
            'schema_version' => 'atlas.programming.execution_sandbox.plan.v1',
            'workspace_hash' => hash('sha256', $workspace),
            'mode' => $strong ? 'isolated_worktree_required' : ($write ? 'checkpoint_required' : 'read_only'),
            'write_allowed' => $write,
            'promotion_requires_patch_verifier' => $write,
            'rollback_required' => $write,
            'snapshot_available' => is_dir($workspace) && File::exists($workspace),
            'external_provider_call_required' => false,
        ];
    }
}
