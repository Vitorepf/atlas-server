<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
            'sandbox_id' => hash('sha256', $workspace.'|'.$risk.'|'.($write ? 'write' : 'read')),
            'workspace_hash' => hash('sha256', $workspace),
            'mode' => $strong ? 'isolated_worktree_required' : ($write ? 'checkpoint_required' : 'read_only'),
            'write_allowed' => $write,
            'allowed_actions' => $write
                ? ['code_search', 'git_diff', 'patch', 'test', 'lint', 'quality_scan']
                : ['code_search', 'git_diff', 'read_only_analysis'],
            'blocked_actions' => $write ? ['external_provider_call_without_budget', 'destructive_git_reset'] : ['patch', 'write', 'destructive_git_reset'],
            'snapshot_strategy' => $strong ? 'git_worktree_or_temp_clone' : ($write ? 'pre_action_checkpoint' : 'none'),
            'rollback_plan' => [
                'required' => $write,
                'strategy' => $strong ? 'discard_isolated_worktree' : ($write ? 'restore_checkpoint' : 'not_applicable'),
                'must_capture_diff_before_promotion' => $write,
            ],
            'promotion_requires_patch_verifier' => $write,
            'rollback_required' => $write,
            'snapshot_available' => is_dir($workspace) && File::exists($workspace),
            'external_provider_call_required' => false,
            'integrity_gate' => [
                'schema_version' => 'atlas.programming.execution_sandbox.integrity_gate.v1',
                'must_prove_no_untracked_destructive_actions' => true,
                'must_attach_action_manifests' => $write,
                'must_attach_patch_verifier_report' => $write,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function provision(string $workspace, string $risk = 'medium', bool $write = true): array
    {
        $plan = $this->plan($workspace, $risk, $write);
        if (($plan['mode'] ?? null) !== 'isolated_worktree_required') {
            return array_merge($plan, [
                'provisioned' => true,
                'execution_workspace' => realpath($workspace) ?: $workspace,
                'provisioning_mode' => $plan['mode'],
            ]);
        }

        $workspace = realpath($workspace) ?: $workspace;
        if (! is_dir($workspace.'/.git')) {
            return array_merge($plan, [
                'provisioned' => false,
                'execution_workspace' => $workspace,
                'provisioning_mode' => 'checkpoint_fallback',
                'provisioning_blockers' => ['git_repository_required_for_isolated_worktree'],
            ]);
        }

        $sandboxRoot = storage_path('app/ai/programming-sandboxes');
        File::ensureDirectoryExists($sandboxRoot);
        $sandbox = $sandboxRoot.'/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
        $process = new Process(['git', 'worktree', 'add', '--detach', $sandbox, 'HEAD'], $workspace);
        $process->setTimeout(60);
        $process->run();

        if (($process->getExitCode() ?? 1) !== 0) {
            return array_merge($plan, [
                'provisioned' => false,
                'execution_workspace' => $workspace,
                'provisioning_mode' => 'checkpoint_fallback',
                'provisioning_blockers' => ['git_worktree_add_failed'],
                'stderr_hash' => hash('sha256', $process->getErrorOutput()),
            ]);
        }

        return array_merge($plan, [
            'provisioned' => true,
            'execution_workspace' => $sandbox,
            'provisioning_mode' => 'git_worktree',
            'rollback_plan' => array_merge((array) ($plan['rollback_plan'] ?? []), [
                'sandbox_path_hash' => hash('sha256', $sandbox),
                'cleanup_command' => 'git worktree remove --force <sandbox>',
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $sandbox
     * @return array<string,mixed>
     */
    public function rollbackReceipt(array $sandbox, string $status = 'planned'): array
    {
        $payload = [
            'schema_version' => 'atlas.programming.sandbox_rollback_receipt.v1',
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? hash('sha256', json_encode($sandbox, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')),
            'mode' => $sandbox['mode'] ?? null,
            'provisioning_mode' => $sandbox['provisioning_mode'] ?? null,
            'status' => $status,
            'rollback_required' => (bool) ($sandbox['rollback_required'] ?? data_get($sandbox, 'rollback_plan.required', false)),
            'rollback_strategy' => data_get($sandbox, 'rollback_plan.strategy'),
            'sandbox_path_hash' => data_get($sandbox, 'rollback_plan.sandbox_path_hash'),
            'cleanup_command' => data_get($sandbox, 'rollback_plan.cleanup_command'),
            'created_at' => now()->toJSON(),
        ];

        return array_merge($payload, [
            'receipt_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ]);
    }
}
