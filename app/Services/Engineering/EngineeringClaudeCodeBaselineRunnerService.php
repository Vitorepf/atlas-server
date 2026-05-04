<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasTask;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class EngineeringClaudeCodeBaselineRunnerService
{
    use RunsCliProcesses;

    /**
     * @param  array<string,mixed>  $runnerOptions
     * @return array<string,mixed>|null
     */
    public function capture(AtlasEngineeringBenchmarkCase $case, AtlasTask $task, array $runnerOptions): ?array
    {
        $mode = $this->mode($runnerOptions);
        if ($mode === 'off') {
            return null;
        }

        $workspace = $this->workspace($runnerOptions, $mode);
        $model = $this->model($runnerOptions);
        $timeout = max(1, min(3600, (int) ($runnerOptions['claude_code_baseline_timeout'] ?? $runnerOptions['baseline_timeout_seconds'] ?? 900)));
        $binary = $this->binary($runnerOptions);
        $prompt = $this->prompt($case, $task);
        $command = $this->command($binary, $model, $runnerOptions);
        $replayPacket = $this->replayPacket($case, $mode, $workspace, $model, $command, $prompt, $timeout, $runnerOptions);
        $fingerprint = $this->cliInvocationFingerprint($command, $prompt, $timeout, $workspace, null, [
            'provider' => 'claude_code_cli',
            'model' => $model,
            'baseline_mode' => $mode,
            'workspace_hash' => hash('sha256', $workspace),
            'case_id' => $case->id,
            'case_code' => $case->case_code,
        ]);

        $base = [
            'schema_version' => 1,
            'enabled' => true,
            'mode' => $mode,
            'provider' => 'claude_code_cli',
            'model' => $model,
            'provider_lock' => 'claude_code_cli',
            'model_lock' => 'opus',
            'case_id' => $case->id,
            'case_code' => $case->case_code,
            'workspace_hash' => hash('sha256', $workspace),
            'prompt_hash' => hash('sha256', $prompt),
            'prompt_chars' => strlen($prompt),
            'task_contract_hash' => hash('sha256', json_encode($case->task_contract_json ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'command' => AtlasSecurity::redactCommand($command),
            'command_display' => AtlasSecurity::commandLineForDisplay($command),
            'invocation_fingerprint' => $fingerprint,
            'replay_packet' => $replayPacket,
            'recorded_at' => now()->toJSON(),
        ];

        if ($mode === 'plan') {
            return $base + [
                'status' => 'planned',
                'executed' => false,
                'limitations' => [
                    'Baseline was planned but not executed. Use claude_code_baseline=run to invoke Claude Code CLI directly.',
                ],
            ];
        }

        $result = $this->runProcess($command, $prompt, $timeout, $workspace);
        $gate = $this->validationGate($runnerOptions, $workspace);
        $deterministicGatesPassed = $result->ok && (string) ($gate['status'] ?? '') === 'passed';
        $blockingReasons = [];
        if (! $result->ok) {
            $blockingReasons[] = $result->errorCode ?: 'baseline_provider_failed';
        }
        if ((string) ($gate['status'] ?? '') !== 'passed') {
            $blockingReasons[] = 'baseline_deterministic_gate_not_passed';
        }

        return $base + [
            'status' => $result->ok ? 'completed' : 'failed',
            'executed' => true,
            'ok' => $result->ok,
            'decision' => $deterministicGatesPassed ? 'resolved' : 'unresolved',
            'score' => $deterministicGatesPassed ? 100 : 0,
            'deterministic_gate' => $gate,
            'deterministic_gates_passed' => $deterministicGatesPassed,
            'pass_without_human' => $deterministicGatesPassed,
            'human_intervention_count' => 0,
            'blocking_reasons' => array_values(array_unique(array_filter($blockingReasons))),
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'output_hash' => hash('sha256', $result->output),
            'output_chars' => strlen($result->output),
            'stdout_hash' => hash('sha256', $result->stdout),
            'stderr_hash' => hash('sha256', $result->stderr),
            'stderr_excerpt' => Str::limit(AtlasSecurity::redactString(trim($result->stderr)), 1000),
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage ? Str::limit($result->errorMessage, 1000) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     * @return array<string,mixed>
     */
    private function validationGate(array $runnerOptions, string $workspace): array
    {
        $command = $runnerOptions['test_command'] ?? null;
        if (! is_string($command) || trim($command) === '') {
            return [
                'status' => 'skipped',
                'reason' => 'test_command_missing',
                'deterministic' => false,
                'required' => true,
            ];
        }

        $command = trim($command);
        $timeout = max(1, min(1800, (int) ($runnerOptions['claude_code_baseline_validation_timeout'] ?? $runnerOptions['baseline_validation_timeout_seconds'] ?? 300)));
        $started = hrtime(true);
        $process = Process::fromShellCommandline($command, $workspace, $this->cliProcessEnv());
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            return [
                'status' => 'failed',
                'reason' => 'timeout',
                'deterministic' => true,
                'required' => true,
                'command' => AtlasSecurity::redactString($command),
                'command_hash' => hash('sha256', $command),
                'timeout_seconds' => $timeout,
                'duration_ms' => $durationMs,
                'error_message' => Str::limit(AtlasSecurity::redactString($exception->getMessage()), 1000),
            ];
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $stdout = AtlasSecurity::redactString($process->getOutput());
        $stderr = AtlasSecurity::redactString($process->getErrorOutput());

        return [
            'status' => $process->isSuccessful() ? 'passed' : 'failed',
            'deterministic' => true,
            'required' => true,
            'command' => AtlasSecurity::redactString($command),
            'command_hash' => hash('sha256', $command),
            'exit_code' => $process->getExitCode(),
            'timeout_seconds' => $timeout,
            'duration_ms' => $durationMs,
            'stdout_hash' => hash('sha256', $stdout),
            'stderr_hash' => hash('sha256', $stderr),
            'stdout_excerpt' => Str::limit(trim($stdout), 1000),
            'stderr_excerpt' => Str::limit(trim($stderr), 1000),
        ];
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function mode(array $runnerOptions): string
    {
        $raw = $runnerOptions['claude_code_baseline'] ?? $runnerOptions['baseline_runner'] ?? $runnerOptions['claude_code_baseline_mode'] ?? 'off';
        if ($raw === true) {
            return 'plan';
        }
        if ($raw === false || $raw === null) {
            return 'off';
        }

        $mode = strtolower(trim((string) $raw));
        $mode = match ($mode) {
            '1', 'true', 'yes', 'on', 'enabled' => 'plan',
            default => $mode,
        };

        if (! in_array($mode, ['off', 'plan', 'run'], true)) {
            throw new InvalidArgumentException('claude_code_baseline must be off, plan or run.');
        }

        return $mode;
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function workspace(array $runnerOptions, string $mode): string
    {
        $atlasWorkspace = $this->resolveWorkspace($runnerOptions['workspace'] ?? null);
        $workspace = $mode === 'run'
            ? ($runnerOptions['claude_code_baseline_workspace'] ?? null)
            : ($runnerOptions['claude_code_baseline_workspace'] ?? $runnerOptions['workspace'] ?? null);
        if (! is_string($workspace) || trim($workspace) === '') {
            throw new InvalidArgumentException($mode === 'run'
                ? 'Claude Code baseline run requires claude_code_baseline_workspace to avoid contaminating the Atlas arm.'
                : 'Claude Code baseline requires workspace.');
        }

        $baselineWorkspace = $this->resolveWorkspace($workspace);
        if ($mode === 'run' && $atlasWorkspace !== null && $baselineWorkspace === $atlasWorkspace) {
            throw new InvalidArgumentException('fair_mode_violation: Claude Code baseline run requires a workspace isolated from the Atlas arm.');
        }

        return $baselineWorkspace ?: trim($workspace);
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function model(array $runnerOptions): string
    {
        $raw = trim((string) ($runnerOptions['claude_code_baseline_model'] ?? $runnerOptions['baseline_model'] ?? 'opus'));
        $provider = (array) config('atlas.ai.providers.claude_cli', []);
        $model = $raw === '' || strtolower($raw) === 'opus'
            ? trim((string) ($provider['premium_model'] ?? 'claude-opus-4-7'))
            : $raw;

        if ($model === '' || ! str_contains(strtolower($model), 'opus')) {
            throw new InvalidArgumentException('fair_mode_violation: Claude Code baseline must use Claude Opus.');
        }

        return $model;
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function binary(array $runnerOptions): string
    {
        $provider = (array) config('atlas.ai.providers.claude_cli', []);
        $binary = trim((string) ($runnerOptions['claude_code_baseline_binary'] ?? $provider['binary'] ?? 'claude'));

        return $binary !== '' ? $binary : 'claude';
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     * @return array<int,string>
     */
    private function command(string $binary, string $model, array $runnerOptions): array
    {
        $command = [$binary, '-p', '--model', $model];
        $permission = strtolower(trim((string) ($runnerOptions['permission'] ?? 'auto')));

        if ($permission === 'danger') {
            $command[] = '--permission-mode';
            $command[] = 'bypassPermissions';
        }

        return $command;
    }

    private function prompt(AtlasEngineeringBenchmarkCase $case, AtlasTask $task): string
    {
        $contract = $case->task_contract_json ?? [];
        $payload = [
            'benchmark_arm' => 'claude_code_cli_baseline',
            'case_code' => $case->case_code,
            'title' => $case->title,
            'description' => $case->description,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'minimum_viable_action' => $task->minimum_viable_action,
                'starter_step' => $task->starter_step,
            ],
            'task_contract' => $contract,
            'instructions' => [
                'Use Claude Code CLI directly as the baseline arm.',
                'Implement the requested task in the current workspace.',
                'Do not rely on Atlas harness repair capsules, Atlas Decide, council, Codex, Gemini or provider fallback.',
                'Prefer the smallest correct code change and leave deterministic validation commands runnable by the operator.',
            ],
        ];

        return "Claude Code baseline benchmark task:\n"
            .json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<int,string>  $command
     * @param  array<string,mixed>  $runnerOptions
     * @return array<string,mixed>
     */
    private function replayPacket(
        AtlasEngineeringBenchmarkCase $case,
        string $mode,
        string $workspace,
        string $model,
        array $command,
        string $prompt,
        int $timeout,
        array $runnerOptions,
    ): array {
        $testCommand = isset($runnerOptions['test_command']) && is_string($runnerOptions['test_command'])
            ? trim($runnerOptions['test_command'])
            : '';
        $validationTimeout = max(1, min(1800, (int) ($runnerOptions['claude_code_baseline_validation_timeout'] ?? $runnerOptions['baseline_validation_timeout_seconds'] ?? 300)));

        return [
            'schema_version' => 1,
            'kind' => 'claude_code_baseline_replay',
            'mode' => $mode,
            'provider_lock' => 'claude_code_cli',
            'model_lock' => 'opus',
            'model' => $model,
            'case_id' => $case->id,
            'case_code' => $case->case_code,
            'workspace_hash' => hash('sha256', $workspace),
            'input' => [
                'transport' => 'stdin',
                'prompt_hash' => hash('sha256', $prompt),
                'prompt_chars' => strlen($prompt),
                'task_contract_hash' => hash('sha256', json_encode($case->task_contract_json ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ],
            'invocation' => [
                'command' => AtlasSecurity::redactCommand($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
                'command_hash' => hash('sha256', implode("\0", $command)),
                'timeout_seconds' => $timeout,
            ],
            'deterministic_gate' => [
                'required' => true,
                'command_hash' => $testCommand !== '' ? hash('sha256', $testCommand) : null,
                'command_present' => $testCommand !== '',
                'timeout_seconds' => $validationTimeout,
                'pass_without_human_requires_gate_pass' => true,
            ],
            'fairness_constraints' => [
                'single_provider' => true,
                'allowed_providers' => ['claude_code_cli'],
                'fallback_disabled' => true,
                'atlas_decide_disabled' => true,
                'council_disabled' => true,
                'same_model_family_required' => 'opus',
            ],
            'created_at' => now()->toJSON(),
        ];
    }

    private function resolveWorkspace(mixed $workspace): ?string
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : trim($workspace);
    }
}
