<?php

namespace App\Services\Ai\Runtime;

use App\Models\AiToolEvent;
use App\Services\Ai\Context\RetrievalRankInput;
use App\Services\Ai\Programming\ProgrammingActionManifestFactory;
use App\Services\Ai\Programming\ProgrammingActionManifestStore;
use App\Services\Ai\Search\SessionSearchService;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AiToolRuntime
{
    /**
     * @return array<int,string>
     */
    public static function availableTools(): array
    {
        return [
            'workspace.profile',
            'package.detect',
            'file.read',
            'file.write',
            'file.patch',
            'session.search',
            'search.rg',
            'shell.run',
            'git.status',
            'git.diff',
            'git.apply_patch',
            'checkpoint.restore',
            'test.run',
            'programming.test',
            'programming.lint',
            'programming.quality_scan',
            'programming.visual_smoke',
            'programming.git_diff',
            'programming.code_search',
        ];
    }

    public function __construct(
        private readonly AiToolPermissionEngine $permissions,
        private readonly WorkspaceProfiler $profiler,
        private readonly SessionSearchService $sessionSearch,
        private readonly AtlasTestCommandResolver $testCommands,
        private readonly RetrievalRankInput $retrievalRankInput,
        private readonly ProgrammingActionManifestFactory $actionManifests,
        private readonly ProgrammingActionManifestStore $actionManifestStore,
    ) {}

    public function execute(ToolInvocation $invocation): ToolResult
    {
        $permission = $this->permissions->authorize($invocation);
        if (! $permission->allowed) {
            $result = ToolResult::failure($invocation, $permission->requiresApproval ? 'approval_required' : 'permission_denied', $permission->message(), [
                'permission' => $permission->toArray(),
            ]);

            $this->recordToolEvent($invocation, $result, $permission->request->risk, $permission->requiresApproval ? 'denied' : 'denied');

            return $result;
        }

        $started = hrtime(true);

        try {
            $result = match ($invocation->tool) {
                'workspace.profile' => $this->workspaceProfile($invocation),
                'package.detect' => $this->packageDetect($invocation),
                'file.read' => $this->fileRead($invocation),
                'file.write' => $this->fileWrite($invocation),
                'file.patch' => $this->filePatch($invocation),
                'session.search' => $this->sessionSearch($invocation),
                'search.rg' => $this->searchRg($invocation),
                'shell.run' => $this->shellRun($invocation),
                'git.status' => $this->gitStatus($invocation),
                'git.diff' => $this->gitDiffTool($invocation),
                'git.apply_patch' => $this->gitApplyPatch($invocation),
                'checkpoint.restore' => $this->checkpointRestore($invocation),
                'test.run' => $this->testRun($invocation),
                'programming.test' => $this->programmingTest($invocation),
                'programming.lint' => $this->programmingLint($invocation),
                'programming.quality_scan' => $this->programmingQualityScan($invocation),
                'programming.visual_smoke' => $this->programmingVisualSmoke($invocation),
                'programming.git_diff' => $this->gitDiffTool($invocation),
                'programming.code_search' => $this->searchRg($invocation),
                default => ToolResult::failure($invocation, 'unknown_tool', "Ferramenta desconhecida: {$invocation->tool}."),
            };
        } catch (\Throwable $exception) {
            $result = ToolResult::failure($invocation, 'runtime_exception', $exception->getMessage());
            $this->recordToolEvent($invocation, $result, $this->riskFor($invocation), 'approved');

            return $result;
        }

        $result = $this->withDuration($this->withActionRuntimeContract($result, $invocation), (int) ((hrtime(true) - $started) / 1_000_000));
        $this->recordToolEvent($invocation, $result, $this->riskFor($invocation), $this->permissionStatus($invocation));

        return $result;
    }

    private function recordToolEvent(ToolInvocation $invocation, ToolResult $result, string $risk, string $permissionStatus): void
    {
        $traceId = data_get($invocation->metadata, 'trace_id');
        if (! is_string($traceId) || $traceId === '' || ! Schema::hasTable('ai_tool_events')) {
            return;
        }

        // Deterministic event_key: same (trace, tool, invocation_id, status) always
        // produces the same hash. Worker retries that re-execute the same
        // invocation collide on the UNIQUE constraint and fail fast — exactly
        // the behavior we want to prevent silent duplicate inserts.
        // See migration 2026_05_01_006000_harden_ai_tool_events for the constraint.
        $eventKey = $this->eventKeyFor($traceId, $invocation, $permissionStatus);

        // Idempotent persistence: if the same event_key was already inserted
        // (worker double-fire, exception path then success path), updateOrCreate
        // refreshes the row instead of throwing on the UNIQUE constraint.
        AiToolEvent::query()->updateOrCreate(
            ['event_key' => $eventKey],
            [
                'trace_id' => $traceId,
                'session_id' => $invocation->sessionId ?: data_get($invocation->metadata, 'session_id'),
                'thread_id' => data_get($invocation->metadata, 'thread_id'),
                'tool' => $invocation->tool,
                'risk' => $risk,
                'permission_status' => $permissionStatus,
                'approval_source' => is_string(data_get($invocation->metadata, 'approval_source')) ? data_get($invocation->metadata, 'approval_source') : null,
                'input_summary' => $this->inputSummary($invocation),
                'output_summary' => $this->outputSummary($result),
                'changed_files' => $result->changedFiles ?: null,
                'checkpoint_id' => $result->checkpointPath ? basename($result->checkpointPath) : null,
                'exit_code' => $result->exitCode,
                'duration_ms' => $result->durationMs,
                'error' => $result->errorMessage,
                'created_at' => now(),
            ],
        );
    }

    /**
     * Builds the deterministic event_key written to ai_tool_events.event_key.
     *
     * Inputs:
     *   - trace_id: scopes the key to a trace (different traces with the same
     *     invocation_id never collide)
     *   - invocation->id: unique per call (provided by ToolInvocation, e.g. a uuid
     *     allocated when the invocation was queued)
     *   - tool name: defensive — protects against invocation_id reuse across tools
     *   - permission_status: lets the denied-permission and the approved-execution
     *     paths emit DIFFERENT keys for the same invocation, since both call
     *     recordToolEvent (see lines 52 and 84). Without this, the second insert
     *     would clobber the first via updateOrCreate, losing the denial record.
     *
     * Output: 'tool:' + 64-hex-char sha256 prefix, total length ≤ 70 chars
     * (well under the column's 180-char limit).
     */
    private function eventKeyFor(string $traceId, ToolInvocation $invocation, string $permissionStatus): string
    {
        return 'tool:'.substr(hash('sha256', implode(':', [
            $traceId,
            $invocation->tool,
            $invocation->id,
            $permissionStatus,
        ])), 0, 64);
    }

    private function riskFor(ToolInvocation $invocation): string
    {
        return match ($invocation->permissionMode) {
            'danger' => 'high',
            'write' => 'medium',
            default => in_array($invocation->tool, ['shell.run', 'test.run', 'programming.test', 'programming.lint', 'programming.quality_scan', 'programming.visual_smoke'], true) ? 'medium' : 'low',
        };
    }

    private function permissionStatus(ToolInvocation $invocation): string
    {
        if ((bool) data_get($invocation->metadata, 'approved', false)) {
            return 'approved';
        }

        return $invocation->permissionMode === 'read' ? 'auto' : 'approved';
    }

    /**
     * @return array<string,mixed>
     */
    private function inputSummary(ToolInvocation $invocation): array
    {
        $arguments = $invocation->arguments;

        if (isset($arguments['content']) && is_string($arguments['content'])) {
            $arguments['content'] = [
                'bytes' => strlen($arguments['content']),
                'lines' => substr_count($arguments['content'], "\n") + 1,
                'sha256' => hash('sha256', $arguments['content']),
            ];
        }

        if (isset($arguments['patch']) && is_string($arguments['patch'])) {
            $arguments['patch'] = [
                'bytes' => strlen($arguments['patch']),
                'lines' => substr_count($arguments['patch'], "\n") + 1,
                'sha256' => hash('sha256', $arguments['patch']),
            ];
        }

        if (isset($arguments['command']) && is_string($arguments['command'])) {
            $arguments['command'] = Str::limit(AtlasSecurity::redactString($arguments['command']), 200, '...[truncated]');
        }

        return [
            'invocation_id' => $invocation->id,
            'workspace_hash' => hash('sha256', $invocation->workspace),
            'arguments' => AtlasSecurity::redactArray($arguments),
            'dry_run' => $invocation->dryRun,
            'source' => $invocation->source,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function outputSummary(ToolResult $result): array
    {
        $text = $result->output !== '' ? $result->output : trim($result->stdout."\n".$result->stderr);

        return [
            'ok' => $result->ok,
            'summary' => $result->summary,
            'output_bytes' => strlen($text),
            'output_sha256' => $text !== '' ? hash('sha256', $text) : null,
            'diff_sha256' => $result->diff ? hash('sha256', $result->diff) : null,
        ];
    }

    private function workspaceProfile(ToolInvocation $invocation): ToolResult
    {
        $profile = $this->profiler->profile($invocation->workspace, (bool) $invocation->argument('refresh', false));

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Workspace profile gerado.',
            output: json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            metadata: ['profile' => $profile->toArray()],
        );
    }

    private function packageDetect(ToolInvocation $invocation): ToolResult
    {
        $profile = $this->profiler->profile($invocation->workspace, (bool) $invocation->argument('refresh', false));
        $payload = [
            'stack' => $profile->stack,
            'package_manager' => $profile->packageManager,
            'scripts' => $profile->scripts,
            'test_commands' => $profile->testCommands,
            'important_files' => $profile->importantFiles,
        ];

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Pacotes e scripts detectados.',
            output: json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            metadata: $payload,
        );
    }

    private function fileRead(ToolInvocation $invocation): ToolResult
    {
        $path = $this->workspacePath($invocation, (string) $invocation->argument('path'));
        if (! File::isFile($path)) {
            return ToolResult::failure($invocation, 'file_not_found', "Arquivo nao encontrado: {$path}.");
        }

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Arquivo lido.',
            output: AtlasSecurity::redactString(File::get($path)),
            metadata: ['path' => $path, 'relative_path' => $this->relativePath($invocation->workspace, $path)],
        );
    }

    private function fileWrite(ToolInvocation $invocation): ToolResult
    {
        $path = $this->workspacePath($invocation, (string) $invocation->argument('path'), allowMissing: true);
        $content = (string) $invocation->argument('content', '');
        $before = File::exists($path) ? File::get($path) : '';
        $diff = $this->unifiedDiff($before, $content, $this->relativePath($invocation->workspace, $path));

        if ($invocation->dryRun) {
            return new ToolResult(
                ok: true,
                invocationId: $invocation->id,
                tool: $invocation->tool,
                summary: 'Dry-run de escrita concluido.',
                changedFiles: [$this->relativePath($invocation->workspace, $path)],
                diff: AtlasSecurity::redactString($diff),
                metadata: ['dry_run' => true],
            );
        }

        $checkpoint = $this->checkpoint($invocation->workspace, [$path], 'file.write');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Arquivo escrito.',
            changedFiles: [$this->relativePath($invocation->workspace, $path)],
            diff: AtlasSecurity::redactString($this->gitDiff($invocation->workspace, [$path]) ?: $diff),
            checkpointPath: $checkpoint,
        );
    }

    private function filePatch(ToolInvocation $invocation): ToolResult
    {
        $path = $this->workspacePath($invocation, (string) $invocation->argument('path'));
        $search = (string) $invocation->argument('search', '');
        $replace = (string) $invocation->argument('replace', '');
        $all = (bool) $invocation->argument('all', false);

        if ($search === '') {
            return ToolResult::failure($invocation, 'missing_search', 'file.patch exige search.');
        }

        $before = File::get($path);
        if (! str_contains($before, $search)) {
            return ToolResult::failure($invocation, 'search_not_found', 'Texto de busca nao encontrado no arquivo.');
        }

        if ($all) {
            $after = str_replace($search, $replace, $before);
        } else {
            $position = strpos($before, $search);
            $after = $position === false
                ? $before
                : substr($before, 0, $position).$replace.substr($before, $position + strlen($search));
        }
        $diff = $this->unifiedDiff($before, $after, $this->relativePath($invocation->workspace, $path));

        if ($invocation->dryRun) {
            return new ToolResult(
                ok: true,
                invocationId: $invocation->id,
                tool: $invocation->tool,
                summary: 'Dry-run de patch concluido.',
                changedFiles: [$this->relativePath($invocation->workspace, $path)],
                diff: AtlasSecurity::redactString($diff),
                metadata: ['dry_run' => true],
            );
        }

        $checkpoint = $this->checkpoint($invocation->workspace, [$path], 'file.patch');
        File::put($path, $after);

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Patch aplicado.',
            changedFiles: [$this->relativePath($invocation->workspace, $path)],
            diff: AtlasSecurity::redactString($this->gitDiff($invocation->workspace, [$path]) ?: $diff),
            checkpointPath: $checkpoint,
        );
    }

    private function searchRg(ToolInvocation $invocation): ToolResult
    {
        $query = (string) $invocation->argument('query', '');
        if ($query === '') {
            return ToolResult::failure($invocation, 'missing_query', 'search.rg exige query.');
        }

        $args = ['rg', '--line-number', '--hidden', '--glob', '!.git', '--glob', '!node_modules', '--glob', '!vendor', $query];
        $path = $invocation->argument('path');
        if (is_string($path) && $path !== '') {
            $args[] = $path;
        }

        $process = $this->runProcess($args, $invocation->workspace, 20);
        if ((int) $process['exit_code'] !== 0) {
            $fallback = $this->phpCodeSearch($invocation->workspace, $query, is_string($path) && $path !== '' ? $path : null);
            if ($fallback !== '') {
                $process = [
                    'exit_code' => 0,
                    'stdout' => $fallback,
                    'stderr' => '',
                    'duration_ms' => $process['duration_ms'],
                    'command' => ['php_recursive_search', $query],
                ];
            }
        }

        return $this->processResult($invocation, $process, 'Busca concluida.');
    }

    private function sessionSearch(ToolInvocation $invocation): ToolResult
    {
        $query = trim((string) $invocation->argument('query', ''));
        if ($query === '') {
            return ToolResult::failure($invocation, 'missing_query', 'session.search exige query.');
        }

        $workspace = (string) ($invocation->argument('workspace', $invocation->workspace) ?: $invocation->workspace);
        $resolvedWorkspace = realpath($workspace);
        if ($resolvedWorkspace && is_dir($resolvedWorkspace)) {
            $workspace = $resolvedWorkspace;
        }

        $topN = $this->retrievalRankInput->sessionTopN($invocation->argument('top_n'));
        $summarize = (bool) $invocation->argument('summarize', false);
        $results = $this->sessionSearch->search($workspace, $query, $topN, $summarize);
        $payload = [
            'query' => $query,
            'workspace' => $workspace,
            'top_n' => $topN,
            'summarize' => $summarize,
            'count' => count($results),
            'results' => array_map(fn ($result): array => $result->toArray(), $results),
        ];

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Session search retornou '.count($results).' thread(s).',
            output: json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            metadata: $payload,
        );
    }

    private function shellRun(ToolInvocation $invocation): ToolResult
    {
        $command = (string) $invocation->argument('command', '');
        if ($command === '') {
            return ToolResult::failure($invocation, 'missing_command', 'shell.run exige command.');
        }

        if ($invocation->dryRun) {
            $redactedCommand = AtlasSecurity::redactString($command);

            return new ToolResult(
                ok: true,
                invocationId: $invocation->id,
                tool: $invocation->tool,
                summary: 'Dry-run de shell concluido.',
                output: $redactedCommand,
                metadata: [
                    'dry_run' => true,
                    'command' => $redactedCommand,
                    'command_display' => $redactedCommand,
                ],
            );
        }

        $before = $this->gitStatusOutput($invocation->workspace);
        $result = $this->processResult($invocation, $this->runShell($command, $invocation->workspace, (int) $invocation->argument('timeout', 600)), 'Comando executado.');
        $after = $this->gitStatusOutput($invocation->workspace);

        return $this->withRuntimeMutationMetadata($result, $before, $after);
    }

    private function gitStatus(ToolInvocation $invocation): ToolResult
    {
        return $this->processResult($invocation, $this->runProcess(['git', 'status', '--short'], $invocation->workspace), 'Git status concluido.');
    }

    private function gitDiffTool(ToolInvocation $invocation): ToolResult
    {
        $args = ['git', 'diff'];
        $path = $invocation->argument('path');
        if (is_string($path) && $path !== '') {
            $args[] = '--';
            $args[] = $path;
        }

        $process = $this->runProcess($args, $invocation->workspace);
        if ((int) $process['exit_code'] !== 0 && str_contains(strtolower((string) $process['stderr']), 'not a git repository')) {
            return new ToolResult(
                ok: true,
                invocationId: $invocation->id,
                tool: $invocation->tool,
                summary: 'Git diff indisponivel: workspace sem repositorio git.',
                metadata: ['git_available' => false],
            );
        }

        return $this->processResult($invocation, $process, 'Git diff concluido.');
    }

    private function gitApplyPatch(ToolInvocation $invocation): ToolResult
    {
        $patch = (string) $invocation->argument('patch', '');
        if ($patch === '') {
            return ToolResult::failure($invocation, 'missing_patch', 'git.apply_patch exige patch.');
        }

        $paths = $this->patchPaths($patch, $invocation->workspace);
        $check = $this->runProcessWithInput(['git', 'apply', '--check', '-'], $patch, $invocation->workspace);
        if ($check['exit_code'] !== 0) {
            return $this->processResult($invocation, $check, 'Patch rejeitado pelo git apply --check.');
        }

        if ($invocation->dryRun) {
            return new ToolResult(
                ok: true,
                invocationId: $invocation->id,
                tool: $invocation->tool,
                summary: 'Dry-run de git apply concluido.',
                changedFiles: array_map(fn (string $path): string => $this->relativePath($invocation->workspace, $path), $paths),
                diff: AtlasSecurity::redactString($patch),
                metadata: ['dry_run' => true],
            );
        }

        $checkpoint = $this->checkpoint($invocation->workspace, $paths, 'git.apply_patch');
        $apply = $this->runProcessWithInput(['git', 'apply', '--whitespace=nowarn', '-'], $patch, $invocation->workspace);
        $result = $this->processResult($invocation, $apply, $apply['exit_code'] === 0 ? 'Patch git aplicado.' : 'Falha ao aplicar patch git.');

        return new ToolResult(
            ok: $result->ok,
            invocationId: $result->invocationId,
            tool: $result->tool,
            summary: $result->summary,
            output: $result->output,
            stdout: $result->stdout,
            stderr: $result->stderr,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            changedFiles: array_map(fn (string $path): string => $this->relativePath($invocation->workspace, $path), $paths),
            diff: AtlasSecurity::redactString($this->gitDiff($invocation->workspace, $paths) ?: $patch),
            checkpointPath: $checkpoint,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            events: $result->events,
            metadata: $result->metadata,
        );
    }

    private function testRun(ToolInvocation $invocation): ToolResult
    {
        $command = (string) $invocation->argument('command', '');
        if ($command === '') {
            $profile = $this->profiler->profile($invocation->workspace);
            $command = $this->preferredTestCommand($profile->testCommands);
        }

        if ($command === '') {
            return ToolResult::failure($invocation, 'no_test_command', 'Nenhum comando de teste detectado.');
        }

        if ($invocation->dryRun) {
            return $this->dryRunCommandResult($invocation, $command, 'Dry-run de teste concluido.', 'test');
        }

        $runtimeInvocation = $invocation->withMetadata(['test_command' => $command]);
        $before = $this->gitStatusOutput($invocation->workspace);
        $result = $this->processResult($runtimeInvocation, $this->runTestShell($command, $invocation->workspace, (int) $invocation->argument('timeout', 900)), 'Teste executado.');
        $after = $this->gitStatusOutput($invocation->workspace);

        return $this->withRuntimeMutationMetadata($result, $before, $after);
    }

    private function programmingTest(ToolInvocation $invocation): ToolResult
    {
        return $this->testRun($invocation->withMetadata(['programming_action' => 'test']));
    }

    private function programmingLint(ToolInvocation $invocation): ToolResult
    {
        $command = trim((string) $invocation->argument('command', ''));
        if ($command === '') {
            $profile = $this->profiler->profile($invocation->workspace);
            $command = $this->preferredLintCommand((array) $profile->scripts);
        }

        if ($command === '') {
            return ToolResult::failure($invocation, 'no_lint_command', 'Nenhum comando de lint/typecheck detectado.');
        }

        if ($invocation->dryRun) {
            return $this->dryRunCommandResult($invocation, $command, 'Dry-run de lint concluido.', 'lint');
        }

        $before = $this->gitStatusOutput($invocation->workspace);
        $result = $this->processResult(
            $invocation->withMetadata(['programming_action' => 'lint', 'lint_command' => $command]),
            $this->runTestShell($command, $invocation->workspace, (int) $invocation->argument('timeout', 600)),
            'Lint executado.',
        );
        $after = $this->gitStatusOutput($invocation->workspace);

        return $this->withRuntimeMutationMetadata($result, $before, $after);
    }

    private function programmingQualityScan(ToolInvocation $invocation): ToolResult
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'atlas:engineering:quality-scan',
            '--workspace='.$invocation->workspace,
            '--profile='.(string) $invocation->argument('profile', 'auto'),
            '--timeout='.(string) max(1, (int) $invocation->argument('timeout', 300)),
            '--json',
        ];
        if ((bool) $invocation->argument('changed_only', true)) {
            $command[] = '--changed-only';
        }

        if ($invocation->dryRun) {
            return $this->dryRunCommandResult($invocation, $command, 'Dry-run de quality scan concluido.', 'quality_scan');
        }

        return $this->processResult(
            $invocation->withMetadata(['programming_action' => 'quality_scan']),
            $this->runProcess($command, $invocation->workspace, max(5, (int) $invocation->argument('timeout', 300)) + 30),
            'Quality scan executado.',
        );
    }

    private function programmingVisualSmoke(ToolInvocation $invocation): ToolResult
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'atlas:engineering:visual-smoke',
            '--workspace='.$invocation->workspace,
            '--timeout='.(string) max(5, (int) $invocation->argument('timeout', 45)),
            '--json',
        ];
        foreach (['start_command' => '--start-command=', 'url' => '--url=', 'baseline' => '--baseline=', 'screenshot_baseline' => '--screenshot-baseline=', 'screenshot_driver' => '--screenshot-driver='] as $argument => $option) {
            $value = $invocation->argument($argument);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $command[] = $option.trim((string) $value);
            }
        }
        foreach ((array) $invocation->argument('routes', []) as $route) {
            if (is_scalar($route) && trim((string) $route) !== '') {
                $command[] = '--route='.trim((string) $route);
            }
        }

        if ($invocation->dryRun) {
            return $this->dryRunCommandResult($invocation, $command, 'Dry-run de visual smoke concluido.', 'visual_smoke');
        }

        return $this->processResult(
            $invocation->withMetadata(['programming_action' => 'visual_smoke']),
            $this->runProcess($command, $invocation->workspace, max(5, (int) $invocation->argument('timeout', 45)) + 30),
            'Visual smoke executado.',
        );
    }

    private function checkpointRestore(ToolInvocation $invocation): ToolResult
    {
        $checkpoint = (string) $invocation->argument('checkpoint', '');
        if ($checkpoint === '') {
            return ToolResult::failure($invocation, 'missing_checkpoint', 'checkpoint.restore exige checkpoint.');
        }

        $checkpoint = realpath($checkpoint) ?: $checkpoint;
        $metadataPath = $checkpoint.'/checkpoint.json';
        if (! File::exists($metadataPath)) {
            return ToolResult::failure($invocation, 'checkpoint_not_found', "Checkpoint invalido: {$checkpoint}.");
        }

        $metadata = json_decode(File::get($metadataPath), true);
        if (! is_array($metadata)) {
            return ToolResult::failure($invocation, 'checkpoint_invalid', 'checkpoint.json invalido.');
        }

        $workspace = (string) ($metadata['workspace'] ?? $invocation->workspace);
        if (realpath($workspace) !== realpath($invocation->workspace)) {
            return ToolResult::failure($invocation, 'workspace_mismatch', 'Checkpoint pertence a outro workspace.');
        }

        $files = is_array($metadata['files'] ?? null) ? $metadata['files'] : [];
        $changed = [];
        $before = $this->gitStatusOutput($invocation->workspace);

        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                continue;
            }

            $relative = $file['path'];
            $target = $this->workspacePath($invocation, $relative, allowMissing: true);
            $source = $checkpoint.DIRECTORY_SEPARATOR.$relative;
            $existed = (bool) ($file['existed'] ?? false);

            if ($existed && File::exists($source)) {
                File::ensureDirectoryExists(dirname($target));
                File::copy($source, $target);
                $changed[] = $relative;
            } elseif (! $existed && File::exists($target)) {
                File::delete($target);
                $changed[] = $relative;
            }
        }

        $after = $this->gitStatusOutput($invocation->workspace);

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: 'Checkpoint restaurado.',
            changedFiles: array_values(array_unique($changed)),
            diff: $changed === []
                ? null
                : AtlasSecurity::redactString($this->gitDiff($invocation->workspace, array_map(fn (string $path): string => $this->workspacePath($invocation, $path, allowMissing: true), $changed))),
            metadata: [
                'checkpoint' => $checkpoint,
                'git_status_before_hash' => hash('sha256', $before),
                'git_status_after_hash' => hash('sha256', $after),
            ],
        );
    }

    /**
     * @param  array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command?:array<int,string>|string,artifact_path?:string|null}  $process
     */
    private function processResult(ToolInvocation $invocation, array $process, string $summary): ToolResult
    {
        $ok = $process['exit_code'] === 0;
        $stdout = AtlasSecurity::redactString($process['stdout']);
        $stderr = AtlasSecurity::redactString($process['stderr']);
        $output = trim($stdout) !== '' ? $stdout : $stderr;
        $error = trim($stderr) ?: trim($stdout) ?: 'Process failed.';
        $command = $process['command'] ?? null;

        return new ToolResult(
            ok: $ok,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: $ok ? $summary : 'Ferramenta terminou com erro.',
            output: $output,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $process['exit_code'],
            durationMs: $process['duration_ms'],
            errorCode: $ok ? null : 'tool_process_failed',
            errorMessage: $ok ? null : $error,
            metadata: [
                'command' => AtlasSecurity::redactCommandValue($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
                'artifact_path' => $process['artifact_path'] ?? null,
            ],
        );
    }

    private function withRuntimeMutationMetadata(ToolResult $result, string $before, string $after): ToolResult
    {
        $changed = $before !== $after;
        $changedFiles = $changed ? $this->parseStatusChangedFiles($after) : [];

        return new ToolResult(
            ok: $result->ok,
            invocationId: $result->invocationId,
            tool: $result->tool,
            summary: $result->summary,
            output: $result->output,
            stdout: $result->stdout,
            stderr: $result->stderr,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            changedFiles: $changedFiles,
            diff: null,
            checkpointPath: $result->checkpointPath,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            events: $result->events,
            metadata: array_merge($result->metadata, [
                'git_status_before_hash' => hash('sha256', $before),
                'git_status_after_hash' => hash('sha256', $after),
                'git_status_after_count' => count($changedFiles),
                'workspace_mutated' => $changed,
            ]),
        );
    }

    private function withDuration(ToolResult $result, int $durationMs): ToolResult
    {
        return new ToolResult(
            ok: $result->ok,
            invocationId: $result->invocationId,
            tool: $result->tool,
            summary: $result->summary,
            output: $result->output,
            stdout: $result->stdout,
            stderr: $result->stderr,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs > 0 ? $result->durationMs : $durationMs,
            changedFiles: $result->changedFiles,
            diff: $result->diff,
            checkpointPath: $result->checkpointPath,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            events: $result->events,
            metadata: $result->metadata,
        );
    }

    private function withActionRuntimeContract(ToolResult $result, ToolInvocation $invocation): ToolResult
    {
        $manifest = $this->actionManifestStore->persist($this->actionManifests->make($invocation, $result));

        return new ToolResult(
            ok: $result->ok,
            invocationId: $result->invocationId,
            tool: $result->tool,
            summary: $result->summary,
            output: $result->output,
            stdout: $result->stdout,
            stderr: $result->stderr,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            changedFiles: $result->changedFiles,
            diff: $result->diff,
            checkpointPath: $result->checkpointPath,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            events: $result->events,
            metadata: array_merge($result->metadata, [
                'action_runtime_contract' => $this->actionRuntimeContract($invocation, $result),
                'programming_action_manifest' => $manifest,
            ]),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function actionRuntimeContract(ToolInvocation $invocation, ToolResult $result): array
    {
        $writeTool = in_array($invocation->tool, ['file.write', 'file.patch', 'git.apply_patch', 'checkpoint.restore'], true);
        $programmingTool = str_starts_with($invocation->tool, 'programming.');

        return [
            'schema_version' => 'atlas.tool_action_runtime.contract.v1',
            'tool' => $invocation->tool,
            'programming_action' => $programmingTool ? substr($invocation->tool, strlen('programming.')) : data_get($invocation->metadata, 'programming_action'),
            'dry_run' => $invocation->dryRun,
            'permission_mode' => $invocation->permissionMode,
            'operator_approval_required_for_execution' => true,
            'rollback' => [
                'available' => $result->checkpointPath !== null,
                'checkpoint_path_hash' => $result->checkpointPath ? hash('sha256', $result->checkpointPath) : null,
                'restore_tool' => $result->checkpointPath ? 'checkpoint.restore' : null,
            ],
            'evidence' => [
                'invocation_id' => $invocation->id,
                'output_sha256' => $result->output !== '' ? hash('sha256', $result->output) : null,
                'stdout_sha256' => $result->stdout !== '' ? hash('sha256', $result->stdout) : null,
                'stderr_sha256' => $result->stderr !== '' ? hash('sha256', $result->stderr) : null,
                'diff_sha256' => $result->diff ? hash('sha256', $result->diff) : null,
                'changed_file_count' => count($result->changedFiles),
                'artifact_path_hash' => is_string(data_get($result->metadata, 'artifact_path')) ? hash('sha256', (string) data_get($result->metadata, 'artifact_path')) : null,
            ],
            'raw_command_exposed' => false,
            'raw_output_exposed' => false,
            'workspace_path_exposed' => false,
            'provider_dispatch_allowed' => false,
            'runtime_policy_mutation_allowed' => false,
            'agent_control_plane_allowed' => false,
            'write_action' => $writeTool,
        ];
    }

    /**
     * @param  array<int,string>|string  $command
     */
    private function dryRunCommandResult(ToolInvocation $invocation, array|string $command, string $summary, string $action): ToolResult
    {
        $display = is_array($command)
            ? AtlasSecurity::commandLineForDisplay($command)
            : AtlasSecurity::redactString($command);

        return new ToolResult(
            ok: true,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: $summary,
            output: $display,
            metadata: [
                'dry_run' => true,
                'programming_action' => $action,
                'command' => AtlasSecurity::redactCommandValue($command),
                'command_display' => $display,
            ],
        );
    }

    private function workspacePath(ToolInvocation $invocation, string $path, bool $allowMissing = false): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Path vazio.');
        }

        $resolved = AtlasSecurity::canonicalPath($path, $invocation->workspace, $allowMissing);

        if ($resolved === '' || (! $allowMissing && ! File::exists($resolved))) {
            throw new \RuntimeException("Path nao encontrado: {$path}.");
        }

        if (! AtlasSecurity::pathIsInside($resolved, $invocation->workspace)) {
            throw new \RuntimeException("Path fora do workspace: {$path}.");
        }

        return $resolved;
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function checkpoint(string $workspace, array $paths, string $reason): ?string
    {
        $dir = storage_path('app/ai/checkpoints/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)));
        $metadata = [
            'workspace' => $workspace,
            'reason' => $reason,
            'created_at' => now()->toJSON(),
            'files' => [],
        ];

        foreach ($paths as $path) {
            if (! AtlasSecurity::pathIsInside($path, $workspace)) {
                continue;
            }

            $relative = $this->relativePath($workspace, $path);
            $metadata['files'][] = [
                'path' => $relative,
                'existed' => File::exists($path),
            ];

            if (File::isFile($path)) {
                $target = $dir.DIRECTORY_SEPARATOR.$relative;
                File::ensureDirectoryExists(dirname($target));
                File::copy($path, $target);
            }
        }

        File::ensureDirectoryExists($dir);
        File::put($dir.'/checkpoint.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $dir;
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function gitDiff(string $workspace, array $paths): string
    {
        $args = ['git', 'diff', '--'];
        foreach ($paths as $path) {
            $args[] = $this->relativePath($workspace, $path);
        }

        return $this->runProcess($args, $workspace)['stdout'];
    }

    /**
     * @param  array<string,string>  $scripts
     */
    private function preferredLintCommand(array $scripts): string
    {
        foreach (['lint', 'typecheck', 'types', 'check', 'test:lint'] as $script) {
            if (isset($scripts[$script]) && is_string($scripts[$script]) && trim($scripts[$script]) !== '') {
                return "npm run {$script}";
            }
        }

        return '';
    }

    private function phpCodeSearch(string $workspace, string $query, ?string $path = null): string
    {
        $root = $path ? $workspace.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR) : $workspace;
        $root = realpath($root) ?: $root;
        if (! AtlasSecurity::pathIsInside($root, $workspace) || (! is_dir($root) && ! is_file($root))) {
            return '';
        }

        $files = is_file($root)
            ? [new \SplFileInfo($root)]
            : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $lines = [];

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $real = $file->getRealPath();
            if (! is_string($real) || str_contains($real, DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR) || str_contains($real, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR) || str_contains($real, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $handle = @fopen($real, 'r');
            if ($handle === false) {
                continue;
            }
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (str_contains($line, $query)) {
                    $lines[] = $this->relativePath($workspace, $real).':'.$lineNumber.':'.rtrim($line, "\r\n");
                }
                if (count($lines) >= 200) {
                    break 2;
                }
            }
            fclose($handle);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    private function unifiedDiff(string $before, string $after, string $label): string
    {
        $old = tempnam(sys_get_temp_dir(), 'atlas-old-');
        $new = tempnam(sys_get_temp_dir(), 'atlas-new-');
        if (! $old || ! $new) {
            return $before === $after ? '' : "--- {$label}\n+++ {$label}\n";
        }

        File::put($old, $before);
        File::put($new, $after);
        $result = $this->runProcess(['diff', '-u', $old, $new], getcwd() ?: base_path());
        File::delete($old);
        File::delete($new);

        return str_replace([$old, $new], ["a/{$label}", "b/{$label}"], $result['stdout']);
    }

    /**
     * @return array<int,string>
     */
    private function patchPaths(string $patch, string $workspace): array
    {
        preg_match_all('/^(?:---|\+\+\+)\s+(?:a|b)\/(.+)$/m', $patch, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $path): string => trim($path))
            ->reject(fn (string $path): bool => $path === '' || $path === '/dev/null')
            ->map(fn (string $path): string => $this->workspacePath(ToolInvocation::make('git.apply_patch', $workspace), $path, allowMissing: true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function parseStatusChangedFiles(string $status): array
    {
        return collect(explode("\n", $status))
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter()
            ->values()
            ->all();
    }

    private function gitStatusOutput(string $workspace): string
    {
        return $this->runProcess(['git', 'status', '--short'], $workspace)['stdout'];
    }

    /**
     * @param  array<int,string>  $commands
     */
    private function preferredTestCommand(array $commands): string
    {
        return $this->testCommands->preferred($commands);
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:array<int,string>}
     */
    private function runProcess(array $command, string $cwd, int $timeout = 60): array
    {
        $started = hrtime(true);
        $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => AtlasSecurity::redactString($process->getOutput()),
            'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => $command,
        ];
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:string}
     */
    private function runShell(string $command, string $cwd, int $timeout = 600): array
    {
        $started = hrtime(true);
        $process = Process::fromShellCommandline($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => AtlasSecurity::redactString($process->getOutput()),
            'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => AtlasSecurity::redactString($command),
        ];
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:string,artifact_path?:string|null}
     */
    private function runTestShell(string $command, string $cwd, int $timeout = 900): array
    {
        $started = hrtime(true);
        $process = Process::fromShellCommandline($command, $cwd, AtlasSecurity::processEnv($this->testEnvironment(), 'tool'));
        $process->setTimeout($timeout);
        $process->run();
        $stdout = AtlasSecurity::redactString($process->getOutput());
        $stderr = AtlasSecurity::redactString($process->getErrorOutput());
        $exitCode = $process->getExitCode() ?? 1;

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => AtlasSecurity::redactString($command),
            'artifact_path' => $exitCode === 0 ? null : $this->writeTestFailureArtifact($command, $cwd, $stdout, $stderr, $exitCode),
        ];
    }

    private function writeTestFailureArtifact(string $command, string $cwd, string $stdout, string $stderr, int $exitCode): string
    {
        $dir = storage_path('app/ai/test-runs');
        File::ensureDirectoryExists($dir);

        $path = $dir.'/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.log';
        File::put($path, implode("\n", [
            'Atlas test.run failure artifact',
            'generated_at='.now()->toJSON(),
            'cwd='.AtlasSecurity::redactString($cwd),
            'exit_code='.$exitCode,
            'command='.AtlasSecurity::redactString($command),
            '',
            '--- stdout ---',
            $stdout,
            '',
            '--- stderr ---',
            $stderr,
        ]));

        return $path;
    }

    /**
     * @return array<string,string>
     */
    private function testEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'ATLAS_TOKEN' => 'testing-atlas-token-with-enough-length',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:array<int,string>}
     */
    private function runProcessWithInput(array $command, string $input, string $cwd, int $timeout = 60): array
    {
        $started = hrtime(true);
        $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setInput($input);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => AtlasSecurity::redactString($process->getOutput()),
            'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => $command,
        ];
    }

    private function relativePath(string $workspace, string $path): string
    {
        $workspace = rtrim(AtlasSecurity::canonicalPath($workspace), DIRECTORY_SEPARATOR);
        $path = AtlasSecurity::canonicalPath($path, allowMissing: true);

        return str_starts_with($path, $workspace.DIRECTORY_SEPARATOR)
            ? substr($path, strlen($workspace) + 1)
            : $path;
    }

    private function pathIsInside(string $path, string $root): bool
    {
        return AtlasSecurity::pathIsInside($path, $root);
    }
}
