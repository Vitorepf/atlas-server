<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\ToolResult;

class ProgrammingActionManifestFactory
{
    /**
     * @return array<string,mixed>
     */
    public function make(ToolInvocation $invocation, ToolResult $result): array
    {
        $programmingTool = str_starts_with($invocation->tool, 'programming.');
        $stage = (string) data_get($invocation->metadata, 'stage', $this->stageForTool($invocation->tool));
        $planId = is_string(data_get($invocation->metadata, 'plan_id')) ? (string) data_get($invocation->metadata, 'plan_id') : null;
        $gate = $this->gateEffect($invocation, $result);

        return [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'action_id' => $invocation->id,
            'plan_id' => $planId,
            'stage' => $stage,
            'tool' => $invocation->tool,
            'programming_tool' => $programmingTool,
            'permission_mode' => $invocation->permissionMode,
            'dry_run' => $invocation->dryRun,
            'inputs' => [
                'workspace_hash' => hash('sha256', $invocation->workspace),
                'arguments_hash' => hash('sha256', json_encode($invocation->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ],
            'outputs' => [
                'exit_code' => $result->exitCode,
                'ok' => $result->ok,
                'stdout_hash' => $result->stdout !== '' ? hash('sha256', $result->stdout) : null,
                'stderr_hash' => $result->stderr !== '' ? hash('sha256', $result->stderr) : null,
                'output_hash' => $result->output !== '' ? hash('sha256', $result->output) : null,
                'diff_hash' => $result->diff ? hash('sha256', $result->diff) : null,
            ],
            'changed_files' => $result->changedFiles,
            'rollback' => [
                'available' => $result->checkpointPath !== null,
                'checkpoint_id' => $result->checkpointPath ? basename($result->checkpointPath) : null,
                'checkpoint_path_hash' => $result->checkpointPath ? hash('sha256', $result->checkpointPath) : null,
                'restore_tool' => $result->checkpointPath ? 'checkpoint.restore' : null,
            ],
            'gate_effect' => $gate,
            'next_action' => match ($gate) {
                'passed', 'advisory', 'not_applicable' => 'continue',
                default => 'repair',
            },
            'created_at' => now()->toJSON(),
        ];
    }

    private function stageForTool(string $tool): string
    {
        return match ($tool) {
            'programming.test', 'programming.lint', 'programming.quality_scan', 'programming.visual_smoke', 'test.run' => 'test',
            'file.write', 'file.patch', 'git.apply_patch' => 'patch',
            'programming.git_diff', 'programming.code_search', 'search.rg', 'git.diff' => 'review',
            default => 'tool',
        };
    }

    private function gateEffect(ToolInvocation $invocation, ToolResult $result): string
    {
        if ($invocation->dryRun) {
            return 'advisory';
        }
        if (! str_starts_with($invocation->tool, 'programming.') && ! in_array($invocation->tool, ['test.run', 'file.write', 'file.patch', 'git.apply_patch'], true)) {
            return 'not_applicable';
        }

        return $result->ok ? 'passed' : 'failed';
    }
}
