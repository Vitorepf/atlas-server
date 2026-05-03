<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\Cli\AtlasCliModelCatalogService;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Cli\AtlasTerminalNotifier;
use App\Services\Ai\Cli\AtlasTerminalTheme;
use App\Services\Ai\Cli\DevProgressReporter;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringTaskContractService;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AtlasCliDevCommand extends Command
{
    protected $signature = 'atlas:cli:dev
        {task?* : Development task}
        {--task-id= : Load an Atlas task and attach its engineering contract}
        {--workspace= : Workspace path. Defaults to current directory}
        {--provider= : Force claude_cli, codex_cli or claude_codex}
        {--model= : Force model alias/id for the selected provider, for example sonnet, opus, spark, codex-premium, claude-opus-4-7 or gpt-5.5}
        {--critical : Prefer council/dual review when available}
        {--permission=auto : auto, read, write or danger}
        {--allow-write : Confirm scoped workspace writes for this run}
        {--operator : Full local operator mode for trusted Mac workspaces}
        {--allow-unsandboxed : Allow write/danger with providers Atlas cannot sandbox directly}
        {--dangerously-allow-all : Confirm danger-full-access for this run}
        {--auto-test : Run tests in final quality gate}
        {--skill=* : Activate one or more agentskills bundle names}
        {--plan-only : Run preflight and print execution plan without calling provider}
        {--complete : Keep running repair iterations until gates pass or max iterations is reached}
        {--max-iterations=3 : Maximum repair iterations for --complete}
        {--resume= : Resume a previous dev execution plan id when present in traces}
        {--force-offline-provider : Call provider even when health says all providers are offline}
        {--no-run : Enqueue only; do not run local worker inline}
        {--no-stream : Disable provider streaming}
        {--no-progress : Disable phase ribbon and stream provider output verbatim}
        {--no-notify : Suppress local notification when the run completes}
        {--image=* : Attach image file(s) to the dev prompt}
        {--clipboard-image : Attach the current macOS clipboard image to the next prompt}
        {--no-auto-image : Do not auto-attach clipboard images when the prompt mentions screenshots/images}
        {--timeout=900 : Provider timeout}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the native Atlas CLI dev workflow with preflight, provider strategy and completion gate.';

    public function handle(
        AtlasCliDevWorkflowService $workflow,
        AtlasCliModelCatalogService $models,
        AtlasCliQualityService $quality,
        AtlasTerminalNotifier $notifier,
        EngineeringTaskContractService $contracts,
        EngineeringBlueprintService $blueprints,
        EngineeringBlueprintSnapshotService $blueprintSnapshots,
        EngineeringRunArtifactService $artifacts,
        AtlasAiRuntimeSettings $settings,
    ): int {
        $workspace = $this->workspace();
        $json = (bool) $this->option('json');
        $task = trim(implode(' ', (array) $this->argument('task')));
        $taskId = $this->taskId();
        $atlasTask = null;
        $engineeringContract = null;
        $engineeringBlueprint = null;
        $engineeringBlueprintSnapshot = null;

        if ($taskId !== null) {
            $atlasTask = AtlasTask::query()
                ->with(['project', 'projectStep'])
                ->find($taskId);

            if (! $atlasTask) {
                return $this->taskNotFound($taskId, $json);
            }

            $engineeringContract = $contracts->forTask($atlasTask);
            $engineeringBlueprint = $blueprints->forTask($atlasTask, $engineeringContract);
            if ($task === '') {
                $task = $contracts->defaultPrompt($atlasTask, $engineeringContract);
            }
        }

        if ($task === '') {
            return $this->runInteractiveDev($workspace);
        }

        $explicitProvider = $this->provider();
        $provider = $explicitProvider;
        $modelSelection = $models->select($this->modelOption(), $provider);
        if ($modelSelection !== null && $provider === null && is_string($modelSelection['provider'] ?? null)) {
            $provider = $modelSelection['provider'];
        }
        if ($modelSelection !== null && ! $models->matchesProvider($modelSelection, $provider)) {
            return $this->modelProviderMismatch($models->label($modelSelection), $provider, $json);
        }
        if (($explicitProvider !== null || $modelSelection !== null) && ! $this->manualProviderAllowed($provider, $settings)) {
            return $this->manualProviderBlocked((string) $provider, $json);
        }
        $modelOverride = is_string($modelSelection['model'] ?? null) ? trim((string) $modelSelection['model']) : null;
        $modelOverride = $modelOverride !== '' ? $modelOverride : null;
        $preflight = $workflow->preflight($workspace, $task, $provider, (bool) $this->option('critical'));
        if ($modelSelection !== null) {
            $preflight['selected_model'] = $this->compactModelSelection($modelSelection);
        }
        $planOnly = (bool) $this->option('plan-only');
        $complete = (bool) $this->option('complete');
        $maxIterations = $this->maxIterations();
        $skills = $workflow->qualityGateSkills($this->skillOptions(), $complete, $maxIterations);
        if ($engineeringContract !== null) {
            $skills = $workflow->engineeringContractSkills($skills);
        }
        $devPlan = $workflow->executionPlan(
            workspace: $workspace,
            task: $task,
            provider: (string) $preflight['selected_provider'],
            maxIterations: $maxIterations,
            mode: $complete ? 'multi_step' : 'single_shot',
        );
        $devPlan['quality_gate_policy'] = $workflow->qualityGatePolicy($complete, $maxIterations, $skills);
        if ($modelSelection !== null) {
            $devPlan['selected_model'] = $this->compactModelSelection($modelSelection);
        }
        if ($atlasTask instanceof AtlasTask && $engineeringContract !== null) {
            $devPlan['atlas_task'] = $contracts->taskSummary($atlasTask);
            $devPlan['engineering_contract'] = $engineeringContract;
            $devPlan['engineering_blueprint'] = $engineeringBlueprint;
            $engineeringBlueprintSnapshot = $blueprintSnapshots->currentForTask($atlasTask, $engineeringContract, (array) $engineeringBlueprint);
            if ($engineeringBlueprintSnapshot !== null) {
                $devPlan['engineering_blueprint_snapshot'] = $engineeringBlueprintSnapshot;
            }
        }
        $devPlan['operator_options'] = [
            'task' => $task,
            'task_id' => $taskId,
            'provider' => $provider,
            'model' => $modelOverride,
            'model_label' => $modelSelection['label'] ?? null,
            'model_tier' => $modelSelection['tier'] ?? null,
            'model_source' => $modelSelection['source'] ?? null,
            'critical' => (bool) $this->option('critical'),
            'permission' => $this->permission(),
            'allow_write' => $this->allowWrite(),
            'allow_danger' => $this->allowDanger(),
            'allow_unsandboxed' => $this->allowUnsandboxed(),
            'auto_test' => (bool) $this->option('auto-test'),
            'complete' => $complete,
            'max_iterations' => $maxIterations,
            'no_stream' => (bool) $this->option('no-stream'),
            'skills' => $skills,
            'image_count' => count((array) $this->option('image')) + ((bool) $this->option('clipboard-image') ? 1 : 0),
            'auto_image' => ! (bool) $this->option('no-auto-image'),
        ];
        if (is_string($this->option('resume')) && $this->option('resume') !== '') {
            $devPlan['plan_id'] = (string) $this->option('resume');
            $devPlan['resumed_at'] = now()->toJSON();
        }
        $providerPrompt = $workflow->promptWithEngineeringContract($task, $engineeringContract, $engineeringBlueprint);

        if ($planOnly) {
            $this->printPayload([
                'ok' => true,
                'phase' => 'preflight',
                'workflow' => $preflight,
                'dev_execution_plan' => $devPlan,
                'activated_skills' => $skills,
                'chat_command' => $workflow->chatCommand(
                    task: $providerPrompt,
                    workspace: $workspace,
                    provider: (string) $preflight['selected_provider'],
                    model: $modelOverride,
                    permission: $this->permission(),
                    allowWrite: $this->allowWrite(),
                    allowDanger: $this->allowDanger(),
                    allowUnsandboxed: $this->allowUnsandboxed(),
                    autoTest: (bool) $this->option('auto-test'),
                    timeout: (int) $this->option('timeout'),
                    stream: ! (bool) $this->option('no-stream') && ! $json,
                    noRun: (bool) $this->option('no-run'),
                    devExecutionPlan: $devPlan,
                    skills: $skills,
                    json: $json,
                    imagePaths: (array) $this->option('image'),
                    clipboardImage: (bool) $this->option('clipboard-image'),
                    noAutoImage: (bool) $this->option('no-auto-image'),
                ),
            ]);

            return self::SUCCESS;
        }

        if ($atlasTask instanceof AtlasTask && $engineeringContract !== null && is_array($engineeringBlueprint)) {
            $engineeringBlueprintSnapshot = $blueprintSnapshots->freezeForTask($atlasTask, $engineeringContract, $engineeringBlueprint);
            if ($engineeringBlueprintSnapshot !== null) {
                $devPlan['engineering_blueprint_snapshot'] = $engineeringBlueprintSnapshot;
            }
        }

        $progress = $this->shouldShowProgress();
        $reporter = new DevProgressReporter($this->output);
        $startedAt = microtime(true);

        if ($progress) {
            $this->renderHeader($reporter, $workspace, $preflight, $task, $maxIterations, $complete, $modelSelection);
        } elseif (! $json) {
            $this->renderPreflightLegacy($preflight);
        }

        if ((bool) $preflight['requires_override'] && ! (bool) $this->option('force-offline-provider')) {
            return $this->bailOffline($preflight, $json, $progress, $reporter);
        }

        if ($progress) {
            $reporter->summarize('inspect', 'done', 0, 'preflight ok');
        }

        $devPlan = $workflow->markStep($devPlan, 'inspect', 'done', [
            'tool' => 'atlas:cli:dev.preflight',
            'output' => 'Preflight completed.',
        ]);

        if ($progress) {
            $reporter->summarize('plan', 'done', 0, (string) data_get($preflight, 'provider_strategy.recommended_provider'));
        }

        $devPlan = $workflow->markStep($devPlan, 'plan', 'done', [
            'output' => (string) data_get($preflight, 'provider_strategy.reason'),
        ]);

        $runs = [];
        $completion = null;
        $iteration = 0;
        $previousStatus = null;
        $passthrough = ! $progress && ! $json;

        do {
            $iteration++;
            $phase = $iteration === 1 ? 'edit' : 'repair';
            $prompt = $iteration === 1
                ? $providerPrompt
                : $this->repairPrompt($providerPrompt, (array) $completion, $iteration, $maxIterations);

            $devPlan = $workflow->markStep($devPlan, $phase, 'running', [
                'iteration' => $iteration,
                'tool' => 'atlas:ai:chat',
                'model' => $modelOverride,
            ]);

            $command = $workflow->chatCommand(
                task: $prompt,
                workspace: $workspace,
                provider: (string) $preflight['selected_provider'],
                model: $modelOverride,
                permission: $this->permission(),
                allowWrite: $this->allowWrite(),
                allowDanger: $this->allowDanger(),
                allowUnsandboxed: $this->allowUnsandboxed(),
                autoTest: false,
                timeout: (int) $this->option('timeout'),
                stream: ! (bool) $this->option('no-stream') && ! $json && ! $progress,
                noRun: (bool) $this->option('no-run'),
                devExecutionPlan: $devPlan,
                skills: $skills,
                json: $json,
                imagePaths: (array) $this->option('image'),
                clipboardImage: (bool) $this->option('clipboard-image'),
                noAutoImage: (bool) $this->option('no-auto-image'),
            );

            if ($progress) {
                $reporter->start($phase);
            }

            $run = $this->runProviderCommand($command, $workspace, passthrough: $passthrough);
            $traceId = $this->extractTraceId($run['stdout']);
            $runs[] = $run + [
                'trace_id' => $traceId,
                'iteration' => $iteration,
                'model' => $modelOverride,
                'model_label' => $modelSelection['label'] ?? null,
                'model_tier' => $modelSelection['tier'] ?? null,
                'model_source' => $modelSelection['source'] ?? null,
            ];

            $iterationCompletion = $quality->evaluate(
                workspace: $workspace,
                runTests: false,
                approved: true,
                traceId: $traceId,
            );
            $changedFiles = (array) ($iterationCompletion['changed_files'] ?? []);

            if ($progress) {
                $editNote = (int) $run['exit_code'] === 0
                    ? $this->filesNote($changedFiles)
                    : 'provider exit '.(int) $run['exit_code'];
                if ((int) $run['exit_code'] === 0) {
                    $reporter->done($phase, $editNote);
                } else {
                    $reporter->fail($phase, $editNote);
                }
            }

            $devPlan = $workflow->markStep($devPlan, $phase, ((int) $run['exit_code'] === 0) ? 'done' : 'failed', [
                'iteration' => $iteration,
                'trace_id' => $traceId,
                'error' => $run['stderr'] ?: null,
                'files' => $changedFiles,
            ]);

            $shouldRunTests = (bool) $this->option('auto-test') || $complete;

            if ($progress) {
                $reporter->start('test');
            }

            $completion = $quality->evaluate(
                workspace: $workspace,
                runTests: $shouldRunTests,
                approved: true,
                traceId: $traceId,
            );

            $testNote = $this->testsNote($completion, $shouldRunTests);
            $testStatus = (string) $completion['status'];

            if ($progress) {
                if ($testStatus === 'failed') {
                    $reporter->fail('test', $testNote);
                } else {
                    $reporter->done('test', $testNote);
                }
            }

            $devPlan = $workflow->markStep($devPlan, 'test', $testStatus === 'failed' ? 'failed' : 'done', [
                'iteration' => $iteration,
                'tool' => 'atlas:cli:quality',
                'quality_status' => $testStatus,
                'files' => $completion['changed_files'] ?? [],
            ]);
            $workflow->persistPlan($traceId, $devPlan);

            $shouldRepair = $complete
                && ! (bool) $this->option('no-run')
                && $testStatus !== 'passed'
                && $iteration < $maxIterations;

            if ($shouldRepair && $previousStatus !== null && $this->statusRank($testStatus) < $this->statusRank($previousStatus)) {
                $devPlan = $workflow->markStep($devPlan, 'repair', 'failed', [
                    'iteration' => $iteration,
                    'reason_if_stopped' => 'quality_gate_worsened',
                ]);
                $shouldRepair = false;
            }

            $previousStatus = $testStatus;
        } while ($shouldRepair);

        $finalStatus = (string) ($completion['status'] ?? 'failed');

        if ($progress) {
            $reporter->summarize('review', $finalStatus === 'failed' ? 'failed' : 'done', 0, $finalStatus);
        }

        $devPlan = $workflow->markStep($devPlan, 'review', $finalStatus === 'failed' ? 'failed' : 'done', [
            'quality_status' => $finalStatus,
        ]);

        if ($progress) {
            $reporter->summarize('finish', $finalStatus === 'failed' ? 'failed' : 'done', $reporter->totalDurationMs());
        }

        $devPlan = $workflow->markStep($devPlan, 'finish', $finalStatus === 'failed' ? 'failed' : 'done', [
            'reason_if_stopped' => $finalStatus === 'failed' ? 'max_iterations_or_quality_failed' : null,
        ]);

        $lastTraceId = data_get(last($runs) ?: [], 'trace_id');
        if (is_string($lastTraceId) && $lastTraceId !== '') {
            $workflow->persistPlan($lastTraceId, $devPlan);
        }

        $engineeringArtifact = null;
        if ($atlasTask instanceof AtlasTask && $engineeringContract !== null && is_array($engineeringBlueprint)) {
            $engineeringArtifact = $artifacts->completionArtifact(
                task: $atlasTask->refresh(),
                contract: $engineeringContract,
                blueprint: $engineeringBlueprint,
                devPlan: $devPlan,
                completion: $completion,
                runs: $runs,
            );
            $devPlan['engineering_artifact_summary'] = $artifacts->summary($engineeringArtifact);
            $artifacts->persistTaskRun($atlasTask->refresh(), $engineeringArtifact);
            $artifacts->persistTraceArtifact(is_string($lastTraceId) ? $lastTraceId : null, $engineeringArtifact);

            if (is_string($lastTraceId) && $lastTraceId !== '') {
                $workflow->persistPlan($lastTraceId, $devPlan);
            }
        }

        $providerOk = collect($runs)->every(fn (array $run): bool => (int) $run['exit_code'] === 0);
        $qualityOk = $complete ? $finalStatus === 'passed' : $finalStatus !== 'failed';
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
                'engineering_artifact' => $engineeringArtifact,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($progress) {
            $this->renderRichCompletion($completion, $devPlan, $task, $ok, is_string($lastTraceId) ? $lastTraceId : null);
        } else {
            $this->renderCompletionLegacy($completion);
        }

        if (! (bool) $this->option('no-notify')) {
            $totalMs = (int) ((microtime(true) - $startedAt) * 1000);
            $title = 'atlas dev · '.($ok ? 'concluido' : 'precisa atencao');
            $body = $this->notificationBody($task, $completion, $finalStatus);
            $notifier->notify($title, $body, $totalMs);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $completion
     */
    private function notificationBody(string $task, ?array $completion, string $finalStatus): string
    {
        $changedFiles = (array) data_get($completion ?? [], 'completion_packet.files_changed', []);
        $tests = (array) data_get($completion ?? [], 'completion_packet.tests', []);
        $parts = [Str::limit($task, 60)];
        if ($changedFiles !== []) {
            $parts[] = count($changedFiles).' '.(count($changedFiles) === 1 ? 'arquivo' : 'arquivos');
        }
        if ($tests !== []) {
            $first = (array) $tests[0];
            $parts[] = ((bool) ($first['ok'] ?? false)) ? 'testes ok' : 'testes falharam';
        }
        $parts[] = 'status '.$finalStatus;

        return implode(' · ', array_filter($parts));
    }

    private function shouldShowProgress(): bool
    {
        if ((bool) $this->option('no-progress')) {
            return false;
        }
        if ((bool) $this->option('json')) {
            return false;
        }
        if ($this->output->isVerbose()) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $preflight
     */
    private function renderHeader(
        DevProgressReporter $reporter,
        string $workspace,
        array $preflight,
        string $task,
        int $maxIterations,
        bool $complete,
        ?array $modelSelection = null,
    ): void {
        $reporter->blank();
        $reporter->note('workspace', $workspace);
        $reporter->note('provider', (string) $preflight['selected_provider']);
        if ($modelSelection !== null) {
            $reporter->note('modelo', $this->modelSelectionNote($modelSelection));
        }
        $online = (bool) data_get($preflight, 'provider_strategy.has_online_provider');
        $reporter->note('online', $online ? 'sim' : 'nao');
        $reporter->note('tarefa', Str::limit($task, 80));
        if ($complete) {
            $reporter->note('iteracoes', 'ate '.$maxIterations);
        }
        $reporter->blank();
    }

    /**
     * @param  array<string,mixed>  $preflight
     */
    private function renderPreflightLegacy(array $preflight): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Dev Workflow</>', 'preflight');
        $this->components->twoColumnDetail('Workspace', (string) $preflight['workspace']);
        $this->components->twoColumnDetail('Provider', (string) $preflight['selected_provider']);
        if (is_array($preflight['selected_model'] ?? null)) {
            $this->components->twoColumnDetail('Model', $this->modelSelectionNote((array) $preflight['selected_model']));
        }
        $this->components->twoColumnDetail('Provider online', ((bool) data_get($preflight, 'provider_strategy.has_online_provider')) ? 'yes' : 'no');
        $this->line((string) data_get($preflight, 'provider_strategy.reason'));
        $this->line('Preflight quality: '.data_get($preflight, 'preflight_quality.status'));
    }

    /**
     * @param  array<string,mixed>  $completion
     */
    private function renderCompletionLegacy(array $completion): void
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
     * @param  array<string,mixed>  $completion
     * @param  array<string,mixed>  $devPlan
     */
    private function renderRichCompletion(?array $completion, array $devPlan, string $task, bool $ok, ?string $lastTraceId): void
    {
        $completion = is_array($completion) ? $completion : [];
        $summary = (string) data_get($completion, 'completion_packet.summary', '');
        $changedFiles = (array) data_get($completion, 'completion_packet.files_changed', []);
        $tests = (array) data_get($completion, 'completion_packet.tests', []);
        $risks = (array) data_get($completion, 'completion_packet.risks', []);

        $this->newLine();
        $this->writeSection('resumo');
        if ($summary !== '') {
            $this->line('  '.$summary);
        } else {
            $this->line($this->ansi('2', '  '.($ok ? 'tarefa concluida sem alteracoes registradas' : 'sem resumo')));
        }

        $this->newLine();
        $this->writeSection('arquivos alterados ('.count($changedFiles).')');
        if ($changedFiles === []) {
            $this->line($this->ansi('2', '  nenhum'));
        } else {
            foreach (array_slice($changedFiles, 0, 30) as $file) {
                $this->line('  '.(string) $file);
            }
            $extra = count($changedFiles) - 30;
            if ($extra > 0) {
                $this->line($this->ansi('2', '  ... mais '.$extra));
            }
        }

        $this->newLine();
        $this->writeSection('testes');
        $decorated = $this->output->isDecorated();
        if ($tests === []) {
            $this->line($this->ansi('2', '  nao executados (use --auto-test ou --complete)'));
        } else {
            foreach ($tests as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $command = (string) ($entry['command'] ?? '-');
                $okFlag = (bool) ($entry['ok'] ?? false);
                $duration = (int) ($entry['duration_ms'] ?? 0);
                $exit = $entry['exit_code'] ?? null;
                $tag = $okFlag
                    ? AtlasTerminalTheme::ok('ok', $decorated)
                    : AtlasTerminalTheme::error('falhou'.($exit !== null ? ' · exit '.(int) $exit : ''), $decorated);
                $meta = AtlasTerminalTheme::muted('· '.$this->humanDuration($duration), $decorated);
                $this->line('  '.$command.' '.AtlasTerminalTheme::muted('· ', $decorated).$tag.' '.$meta);
            }
        }

        $this->newLine();
        $this->writeSection('riscos');
        if ($risks === []) {
            $this->line($this->ansi('2', '  nenhum'));
        } else {
            foreach ($risks as $risk) {
                $this->line('  '.AtlasTerminalTheme::risk('· '.(string) $risk, $decorated));
            }
        }

        $this->newLine();
        $this->writeSection('continuar');
        if (! $ok) {
            $this->line('  atlas continue '.$this->ansi('2', '· retoma o plano e tenta repair'));
        } else {
            $this->line('  atlas chat'.($lastTraceId ? '' : '').' '.$this->ansi('2', '· abre a thread mais recente para revisar'));
        }
        $planId = (string) data_get($devPlan, 'plan_id', '');
        if ($planId !== '') {
            $this->line('  atlas dev '.escapeshellarg($task).' --resume='.$planId.' '.$this->ansi('2', '· reexecuta este plano'));
        }
        $this->newLine();
    }

    private function writeSection(string $label): void
    {
        $line = $this->ansi('1;36', $label);
        $rule = $this->ansi('90', str_repeat('-', max(2, strlen($label) + 4)));
        $this->line($line);
        $this->line($rule);
    }

    private function humanDuration(int $ms): string
    {
        $seconds = (int) round($ms / 1000);
        if ($seconds < 1) {
            return '<1s';
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds - $minutes * 60;

        return $remaining === 0 ? $minutes.'m' : $minutes.'m'.$remaining.'s';
    }

    private function ansi(string $code, string $text): string
    {
        if (! $this->output->isDecorated()) {
            return $text;
        }

        return "\033[".$code.'m'.$text."\033[0m";
    }

    /**
     * @param  array<int,string>  $files
     */
    private function filesNote(array $files): string
    {
        $count = count($files);
        if ($count === 0) {
            return 'sem alteracoes';
        }
        if ($count === 1) {
            return '1 arquivo';
        }

        return $count.' arquivos';
    }

    /**
     * @param  array<string,mixed>  $completion
     */
    private function testsNote(array $completion, bool $ranTests): string
    {
        if (! $ranTests) {
            return 'nao executados';
        }
        $tests = (array) data_get($completion, 'completion_packet.tests', []);
        if ($tests === []) {
            return 'sem teste detectado';
        }
        $first = (array) ($tests[0] ?? []);
        $okFlag = (bool) ($first['ok'] ?? false);
        if ($okFlag) {
            return 'tudo verde';
        }
        $exit = $first['exit_code'] ?? null;

        return 'falhou'.($exit !== null ? ' · exit '.(int) $exit : '');
    }

    /**
     * @param  array<string,mixed>  $preflight
     */
    private function bailOffline(array $preflight, bool $json, bool $progress, DevProgressReporter $reporter): int
    {
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

        if ($progress) {
            $reporter->fail('inspect', 'nenhum provider online');
            $this->newLine();
            $this->line('  rode '.$this->ansi('1', 'atlas bootstrap --refresh-providers').' ou use --force-offline-provider');
        } else {
            $this->error('Nenhum provider online. Rode atlas bootstrap --refresh-providers ou use --force-offline-provider se quiser tentar mesmo assim.');
        }

        return self::FAILURE;
    }

    private function runInteractiveDev(string $workspace): int
    {
        $command = $this->interactiveChatCommand($workspace);

        return (int) $this->runProviderCommand($command, $workspace, passthrough: true, tty: true)['exit_code'];
    }

    /**
     * @return array<int,string>
     */
    private function interactiveChatCommand(string $workspace): array
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'atlas:ai:chat',
            '--dev',
            '--new-thread',
            '--workspace='.$workspace,
            '--permission='.$this->permission(),
            '--stream',
            '--cockpit',
            '--no-skill-prompt',
        ];

        if ($provider = $this->provider()) {
            $command[] = '--provider='.$provider;
        }

        if ($model = $this->modelOption()) {
            $command[] = '--model='.$model;
        }

        if ($this->allowWrite()) {
            $command[] = '--allow-write';
        }

        if ($this->allowDanger()) {
            $command[] = '--dangerously-allow-all';
        }

        if ($this->allowUnsandboxed()) {
            $command[] = '--allow-unsandboxed';
        }

        foreach ($this->skillOptions() as $skill) {
            $command[] = '--skill='.$skill;
        }

        foreach ((array) $this->option('image') as $image) {
            if (is_scalar($image) && trim((string) $image) !== '') {
                $command[] = '--image='.trim((string) $image);
            }
        }

        if ((bool) $this->option('clipboard-image')) {
            $command[] = '--clipboard-image';
        }

        if ((bool) $this->option('no-auto-image')) {
            $command[] = '--no-auto-image';
        }

        return $command;
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int, stdout:string, stderr:string, command:array<int,string>, command_display:string}
     */
    private function runProviderCommand(array $command, string $workspace, bool $passthrough = true, bool $tty = false): array
    {
        $process = new Process($command, $workspace, AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(max(30, (int) $this->option('timeout')) + 60);

        if ($tty && Process::isTtySupported()) {
            $process->setTty(true);
            $process->setIdleTimeout(null);
            $process->setTimeout(null);
            $exitCode = $process->run();

            return [
                'exit_code' => is_int($exitCode) ? $exitCode : self::FAILURE,
                'stdout' => '',
                'stderr' => '',
                'command' => AtlasSecurity::redactCommand($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
            ];
        }

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
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload): void
    {
        $payload = AtlasSecurity::redactArray($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->renderPreflightLegacy((array) $payload['workflow']);
        $this->line('Comando de execucao: '.AtlasSecurity::commandLineForDisplay((array) $payload['chat_command']));
    }

    private function taskId(): ?string
    {
        $taskId = $this->option('task-id');

        return is_string($taskId) && trim($taskId) !== '' ? trim($taskId) : null;
    }

    private function modelOption(): ?string
    {
        $model = $this->option('model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    private function taskNotFound(string $taskId, bool $json): int
    {
        if ($json) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_task_not_found',
                'task_id' => $taskId,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error("Atlas task not found: {$taskId}");

        return self::FAILURE;
    }

    private function modelProviderMismatch(string $modelLabel, ?string $provider, bool $json): int
    {
        $message = 'Modelo '.$modelLabel.' nao combina com provider '.($provider ?: 'padrao').'. Use --provider correto ou remova --model.';
        if ($json) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_model_provider_mismatch',
                'message' => $message,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function manualProviderAllowed(?string $provider, AtlasAiRuntimeSettings $settings): bool
    {
        if (! in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli'], true)) {
            return true;
        }

        return (bool) ($settings->providerConfig((string) $provider)['allow_manual'] ?? true);
    }

    private function manualProviderBlocked(string $provider, bool $json): int
    {
        $message = "Provider {$provider} esta bloqueado para uso manual pelas configuracoes do Atlas app.";
        if ($json) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_manual_provider_blocked',
                'provider' => $provider,
                'message' => $message,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function provider(): ?string
    {
        $provider = $this->option('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    private function compactModelSelection(array $selection): array
    {
        return array_filter([
            'model' => $selection['model'] ?? null,
            'label' => $selection['label'] ?? null,
            'tier' => $selection['tier'] ?? null,
            'provider' => $selection['provider'] ?? null,
            'source' => $selection['source'] ?? null,
            'alias' => $selection['alias'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $selection
     */
    private function modelSelectionNote(array $selection): string
    {
        $label = (string) ($selection['label'] ?? $selection['model'] ?? '-');
        $model = (string) ($selection['model'] ?? '-');
        $tier = (string) ($selection['tier'] ?? 'manual');

        return "{$label} · {$model} · {$tier}";
    }

    private function permission(): string
    {
        if ((bool) $this->option('operator')) {
            return 'danger';
        }

        $permission = strtolower(trim((string) $this->option('permission')));
        if (in_array($permission, ['read', 'write', 'danger'], true)) {
            return $permission;
        }

        $configured = strtolower(trim((string) config('atlas.ai.tool_permissions.default_mode', 'read')));

        return in_array($configured, ['write', 'danger'], true) ? $configured : 'write';
    }

    private function allowWrite(): bool
    {
        return (bool) $this->option('allow-write') || in_array($this->permission(), ['write', 'danger'], true);
    }

    private function allowDanger(): bool
    {
        return (bool) $this->option('operator') || (bool) $this->option('dangerously-allow-all') || $this->permission() === 'danger';
    }

    private function allowUnsandboxed(): bool
    {
        return (bool) $this->option('operator')
            || (bool) $this->option('allow-unsandboxed')
            || (bool) config('atlas.ai.tool_permissions.allow_unsandboxed_write', false);
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

        if (preg_match('/trace\\s+([0-9a-fA-F]{8})/', $stdout, $matches)) {
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

        if (! $resolved || ! is_dir($resolved)) {
            return $workspace;
        }

        return $this->projectRootFor($resolved) ?: $resolved;
    }

    private function projectRootFor(string $workspace): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--show-toplevel'], $workspace, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(3);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $root = trim(AtlasSecurity::redactString($process->getOutput()));

        return $root !== '' && is_dir($root) ? $root : null;
    }
}
