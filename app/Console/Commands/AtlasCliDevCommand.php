<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AtlasCliDevCommand extends Command
{
    protected $signature = 'atlas:cli:dev
        {task?* : Development task}
        {--workspace= : Workspace path. Defaults to current directory}
        {--provider= : Force claude_cli, codex_cli or claude_codex}
        {--critical : Prefer council/dual review when available}
        {--permission=write : read, write or danger}
        {--allow-write : Confirm scoped workspace writes for this run}
        {--auto-test : Run tests in final quality gate}
        {--skill=* : Activate one or more agentskills bundle names}
        {--plan-only : Run preflight and print execution plan without calling provider}
        {--complete : Keep running repair iterations until gates pass or max iterations is reached}
        {--max-iterations=3 : Maximum repair iterations for --complete}
        {--resume= : Resume a previous dev execution plan id when present in traces}
        {--force-offline-provider : Call provider even when health says all providers are offline}
        {--no-run : Enqueue only; do not run local worker inline}
        {--no-stream : Disable provider streaming}
        {--timeout=900 : Provider timeout}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the native Atlas CLI dev workflow with preflight, provider strategy and completion gate.';

    public function handle(AtlasCliDevWorkflowService $workflow, AtlasCliQualityService $quality): int
    {
        $workspace = $this->workspace();
        $task = trim(implode(' ', (array) $this->argument('task')));

        if ($task === '') {
            return $this->runInteractiveDev($workspace);
        }

        $provider = $this->provider();
        $preflight = $workflow->preflight($workspace, $task, $provider, (bool) $this->option('critical'));
        $json = (bool) $this->option('json');
        $planOnly = (bool) $this->option('plan-only');
        $complete = (bool) $this->option('complete');
        $maxIterations = $this->maxIterations();
        $skills = $workflow->qualityGateSkills($this->skillOptions(), $complete, $maxIterations);
        $devPlan = $workflow->executionPlan(
            workspace: $workspace,
            task: $task,
            provider: (string) $preflight['selected_provider'],
            maxIterations: $maxIterations,
            mode: $complete ? 'multi_step' : 'single_shot',
        );
        $devPlan['quality_gate_policy'] = $workflow->qualityGatePolicy($complete, $maxIterations, $skills);
        if (is_string($this->option('resume')) && $this->option('resume') !== '') {
            $devPlan['plan_id'] = (string) $this->option('resume');
            $devPlan['resumed_at'] = now()->toJSON();
        }

        if ($planOnly) {
            $this->printPayload([
                'ok' => true,
                'phase' => 'preflight',
                'workflow' => $preflight,
                'dev_execution_plan' => $devPlan,
                'activated_skills' => $skills,
                'chat_command' => $workflow->chatCommand(
                    task: $task,
                    workspace: $workspace,
                    provider: (string) $preflight['selected_provider'],
                    permission: $this->permission(),
                    allowWrite: (bool) $this->option('allow-write') || $this->permission() === 'write',
                    autoTest: (bool) $this->option('auto-test'),
                    timeout: (int) $this->option('timeout'),
                    stream: ! (bool) $this->option('no-stream') && ! $json,
                    noRun: (bool) $this->option('no-run'),
                    devExecutionPlan: $devPlan,
                    skills: $skills,
                    json: $json,
                ),
            ]);

            return self::SUCCESS;
        }

        if (! $json) {
            $this->renderPreflight($preflight);
        }

        if ((bool) $preflight['requires_override'] && ! (bool) $this->option('force-offline-provider')) {
            if ($json) {
                $this->line(json_encode([
                    'ok' => false,
                    'phase' => 'preflight',
                    'workflow' => $preflight,
                    'error' => 'provider_offline',
                    'message' => 'Nenhum provider online. Rode atlas bootstrap --refresh-providers.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->error('Nenhum provider online. Rode atlas bootstrap --refresh-providers ou use --force-offline-provider se quiser tentar mesmo assim.');

            return self::FAILURE;
        }

        $devPlan = $workflow->markStep($devPlan, 'inspect', 'done', [
            'tool' => 'atlas:cli:dev.preflight',
            'output' => 'Preflight completed.',
        ]);
        $devPlan = $workflow->markStep($devPlan, 'plan', 'done', [
            'output' => (string) data_get($preflight, 'provider_strategy.reason'),
        ]);

        $runs = [];
        $completion = null;
        $iteration = 0;
        $previousStatus = null;

        do {
            $iteration++;
            $phase = $iteration === 1 ? 'edit' : 'repair';
            $prompt = $iteration === 1
                ? $task
                : $this->repairPrompt($task, (array) $completion, $iteration, $maxIterations);

            $devPlan = $workflow->markStep($devPlan, $phase, 'running', [
                'iteration' => $iteration,
                'tool' => 'atlas:ai:chat',
            ]);

            $command = $workflow->chatCommand(
                task: $prompt,
                workspace: $workspace,
                provider: (string) $preflight['selected_provider'],
                permission: $this->permission(),
                allowWrite: (bool) $this->option('allow-write') || $this->permission() === 'write',
                autoTest: false,
                timeout: (int) $this->option('timeout'),
                stream: ! (bool) $this->option('no-stream') && ! $json,
                noRun: (bool) $this->option('no-run'),
                devExecutionPlan: $devPlan,
                skills: $skills,
                json: $json,
            );

            $run = $this->runProviderCommand($command, $workspace, passthrough: ! $json);
            $traceId = $this->extractTraceId($run['stdout']);
            $runs[] = $run + ['trace_id' => $traceId, 'iteration' => $iteration];
            $devPlan = $workflow->markStep($devPlan, $phase, ((int) $run['exit_code'] === 0) ? 'done' : 'failed', [
                'iteration' => $iteration,
                'trace_id' => $traceId,
                'error' => $run['stderr'] ?: null,
            ]);

            $completion = $quality->evaluate(
                workspace: $workspace,
                runTests: (bool) $this->option('auto-test') || (bool) $this->option('complete'),
                approved: true,
                traceId: $traceId,
            );
            $devPlan = $workflow->markStep($devPlan, 'test', $completion['status'] === 'failed' ? 'failed' : 'done', [
                'iteration' => $iteration,
                'tool' => 'atlas:cli:quality',
                'quality_status' => $completion['status'],
                'files' => $completion['changed_files'] ?? [],
            ]);
            $workflow->persistPlan($traceId, $devPlan);

            $status = (string) $completion['status'];
            $shouldRepair = (bool) $this->option('complete')
                && ! (bool) $this->option('no-run')
                && $status !== 'passed'
                && $iteration < $maxIterations;

            if ($shouldRepair && $previousStatus !== null && $this->statusRank($status) < $this->statusRank($previousStatus)) {
                $devPlan = $workflow->markStep($devPlan, 'repair', 'failed', [
                    'iteration' => $iteration,
                    'reason_if_stopped' => 'quality_gate_worsened',
                ]);
                $shouldRepair = false;
            }

            $previousStatus = $status;
        } while ($shouldRepair);

        $finalStatus = (string) ($completion['status'] ?? 'failed');
        $devPlan = $workflow->markStep($devPlan, 'review', $finalStatus === 'failed' ? 'failed' : 'done', [
            'quality_status' => $finalStatus,
        ]);
        $devPlan = $workflow->markStep($devPlan, 'finish', $finalStatus === 'failed' ? 'failed' : 'done', [
            'reason_if_stopped' => $finalStatus === 'failed' ? 'max_iterations_or_quality_failed' : null,
        ]);

        if (($lastTraceId = data_get(last($runs) ?: [], 'trace_id')) && is_string($lastTraceId)) {
            $workflow->persistPlan($lastTraceId, $devPlan);
        }

        $providerOk = collect($runs)->every(fn (array $run): bool => (int) $run['exit_code'] === 0);
        $qualityOk = $complete
            ? $finalStatus === 'passed'
            : $finalStatus !== 'failed';
        $ok = $providerOk && $qualityOk;

        if ($json) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => $ok,
                'phase' => 'complete',
                'workflow' => $preflight,
                'dev_execution_plan' => $devPlan,
                'activated_skills' => $skills,
                'provider_runs' => $runs,
                'completion' => $completion,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderCompletion($completion);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function runInteractiveDev(string $workspace): int
    {
        $command = [
            PHP_BINARY,
            'artisan',
            'atlas:ai:chat',
            '--dev',
            '--workspace='.$workspace,
            '--permission='.$this->permission(),
            '--allow-write',
            '--stream',
        ];

        foreach ($this->skillOptions() as $skill) {
            $command[] = '--skill='.$skill;
        }

        return (int) $this->runProviderCommand($command, $workspace)['exit_code'];
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int, stdout:string, stderr:string}
     */
    private function runProviderCommand(array $command, string $workspace, bool $passthrough = true): array
    {
        $process = new Process($command, $workspace, AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(max(30, (int) $this->option('timeout')) + 60);
        $stdout = '';
        $stderr = '';

        $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr, $passthrough): void {
            $buffer = AtlasSecurity::redactString($buffer);
            if ($type === Process::ERR) {
                $stderr .= $buffer;
            } else {
                $stdout .= $buffer;
            }

            if ($passthrough) {
                $this->output->write($buffer);
            }
        });

        return [
            'exit_code' => $process->getExitCode() ?? self::FAILURE,
            'stdout' => $stdout ?: AtlasSecurity::redactString($process->getOutput()),
            'stderr' => $stderr ?: AtlasSecurity::redactString($process->getErrorOutput()),
            'command' => AtlasSecurity::redactCommand($command),
            'command_display' => AtlasSecurity::commandLineForDisplay($command),
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     */
    private function renderPreflight(array $preflight): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Dev Workflow</>', 'preflight');
        $this->components->twoColumnDetail('Workspace', (string) $preflight['workspace']);
        $this->components->twoColumnDetail('Provider', (string) $preflight['selected_provider']);
        $this->components->twoColumnDetail('Provider online', ((bool) data_get($preflight, 'provider_strategy.has_online_provider')) ? 'yes' : 'no');
        $this->line((string) data_get($preflight, 'provider_strategy.reason'));
        $this->line('Preflight quality: '.data_get($preflight, 'preflight_quality.status'));
    }

    /**
     * @param  array<string,mixed>  $completion
     */
    private function renderCompletion(array $completion): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Dev Completion</>', (string) $completion['status']);
        $this->line((string) data_get($completion, 'completion_packet.summary'));

        $risks = (array) data_get($completion, 'completion_packet.risks', []);
        if ($risks !== []) {
            $this->line('Riscos:');
            foreach ($risks as $risk) {
                $this->line('  - '.$risk);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload): void
    {
        $payload = AtlasSecurity::redactArray($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->renderPreflight((array) $payload['workflow']);
        $this->line('Comando de execucao: '.AtlasSecurity::commandLineForDisplay((array) $payload['chat_command']));
    }

    private function provider(): ?string
    {
        $provider = $this->option('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function permission(): string
    {
        $permission = (string) $this->option('permission');

        return in_array($permission, ['read', 'write', 'danger'], true) ? $permission : 'write';
    }

    private function maxIterations(): int
    {
        return max(1, min(10, (int) $this->option('max-iterations')));
    }

    /**
     * @return array<int,string>
     */
    private function skillOptions(): array
    {
        return collect((array) $this->option('skill'))
            ->flatMap(fn (mixed $value): array => is_string($value) ? preg_split('/\s*,\s*/', $value) ?: [] : [])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (mixed $name): string => strtolower(trim((string) $name)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $completion
     */
    private function repairPrompt(string $task, array $completion, int $iteration, int $maxIterations): string
    {
        return implode("\n\n", [
            "Corrija a tarefa anterior do Atlas CLI. Iteracao de reparo {$iteration}/{$maxIterations}.",
            "Objetivo original: {$task}",
            'Quality gate atual:',
            json_encode([
                'status' => $completion['status'] ?? null,
                'changed_files' => $completion['changed_files'] ?? [],
                'tests' => data_get($completion, 'completion_packet.tests', []),
                'risks' => data_get($completion, 'completion_packet.risks', []),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Aplique a menor correcao que faca os gates passarem. Nao reescreva areas nao relacionadas.',
        ]);
    }

    private function extractTraceId(string $stdout): ?string
    {
        $decoded = json_decode($stdout, true);
        if (is_array($decoded) && is_string($decoded['trace_id'] ?? null)) {
            return $decoded['trace_id'];
        }

        if (preg_match('/trace:\\s*([0-9a-fA-F-]{36})/', $stdout, $matches)) {
            return $matches[1];
        }

        if (preg_match('/"trace_id"\\s*:\\s*"([^"]+)"/', $stdout, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'passed' => 3,
            'needs_review' => 2,
            'failed' => 1,
            default => 0,
        };
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
