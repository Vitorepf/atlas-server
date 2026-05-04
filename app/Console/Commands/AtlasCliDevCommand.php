<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\Cli\AtlasCliModelCatalogService;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Cli\AtlasTerminalNotifier;
use App\Services\Ai\Cli\AtlasTerminalTheme;
use App\Services\Ai\Cli\DevProgressReporter;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringTaskContractService;
use App\Support\AtlasPhpBinary;
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
        {--ai= : Session AI/provider alias: claude, codex, gemini or conselho}
        {--provider= : Force claude_cli, codex_cli or claude_codex}
        {--model= : Force model alias/id for the selected provider, for example sonnet, opus, spark, codex-premium, claude-opus-4-7 or gpt-5.5}
        {--claude-only : Fair Claude benchmark mode: force claude_cli + Claude Opus and disable fallback/decide/council}
        {--single-provider : Fair Claude benchmark mode: forbid provider switching}
        {--no-decide : Fair Claude benchmark mode: disable Atlas Decide for this run}
        {--fallback-disabled : Fair Claude benchmark mode: fail instead of falling back to another provider/model}
        {--critical : Prefer council/dual review when available}
        {--permission=auto : auto, read, write or danger}
        {--sandbox= : Forge/Harness sandbox override: workspace, worktree or docker}
        {--provider-runtime= : Forge/Harness provider runtime override: host, docker or auto}
        {--test-command= : Forge/Harness validation command override}
        {--visual-e2e= : Forge/Harness visual/E2E policy override: auto, off or required}
        {--quality-scan= : Forge/Harness quality scan override: auto, off or required}
        {--harness-policy= : Forge/Harness autonomy policy override: auto, off or strict}
        {--no-apply-isolated-patch : Forge/Harness keeps isolated worktree patch unapplied}
        {--allow-write : Confirm scoped workspace writes for this run}
        {--operator : Full local operator mode for trusted Mac workspaces}
        {--allow-unsandboxed : Allow write/danger with providers Atlas cannot sandbox directly}
        {--dangerously-allow-all : Confirm danger-full-access for this run}
        {--auto-test : Run tests in final quality gate}
        {--skill=* : Activate one or more agentskills bundle names}
        {--plan-only : Run preflight and print execution plan without calling provider}
        {--complete : Keep running repair iterations until gates pass or max iterations is reached}
        {--forge : Use the maximum-power programming profile behind atlas forge}
        {--max-iterations=3 : Maximum repair iterations for --complete}
        {--resume= : Resume a previous dev execution plan id when present in traces}
        {--no-open-brain : Disable automatic Open Brain context injection for this dev run}
        {--require-open-brain : Fail if Open Brain context cannot be injected}
        {--open-brain-refresh : Request a fresh Open Brain context instead of reusing a prior hash}
        {--open-brain-budget= : Override Open Brain context budget in characters}
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
        FairClaudePolicy $fairClaude,
        AtlasProgrammingOrchestrator $programming,
    ): int {
        $workspace = $this->workspace();
        $json = (bool) $this->option('json');
        $task = trim(implode(' ', (array) $this->argument('task')));
        $programmingProfile = (bool) $this->option('forge') ? 'forge' : 'dev';
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
            return $this->runInteractiveDev($workspace, $programming, $programmingProfile);
        }

        $fairFlags = $fairClaude->normalizeFlags($this->fairClaudeFlags());
        $fairMode = (bool) ($fairFlags['fair_mode'] ?? false);
        $explicitProvider = $this->provider();
        $provider = $explicitProvider;
        if ($fairMode && $provider === null) {
            $provider = FairClaudePolicy::PROVIDER_LOCK;
        }
        $modelOption = $this->modelOption();
        if ($fairMode && $modelOption === null) {
            $modelOption = FairClaudePolicy::MODEL_LOCK;
        }
        $modelSelection = $models->select($modelOption, $provider);
        if ($modelSelection !== null && $provider === null && is_string($modelSelection['provider'] ?? null)) {
            $provider = $modelSelection['provider'];
        }
        if ($fairMode) {
            $fairValidation = $fairClaude->validate($provider, $modelSelection);
            if (! (bool) ($fairValidation['ok'] ?? false)) {
                return $this->fairModeViolation($fairValidation, $json);
            }
        }
        if ($modelSelection !== null && ! $models->matchesProvider($modelSelection, $provider)) {
            return $this->modelProviderMismatch($models->label($modelSelection), $provider, $json);
        }
        if (($explicitProvider !== null || $modelSelection !== null) && ! $this->manualProviderAllowed($provider, $settings)) {
            return $this->manualProviderBlocked((string) $provider, $json);
        }
        $modelOverride = is_string($modelSelection['model'] ?? null) ? trim((string) $modelSelection['model']) : null;
        $modelOverride = $modelOverride !== '' ? $modelOverride : null;
        $aiPolicyOverride = $this->aiPolicyOverride($provider, $modelSelection, $modelOverride, fairMode: $fairMode);
        $preflight = $workflow->preflight(
            $workspace,
            $task,
            $provider,
            (bool) $this->option('critical') || $programmingProfile === 'forge',
            $programmingProfile,
        );
        if ($modelSelection !== null) {
            $preflight['selected_model'] = $this->compactModelSelection($modelSelection);
        }
        if ($fairMode) {
            $preflight['fair_mode'] = $fairClaude->metadata();
        }
        $planOnly = (bool) $this->option('plan-only');
        $complete = (bool) $this->option('complete') || $programmingProfile === 'forge';
        $maxIterations = $this->maxIterations($programmingProfile);
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
        $sessionPlan = $programming->sessionPlan($workspace, $programmingProfile, [
            'task' => $task,
            'provider' => $provider,
            'model' => $modelOverride,
            'interactive' => false,
            'complete' => $complete,
            'auto_test' => (bool) $this->option('auto-test') || $programmingProfile === 'forge',
            'max_iterations' => $maxIterations,
            'ai_policy_override' => $aiPolicyOverride,
        ]);
        $devPlan['orchestrator'] = 'AtlasProgrammingOrchestrator';
        $devPlan['programming_profile'] = $programmingProfile;
        $devPlan['programming_session_plan'] = $sessionPlan;
        $devPlan['quality_gate_policy'] = $workflow->qualityGatePolicy($complete, $maxIterations, $skills, fairMode: $fairMode);
        if ($modelSelection !== null) {
            $devPlan['selected_model'] = $this->compactModelSelection($modelSelection);
        }
        if ($fairMode) {
            $devPlan['fair_mode'] = $fairClaude->metadata();
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
            'auto_test' => (bool) $this->option('auto-test') || $programmingProfile === 'forge',
            'complete' => $complete,
            'max_iterations' => $maxIterations,
            'no_stream' => (bool) $this->option('no-stream'),
            'open_brain' => $this->openBrainOperatorOptions($complete, $programmingProfile),
            'skills' => $skills,
            'image_count' => count((array) $this->option('image')) + ((bool) $this->option('clipboard-image') ? 1 : 0),
            'auto_image' => ! (bool) $this->option('no-auto-image'),
        ];
        if ($aiPolicyOverride !== []) {
            $devPlan['operator_options']['ai_policy_override'] = $aiPolicyOverride;
            $devPlan['ai_policy_override'] = $aiPolicyOverride;
        }
        if ($programmingProfile === 'forge') {
            $devPlan['operator_options']['harness_overrides'] = array_filter([
                'sandbox' => $this->stringOption('sandbox'),
                'provider_runtime' => $this->stringOption('provider-runtime'),
                'test_command' => $this->stringOption('test-command'),
                'visual_e2e' => $this->stringOption('visual-e2e'),
                'quality_scan' => $this->stringOption('quality-scan'),
                'harness_policy' => $this->stringOption('harness-policy'),
                'apply_isolated_patch' => ! (bool) $this->option('no-apply-isolated-patch'),
            ], fn (mixed $value): bool => $value !== null);
        }
        if ($fairMode) {
            $devPlan['operator_options']['fair_mode'] = true;
            $devPlan['operator_options']['claude_only'] = (bool) ($fairFlags['claude_only'] ?? false);
            $devPlan['operator_options']['single_provider'] = true;
            $devPlan['operator_options']['no_decide'] = true;
            $devPlan['operator_options']['fallback_disabled'] = true;
        }
        if (is_string($this->option('resume')) && $this->option('resume') !== '') {
            $devPlan['plan_id'] = (string) $this->option('resume');
            $devPlan['resumed_at'] = now()->toJSON();
        }
        $providerPrompt = $workflow->promptWithEngineeringContract($task, $engineeringContract, $engineeringBlueprint);
        if ($fairMode) {
            $providerPrompt = $workflow->fairClaudePromptContract($providerPrompt);
        }

        if ($planOnly) {
            $chatCommand = $workflow->chatCommand(
                task: $providerPrompt,
                workspace: $workspace,
                provider: (string) $preflight['selected_provider'],
                model: $modelOverride,
                permission: $this->permission(),
                allowWrite: $this->allowWrite(),
                allowDanger: $this->allowDanger(),
                allowUnsandboxed: $this->allowUnsandboxed(),
                autoTest: (bool) $this->option('auto-test') || $programmingProfile === 'forge',
                timeout: (int) $this->option('timeout'),
                stream: ! (bool) $this->option('no-stream') && ! $json,
                noRun: (bool) $this->option('no-run'),
                devExecutionPlan: $devPlan,
                skills: $skills,
                json: $json,
                imagePaths: (array) $this->option('image'),
                clipboardImage: (bool) $this->option('clipboard-image'),
                noAutoImage: (bool) $this->option('no-auto-image'),
                openBrain: $this->openBrainCommandOptions($complete, $programmingProfile),
            );

            $this->printPayload([
                'ok' => true,
                'phase' => 'preflight',
                'workflow' => $preflight,
                'dev_execution_plan' => $devPlan,
                'activated_skills' => $skills,
                'open_brain_preview' => $this->openBrainPlanPreview(
                    input: $providerPrompt,
                    workspace: $workspace,
                    provider: (string) $preflight['selected_provider'],
                    model: $modelOverride,
                    devPlan: $devPlan,
                    skills: $skills,
                    complete: $complete,
                ),
                'chat_command' => $chatCommand,
            ]);

            return self::SUCCESS;
        }

        if ($atlasTask instanceof AtlasTask && $engineeringContract !== null && is_array($engineeringBlueprint)) {
            $engineeringBlueprintSnapshot = $blueprintSnapshots->freezeForTask($atlasTask, $engineeringContract, $engineeringBlueprint);
            if ($engineeringBlueprintSnapshot !== null) {
                $devPlan['engineering_blueprint_snapshot'] = $engineeringBlueprintSnapshot;
            }
        }

        if ($programmingProfile === 'forge' && ! $fairMode) {
            $result = $programming->executeWithHarness(ProgrammingExecutionRequest::fromArray([
                'profile' => 'forge',
                'workspace' => $workspace,
                'task' => $task,
                'objective' => $task,
                'task_id' => $taskId,
                'provider' => $provider,
                'model' => $modelOverride,
                'permission' => $this->permission(),
                'complete' => true,
                'auto_test' => true,
                'critical' => true,
                'max_attempts' => $maxIterations,
                'no_provider' => (bool) $this->option('no-run'),
                'test_command' => $this->stringOption('test-command'),
                'sandbox' => $this->stringOption('sandbox'),
                'provider_runtime' => $this->stringOption('provider-runtime'),
                'visual_e2e' => $this->stringOption('visual-e2e'),
                'quality_scan' => $this->stringOption('quality-scan'),
                'harness_policy' => $this->stringOption('harness-policy'),
                'apply_isolated_patch' => ! (bool) $this->option('no-apply-isolated-patch'),
                'contract' => is_array($engineeringContract) ? $engineeringContract : [],
                'policy_contracts' => data_get($devPlan, 'programming_session_plan.policy_contracts')
                    ?: data_get($devPlan, 'programming_session_plan.policy_profile.policy_contracts')
                    ?: data_get($devPlan, 'programming_session_plan.policy_profile.effective_policy.operational_contracts')
                    ?: [],
            ]))->toArray();

            if ($json) {
                $this->line(json_encode([
                    'ok' => in_array($result['status'] ?? null, ['passed', 'partial'], true),
                    'phase' => 'forge_harness',
                    'workflow' => $preflight,
                    'dev_execution_plan' => $devPlan,
                    'programming_result' => $result,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->newLine();
                $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Forge</>', (string) ($result['status'] ?? 'unknown'));
                $this->components->twoColumnDetail('Executor', (string) ($result['executor'] ?? 'engineering_harness'));
                $this->components->twoColumnDetail('Task', (string) ($result['task_id'] ?? '-'));
                $this->components->twoColumnDetail('Run', (string) data_get($result, 'harness_payload.run.id', '-'));
            }

            return in_array($result['status'] ?? null, ['passed', 'partial'], true)
                ? self::SUCCESS
                : self::FAILURE;
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
                : ($fairMode
                    ? $workflow->fairClaudeRepairCapsule($providerPrompt, (array) $completion, $iteration, $maxIterations, $devPlan)
                    : $programming->repairPrompt($providerPrompt, (array) $completion, $iteration, $maxIterations));

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
                autoTest: $programmingProfile === 'forge',
                timeout: (int) $this->option('timeout'),
                stream: ! (bool) $this->option('no-stream') && ! $json && ! $progress,
                noRun: (bool) $this->option('no-run'),
                devExecutionPlan: $devPlan,
                skills: $skills,
                json: $json,
                imagePaths: (array) $this->option('image'),
                clipboardImage: (bool) $this->option('clipboard-image'),
                noAutoImage: (bool) $this->option('no-auto-image'),
                openBrain: $this->openBrainCommandOptions($complete, $programmingProfile),
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

            $shouldRunTests = (bool) $this->option('auto-test') || $complete || $programmingProfile === 'forge';

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

        $providerOk = collect($runs)->every(fn (array $run): bool => (int) $run['exit_code'] === 0);
        $humanInterventionCount = 0;
        $fairProtocol = $fairMode
            ? $workflow->fairClaudeProtocolStatus((array) $completion, $providerOk, $humanInterventionCount)
            : null;

        if ($fairProtocol !== null) {
            $devPlan['fair_mode_result'] = $fairProtocol;
        }

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

        $qualityOk = ($complete || $fairMode) ? $finalStatus === 'passed' : $finalStatus !== 'failed';
        if ($fairProtocol !== null) {
            $qualityOk = $qualityOk && (string) ($fairProtocol['status'] ?? 'unverified') === 'valid';
        }
        $ok = $providerOk && $qualityOk;
        $fairFinalPacket = $fairProtocol !== null
            ? $workflow->fairClaudeFinalPacket((array) $completion, $fairProtocol, $devPlan, $runs, $ok)
            : null;
        if ($fairFinalPacket !== null) {
            $devPlan['final_packet'] = $fairFinalPacket;
            if (is_string($lastTraceId) && $lastTraceId !== '') {
                $workflow->persistPlan($lastTraceId, $devPlan);
            }
        }

        if ($json) {
            $this->line(json_encode(AtlasSecurity::redactArray([
                'ok' => $ok,
                'phase' => 'complete',
                'workflow' => $preflight,
                'dev_execution_plan' => $devPlan,
                'activated_skills' => $skills,
                'provider_runs' => $runs,
                'completion' => $completion,
                'fair_mode_result' => $fairProtocol,
                'final_packet' => $fairFinalPacket,
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

    private function runInteractiveDev(string $workspace, AtlasProgrammingOrchestrator $programming, string $programmingProfile): int
    {
        $command = $this->interactiveChatCommand($workspace, $programming, $programmingProfile);

        return (int) $this->runProviderCommand($command, $workspace, passthrough: true, tty: true)['exit_code'];
    }

    /**
     * @return array<int,string>
     */
    private function interactiveChatCommand(
        string $workspace,
        ?AtlasProgrammingOrchestrator $programming = null,
        string $programmingProfile = 'dev',
    ): array {
        $programming ??= app(AtlasProgrammingOrchestrator::class);
        $sessionPlan = $programming->sessionPlan($workspace, $programmingProfile, [
            'interactive' => true,
            'provider' => $this->provider(),
            'model' => $this->modelOption(),
            'complete' => $programmingProfile === 'forge',
            'auto_test' => $programmingProfile === 'forge',
            'max_iterations' => $programmingProfile === 'forge' ? 5 : 3,
        ]);
        $command = [
            AtlasPhpBinary::path(),
            base_path('artisan'),
            'atlas:ai:chat',
            '--dev',
            '--new-thread',
            '--workspace='.$workspace,
            '--permission='.$this->permission(),
            '--stream',
            '--cockpit',
            '--no-skill-prompt',
            '--dev-plan='.json_encode($sessionPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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

        if ($programmingProfile === 'forge') {
            $command[] = '--auto-test';
            $command[] = '--require-open-brain';
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
        $preview = is_array($payload['open_brain_preview'] ?? null) ? $payload['open_brain_preview'] : null;
        if ($preview) {
            $hash = is_string($preview['context_pack_hash'] ?? null) ? substr((string) $preview['context_pack_hash'], 0, 10) : 'sem hash';
            $refs = (int) data_get($preview, 'summary.context_refs', 0);
            $this->line('Open Brain: '.($preview['status'] ?? 'unknown').' · hash '.$hash.' · refs '.$refs);
        }
        $this->line('Comando de execucao: '.AtlasSecurity::commandLineForDisplay((array) $payload['chat_command']));
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @param  array<int,string>  $skills
     * @return array<string,mixed>
     */
    private function openBrainPlanPreview(string $input, string $workspace, ?string $provider, ?string $model, array $devPlan, array $skills, bool $complete): array
    {
        $openBrain = $this->openBrainCommandOptions($complete);
        $surface = data_get($devPlan, 'resumed_at') ? 'cli_continue' : 'cli_dev';
        $payload = [
            'app_surface' => 'atlas_cli',
            'atlas_workflow_mode' => 'dev',
            'workspace' => $workspace,
            'decision_mode' => $provider ? 'manual_override' : 'atlas_decide',
            'operator_requested_provider' => $provider ?: 'auto',
            'requested_provider' => $provider,
            'requested_model' => $model,
            'requested_agent' => 'desenvolvedor',
            'activated_skills' => $skills,
            'dev_execution_plan' => $devPlan,
            'open_brain' => array_filter($openBrain + [
                'surface' => $surface,
                'workflow_mode' => 'dev',
                'provider_safe_only' => true,
                'preview' => true,
            ], fn (mixed $value): bool => $value !== null),
        ];
        $options = [
            'source_type' => 'manual',
            'agent_slug' => 'desenvolvedor',
            'provider' => $provider,
            'include_semantic_context' => true,
            'payload' => $payload,
            'open_brain' => [
                'preview' => true,
            ],
        ];
        if ($model !== null && trim($model) !== '') {
            $options['model'] = trim($model);
        }

        try {
            $task = AiTaskRequest::fromInput($input, $options, [
                'agent' => 'desenvolvedor',
                'intent' => 'atlas_cli_dev_plan_preview',
            ]);
            $contextPack = app(AiContextPackBuilder::class)->build($input, $task, $options);
            $result = app(AtlasOpenBrainContextInjectionService::class)->inject($input, $task, $contextPack, $options);

            return $this->compactOpenBrainPlanPreview($result);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'enabled' => true,
                'status' => 'preview_failed',
                'reason' => 'open_brain_preview_exception',
                'surface' => $surface,
                'mode' => data_get($openBrain, 'mode', 'auto'),
                'workspace' => $workspace,
                'context_ready' => false,
                'provider_execution_allowed' => ! ((string) data_get($openBrain, 'mode') === 'required'),
                'context_pack_hash' => null,
                'audit_id' => null,
                'summary' => [
                    'context_refs' => 0,
                    'memory_refs' => 0,
                    'knowledge_refs' => 0,
                    'code_refs' => 0,
                    'budget_chars' => data_get($openBrain, 'budget_chars', config('atlas.open_brain.injection.budget_chars', 20000)),
                    'used_chars' => 0,
                    'provider_safe' => true,
                ],
                'warnings' => ['open_brain_preview_exception:'.class_basename($exception)],
                'next_actions' => ['Inspect logs and run atlas memory maintain before requiring Open Brain.'],
                'policy' => $openBrain + ['surface' => $surface, 'provider_safe_only' => true],
                'previewed_at' => now()->toJSON(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function compactOpenBrainPlanPreview(array $result): array
    {
        $status = (string) ($result['status'] ?? 'unknown');

        return [
            'enabled' => (bool) ($result['enabled'] ?? false),
            'status' => $status,
            'reason' => $result['reason'] ?? null,
            'surface' => $result['surface'] ?? null,
            'mode' => $result['mode'] ?? null,
            'workspace' => $result['workspace'] ?? null,
            'context_ready' => in_array($status, ['injected', 'degraded'], true),
            'provider_execution_allowed' => $status !== 'failed_closed',
            'context_pack_hash' => $result['context_pack_hash'] ?? null,
            'audit_id' => $result['audit_id'] ?? null,
            'summary' => is_array($result['summary'] ?? null) ? $result['summary'] : [],
            'warnings' => array_values((array) ($result['warnings'] ?? [])),
            'next_actions' => array_values((array) ($result['next_actions'] ?? [])),
            'policy' => is_array($result['policy'] ?? null) ? $result['policy'] : [],
            'previewed_at' => now()->toJSON(),
        ];
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string,bool>
     */
    private function fairClaudeFlags(): array
    {
        return [
            'claude_only' => (bool) $this->option('claude-only'),
            'single_provider' => (bool) $this->option('single-provider'),
            'no_decide' => (bool) $this->option('no-decide'),
            'fallback_disabled' => (bool) $this->option('fallback-disabled'),
        ];
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

    /**
     * @param  array<string,mixed>  $violation
     */
    private function fairModeViolation(array $violation, bool $json): int
    {
        $payload = AtlasSecurity::redactArray([
            'ok' => false,
            'phase' => 'preflight',
            'error' => FairClaudePolicy::ERROR_CODE,
            'message' => (string) ($violation['message'] ?? 'Fair Claude mode violation.'),
            'fair_mode' => $violation['fair_mode'] ?? [],
            'details' => $violation['details'] ?? [],
        ]);

        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error((string) $payload['message']);

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
        $provider = $this->option('provider') ?: $this->option('ai');
        if (is_string($provider) && trim($provider) !== '') {
            $provider = Str::of($provider)->lower()->trim()->replace(['_', ' '], '-')->toString();

            return match ($provider) {
                'claude', 'claude-cli' => 'claude_cli',
                'codex', 'codex-cli' => 'codex_cli',
                'gemini', 'gemini-cli' => 'gemini_cli',
                'conselho', 'council', 'ambos', 'claude-codex' => 'claude_codex',
                default => str_replace('-', '_', $provider),
            };
        }

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function aiPolicyOverride(?string $provider, ?array $modelSelection = null, ?string $modelOverride = null, bool $fairMode = false): array
    {
        if (! in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli'], true)) {
            return [];
        }

        if ($fairMode) {
            return app(FairClaudePolicy::class)->runtimeOverride($modelSelection, $modelOverride);
        }

        $override = [
            'default_provider' => $provider,
            'enabled_providers' => ['claude_cli', 'codex_cli', 'gemini_cli'],
            'disabled_providers' => [],
            'fallback_order' => array_values(array_unique([$provider, 'claude_cli', 'codex_cli', 'gemini_cli'])),
            'allow_council' => false,
            'allow_multistage_graph' => false,
        ];

        $model = $modelOverride ?: (is_string($modelSelection['model'] ?? null) ? (string) $modelSelection['model'] : null);
        if (is_string($model) && trim($model) !== '') {
            $model = trim($model);
            $override['providers'][$provider] = array_filter([
                'model' => $model,
                'model_label' => is_string($modelSelection['label'] ?? null) ? (string) $modelSelection['label'] : null,
                'model_tier' => is_string($modelSelection['tier'] ?? null) ? (string) $modelSelection['tier'] : null,
                'model_identity' => $model,
                'allow_auto' => true,
                'allow_manual' => true,
            ], fn (mixed $value): bool => $value !== null);
            $override['allowed_models'][$provider] = [$model];
        }

        return $override;
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

    /**
     * @return array<string,mixed>
     */
    private function openBrainOperatorOptions(bool $complete, string $programmingProfile = 'dev'): array
    {
        return $this->openBrainCommandOptions($complete, $programmingProfile);
    }

    /**
     * @return array<string,mixed>
     */
    private function openBrainCommandOptions(bool $complete, string $programmingProfile = 'dev'): array
    {
        $budget = $this->option('open-brain-budget');
        $budgetChars = is_scalar($budget) && trim((string) $budget) !== ''
            ? max(2000, (int) $budget)
            : null;
        $require = (bool) $this->option('require-open-brain')
            || $programmingProfile === 'forge'
            || ($complete && (bool) config('atlas.open_brain.injection.required_for_complete', true));

        return array_filter([
            'mode' => (bool) $this->option('no-open-brain')
                ? 'off'
                : ($require ? 'required' : 'auto'),
            'refresh' => (bool) $this->option('open-brain-refresh'),
            'budget_chars' => $budgetChars,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function maxIterations(string $programmingProfile = 'dev'): int
    {
        $requested = max(1, min(10, (int) $this->option('max-iterations')));

        return $programmingProfile === 'forge' ? max(5, $requested) : $requested;
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
