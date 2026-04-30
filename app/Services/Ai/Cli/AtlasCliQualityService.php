<?php

namespace App\Services\Ai\Cli;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

class AtlasCliQualityService
{
    public function __construct(
        private readonly WorkspaceProfiler $profiler,
        private readonly AiToolRuntime $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function evaluate(string $workspace, bool $runTests = false, ?string $testCommand = null, bool $approved = false, ?string $traceId = null): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $profile = $this->profiler->profile($workspace);
        $status = $this->runtime->execute(ToolInvocation::make('git.status', $workspace, [], $this->runtimeOptions($traceId)));
        $diff = $this->runtime->execute(ToolInvocation::make('git.diff', $workspace, [], $this->runtimeOptions($traceId)));
        $changedFiles = $this->changedFiles($status->stdout);
        $testResult = null;

        if ($runTests) {
            $testResult = $this->runtime->execute(ToolInvocation::make('test.run', $workspace, [
                'command' => $testCommand ?: $this->preferredTestCommand($profile->testCommands),
                'timeout' => 900,
            ], $this->runtimeOptions($traceId, [
                'permission_mode' => 'write',
                'metadata' => [
                    'approved' => $approved,
                    'approval_source' => $approved ? 'atlas_cli_quality' : null,
                ],
            ])));
        }

        $gates = $this->gates($status->ok, $diff->ok, $changedFiles, $runTests, $testResult);

        return [
            'workspace' => $workspace,
            'generated_at' => now()->toJSON(),
            'status' => $this->overallStatus($gates),
            'changed_files' => $changedFiles,
            'dirty_count' => count($changedFiles),
            'diff_hash' => $diff->stdout !== '' ? hash('sha256', $diff->stdout) : null,
            'diff_excerpt' => Str::limit(AtlasSecurity::redactString($diff->stdout), 12000, "\n...[diff truncated by Atlas]"),
            'test_commands_detected' => $profile->testCommands,
            'test_result' => $testResult ? $testResult->toArray() : null,
            'quality_gates' => $gates,
            'completion_packet' => [
                'status' => $this->overallStatus($gates),
                'summary' => $this->summary($changedFiles, $runTests, $testResult),
                'files_changed' => $changedFiles,
                'tests' => $testResult ? [[
                    'command' => data_get($testResult->metadata, 'command'),
                    'ok' => $testResult->ok,
                    'exit_code' => $testResult->exitCode,
                    'duration_ms' => $testResult->durationMs,
                    'error' => $testResult->errorMessage ? AtlasSecurity::redactString($testResult->errorMessage) : null,
                ]] : [],
                'quality_gates' => $gates,
                'risks' => $this->risks($changedFiles, $runTests, $testResult),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    public function compact(array $quality, int $fileLimit = 40): array
    {
        $changedFiles = array_values((array) ($quality['changed_files'] ?? []));
        $preview = array_slice($changedFiles, 0, max(0, $fileLimit));
        $testResult = is_array($quality['test_result'] ?? null) ? $quality['test_result'] : null;

        return [
            'workspace' => $quality['workspace'] ?? null,
            'generated_at' => $quality['generated_at'] ?? null,
            'status' => $quality['status'] ?? 'unknown',
            'dirty_count' => (int) ($quality['dirty_count'] ?? count($changedFiles)),
            'changed_files_preview' => $preview,
            'changed_files_truncated_count' => max(0, count($changedFiles) - count($preview)),
            'diff_hash' => $quality['diff_hash'] ?? null,
            'test_commands_detected' => $quality['test_commands_detected'] ?? [],
            'test_result' => $testResult ? [
                'ok' => (bool) ($testResult['ok'] ?? false),
                'exit_code' => $testResult['exit_code'] ?? null,
                'duration_ms' => $testResult['duration_ms'] ?? null,
                'error' => is_string($testResult['error_message'] ?? null) ? AtlasSecurity::redactString($testResult['error_message']) : null,
            ] : null,
            'quality_gates' => $quality['quality_gates'] ?? [],
            'completion_packet' => [
                'status' => data_get($quality, 'completion_packet.status'),
                'summary' => data_get($quality, 'completion_packet.summary'),
                'risks' => data_get($quality, 'completion_packet.risks', []),
                'tests' => data_get($quality, 'completion_packet.tests', []),
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function changedFiles(string $status): array
    {
        return collect(explode("\n", $status))
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $commands
     */
    private function preferredTestCommand(array $commands): string
    {
        return in_array('php artisan test', $commands, true)
            ? 'php artisan test'
            : ($commands[0] ?? '');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function runtimeOptions(?string $traceId, array $overrides = []): array
    {
        $metadata = is_array($overrides['metadata'] ?? null) ? $overrides['metadata'] : [];
        if ($traceId) {
            $metadata['trace_id'] = $traceId;
        }

        return array_merge($overrides, ['metadata' => $metadata]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function gates(bool $statusOk, bool $diffOk, array $changedFiles, bool $runTests, mixed $testResult): array
    {
        $gates = [
            [
                'name' => 'git_status',
                'status' => $statusOk ? 'passed' : 'failed',
                'detail' => $statusOk ? 'Git status executado.' : 'Git status falhou.',
            ],
            [
                'name' => 'git_diff',
                'status' => $diffOk ? 'passed' : 'failed',
                'detail' => $diffOk ? 'Git diff executado.' : 'Git diff falhou.',
            ],
            [
                'name' => 'workspace_changes',
                'status' => $this->workspaceChangesStatus($changedFiles, $runTests, $testResult),
                'detail' => $this->workspaceChangesDetail($changedFiles, $runTests, $testResult),
            ],
        ];

        if ($runTests) {
            $gates[] = [
                'name' => 'tests',
                'status' => $testResult?->ok ? 'passed' : 'failed',
                'detail' => $testResult?->ok ? 'Testes passaram.' : ($testResult?->errorMessage ?: 'Testes nao passaram ou foram bloqueados.'),
            ];
        } elseif ($changedFiles !== []) {
            $gates[] = [
                'name' => 'tests',
                'status' => 'needs_review',
                'detail' => 'Ha mudancas pendentes e os testes nao foram executados neste gate.',
            ];
        }

        return $gates;
    }

    private function overallStatus(array $gates): string
    {
        $statuses = collect($gates)->pluck('status');

        if ($statuses->contains('failed')) {
            return 'failed';
        }

        if ($statuses->contains('needs_review')) {
            return 'needs_review';
        }

        return 'passed';
    }

    private function workspaceChangesStatus(array $changedFiles, bool $runTests, mixed $testResult): string
    {
        if ($changedFiles === []) {
            return 'passed';
        }

        if ($runTests && $testResult?->ok) {
            return 'passed';
        }

        return 'needs_review';
    }

    private function workspaceChangesDetail(array $changedFiles, bool $runTests, mixed $testResult): string
    {
        if ($changedFiles === []) {
            return 'Workspace sem mudancas pendentes.';
        }

        if ($runTests && $testResult?->ok) {
            return count($changedFiles).' arquivo(s) alterado(s); diff pendente coberto por teste executado.';
        }

        return count($changedFiles).' arquivo(s) alterado(s).';
    }

    private function summary(array $changedFiles, bool $runTests, mixed $testResult): string
    {
        if ($changedFiles === []) {
            return 'Nenhuma mudanca pendente detectada no workspace.';
        }

        if (! $runTests) {
            return count($changedFiles).' arquivo(s) alterado(s); testes ainda nao executados neste gate.';
        }

        return $testResult?->ok
            ? count($changedFiles).' arquivo(s) alterado(s); testes executados com sucesso.'
            : count($changedFiles).' arquivo(s) alterado(s); testes falharam ou foram bloqueados.';
    }

    /**
     * @return array<int,string>
     */
    private function risks(array $changedFiles, bool $runTests, mixed $testResult): array
    {
        $risks = [];

        if ($changedFiles !== [] && ! $runTests) {
            $risks[] = 'Mudancas sem execucao de testes neste gate.';
        }

        if ($testResult && ! $testResult->ok) {
            $risks[] = 'Teste falhou ou foi bloqueado; nao declarar trabalho como final.';
        }

        return $risks;
    }
}
