<?php

namespace App\Console\Commands;

use App\Models\AiPermissionSession;
use App\Services\Ai\Runtime\AiToolPermissionEngine;
use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\ToolResult;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasRuntimeCommand extends Command
{
    protected $signature = 'atlas:runtime
        {tool=workspace.profile : Runtime tool}
        {arguments?* : Tool-specific arguments}
        {--workspace= : Workspace path. Defaults to current directory}
        {--permission=auto : auto, read, write or danger}
        {--trace= : Trace id used to persist this tool event}
        {--thread= : Thread id used for persisted tool event}
        {--path= : File/path argument}
        {--query= : search.rg or session.search query}
        {--top-n=3 : Number of session.search thread results}
        {--summarize : Return compact local excerpts for session.search}
        {--command= : shell.run or test.run command}
        {--content= : file.write content}
        {--search= : file.patch search text}
        {--replace= : file.patch replacement text}
        {--patch= : git.apply_patch patch text}
        {--checkpoint= : checkpoint.restore checkpoint path}
        {--stdin : Read content/patch/search text from STDIN}
        {--all : Replace all matches for file.patch}
        {--timeout=600 : Tool timeout in seconds when applicable}
        {--refresh-index : Force workspace profile refresh}
        {--dry-run : Preview without writing}
        {--yes : Approve required write permission for this invocation}
        {--remember= : Remember this approval for a duration such as 30m, 2h or 1d}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas native tool runtime with permissions, checkpoints, diffs and workspace profiling.';

    public function handle(
        AiToolRuntime $runtime,
        AiToolPermissionEngine $permissions,
        AtlasWorkspacePathResolverService $workspacePaths,
        AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate,
    ): int {
        $tool = $this->normalizeTool((string) $this->argument('tool'));
        $workspace = $this->workspace();
        $arguments = $this->toolArguments($tool);
        $probe = ToolInvocation::make($tool, $workspace, $arguments, [
            'permission_mode' => 'read',
            'dry_run' => (bool) $this->option('dry-run'),
            'metadata' => ['approved' => false],
        ]);
        $permissionMode = $this->permissionMode((string) $this->option('permission'), $permissions->requiredModeFor($probe));
        $invocation = ToolInvocation::make($tool, $workspace, $arguments, [
            'permission_mode' => $permissionMode,
            'dry_run' => (bool) $this->option('dry-run'),
            'metadata' => [
                'approved' => (bool) $this->option('yes'),
                'approval_source' => (bool) $this->option('yes') ? 'cli_yes_flag' : null,
                'trace_id' => is_string($this->option('trace')) ? $this->option('trace') : null,
                'thread_id' => is_string($this->option('thread')) ? $this->option('thread') : null,
            ],
        ]);

        $awisBlock = $this->awisMutationBlock($tool, $workspace, $arguments, $workspacePaths, $workspaceGate);
        if ($awisBlock !== null) {
            $this->printResult(ToolResult::failure($invocation, (string) $awisBlock['error'], 'AWIS bloqueou ferramenta mutativa sem workspace certificado.', [
                'awis_execution_gate' => $awisBlock,
            ]));

            return self::FAILURE;
        }

        $decision = $permissions->authorize($invocation);
        if ($decision->requiresApproval && ! (bool) $this->option('yes')) {
            if (! $this->input->isInteractive() || (bool) $this->option('json')) {
                $this->printResult(ToolResult::failure($invocation, 'approval_required', $decision->message(), [
                    'permission' => $decision->toArray(),
                ]));

                return self::FAILURE;
            }

            $this->warn($decision->message());
            $this->line('Tool: '.$decision->request->tool);
            $this->line('Workspace: '.$decision->request->workspace);
            $this->line('Risco: '.$decision->request->risk);
            $this->line('Motivo: '.$decision->request->reason);
            if ($decision->request->command) {
                $this->line('Comando: '.$decision->request->command);
            }
            if ($decision->request->paths !== []) {
                $this->line('Paths: '.implode(', ', $decision->request->paths));
            }

            if (! $this->confirm('Permitir esta execucao uma vez?', false)) {
                $this->printResult(ToolResult::failure($invocation, 'approval_denied', 'Operador negou a execucao.', [
                    'permission' => $decision->toArray(),
                ]));

                return self::FAILURE;
            }

            $invocation = $invocation->withMetadata([
                'approved' => true,
                'approval_source' => 'interactive_cli_once',
            ]);
        }

        $this->rememberApprovalIfRequested($invocation);

        $result = $runtime->execute($invocation);
        $this->printResult($result);

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    private function printResult(ToolResult $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line($result->ok ? '<info>'.$result->summary.'</info>' : '<error>'.$result->summary.'</error>');

        if ($result->changedFiles !== []) {
            $this->line('Arquivos afetados: '.implode(', ', $result->changedFiles));
        }

        if ($result->checkpointPath) {
            $this->line('Checkpoint: '.$result->checkpointPath);
        }

        if ($result->diff) {
            $this->line('');
            $this->line($result->diff);
        } elseif (trim($result->output) !== '') {
            $this->line('');
            $this->line($result->output);
        }

        if (! $result->ok && $result->errorMessage) {
            $this->error($result->errorMessage);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function toolArguments(string $tool): array
    {
        $free = collect((array) $this->argument('arguments'))
            ->map(fn (mixed $argument): string => (string) $argument)
            ->filter(fn (string $argument): bool => $argument !== '')
            ->values()
            ->all();
        $stdin = (bool) $this->option('stdin') ? stream_get_contents(STDIN) : null;
        $path = $this->option('path') ?: ($free[0] ?? null);

        return match ($tool) {
            'workspace.profile', 'package.detect' => [
                'refresh' => (bool) $this->option('refresh-index'),
            ],
            'file.read' => [
                'path' => $path,
            ],
            'file.write' => [
                'path' => $path,
                'content' => is_string($stdin) ? $stdin : (string) ($this->option('content') ?? ''),
            ],
            'file.patch' => [
                'path' => $path,
                'search' => is_string($stdin) ? $stdin : (string) ($this->option('search') ?? ''),
                'replace' => (string) ($this->option('replace') ?? ''),
                'all' => (bool) $this->option('all'),
            ],
            'search.rg' => [
                'query' => (string) ($this->option('query') ?: implode(' ', $free)),
                'path' => $this->option('path'),
            ],
            'session.search' => [
                'query' => (string) ($this->option('query') ?: implode(' ', $free)),
                'workspace' => $this->workspace(),
                'top_n' => (int) $this->option('top-n'),
                'summarize' => (bool) $this->option('summarize'),
            ],
            'shell.run' => [
                'command' => (string) ($this->option('command') ?: implode(' ', $free)),
                'timeout' => (int) $this->option('timeout'),
            ],
            'test.run' => [
                'command' => (string) ($this->option('command') ?: implode(' ', $free)),
                'timeout' => (int) $this->option('timeout'),
            ],
            'git.status' => [],
            'git.diff' => [
                'path' => $this->option('path') ?: ($free[0] ?? null),
            ],
            'git.apply_patch' => [
                'patch' => is_string($stdin) ? $stdin : (string) ($this->option('patch') ?? ''),
            ],
            'checkpoint.restore' => [
                'checkpoint' => (string) ($this->option('checkpoint') ?: ($free[0] ?? '')),
            ],
            default => [],
        };
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function normalizeTool(string $tool): string
    {
        return match (Str::of($tool)->lower()->trim()->value()) {
            'profile', 'workspace', 'workspace.profile' => 'workspace.profile',
            'package', 'package.detect', 'detect' => 'package.detect',
            'read', 'file.read' => 'file.read',
            'write', 'file.write' => 'file.write',
            'patch', 'file.patch' => 'file.patch',
            'search', 'rg', 'search.rg' => 'search.rg',
            'session.search', 'session-search', 'search.sessions', 'history.search', 'conversation.search' => 'session.search',
            'shell', 'run', 'shell.run' => 'shell.run',
            'status', 'git.status' => 'git.status',
            'diff', 'git.diff' => 'git.diff',
            'apply', 'git.apply', 'git.apply_patch' => 'git.apply_patch',
            'restore', 'rollback', 'checkpoint.restore' => 'checkpoint.restore',
            'test', 'test.run' => 'test.run',
            default => $tool,
        };
    }

    private function permissionMode(string $requested, string $required): string
    {
        $requested = Str::of($requested)->lower()->trim()->value();

        return in_array($requested, ['read', 'write', 'danger'], true) ? $requested : $required;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>|null
     */
    private function awisMutationBlock(
        string $tool,
        string $workspace,
        array $arguments,
        AtlasWorkspacePathResolverService $workspacePaths,
        AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate,
    ): ?array {
        if ((bool) $this->option('dry-run')) {
            return null;
        }

        if (! in_array($tool, ['file.write', 'file.patch', 'git.apply_patch', 'checkpoint.restore'], true)) {
            return null;
        }

        $resolution = $workspacePaths->resolveForExecution($workspace);
        if (($resolution['status'] ?? null) !== 'ready') {
            return [
                'schema_version' => 'atlas.runtime.awis_execution_gate.v1',
                'status' => 'blocked',
                'error' => 'awis_workspace_required_for_runtime_tool',
                'tool' => $tool,
                'workspace_resolution' => $resolution,
                'target_path' => is_string($arguments['path'] ?? null) ? $arguments['path'] : null,
            ];
        }

        $gate = $workspaceGate->gate(
            workspace: (string) ($resolution['workspace_slug'] ?? $workspace),
            mode: $tool === 'git.apply_patch' ? 'patch' : 'dev',
            task: 'Atlas runtime tool '.$tool,
        );

        if (($gate['allowed'] ?? false) === true) {
            return null;
        }

        return [
            'schema_version' => 'atlas.runtime.awis_execution_gate.v1',
            'status' => 'blocked',
            'error' => 'awis_execution_gate_blocked',
            'tool' => $tool,
            'workspace_resolution' => $resolution,
            'awis_execution_gate' => $gate,
            'target_path' => is_string($arguments['path'] ?? null) ? $arguments['path'] : null,
        ];
    }

    private function rememberApprovalIfRequested(ToolInvocation $invocation): void
    {
        $duration = $this->option('remember');
        if (! is_string($duration) || $duration === '' || ! DatabaseTableAvailability::has('ai_permission_sessions')) {
            return;
        }

        if (! (bool) data_get($invocation->metadata, 'approved', false) && ! (bool) $this->option('yes')) {
            return;
        }

        $seconds = $this->durationSeconds($duration);
        if ($invocation->permissionMode === 'danger') {
            $seconds = min($seconds, 30 * 60);
        }

        AiPermissionSession::query()->create([
            'workspace' => $invocation->workspace,
            'mode' => $invocation->permissionMode,
            'allowed_tools' => [$invocation->tool],
            'allowed_paths' => array_values(array_filter([
                $invocation->argument('path'),
            ], 'is_string')) ?: null,
            'denied_patterns' => ['**/.env', '**/secrets/*', '**/*token*'],
            'expires_at' => now()->addSeconds($seconds),
            'granted_by' => 'atlas_runtime_remember',
            'reason' => 'Runtime approval remembered from atlas runtime --remember.',
        ]);
    }

    private function durationSeconds(string $value): int
    {
        if (preg_match('/^(\d+)\s*([mhd])$/i', trim($value), $matches) !== 1) {
            return 2 * 60 * 60;
        }

        $amount = max(1, (int) $matches[1]);

        return match (strtolower($matches[2])) {
            'd' => $amount * 86400,
            'h' => $amount * 3600,
            default => $amount * 60,
        };
    }
}
