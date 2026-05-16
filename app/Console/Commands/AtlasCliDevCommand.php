<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\Cli\AtlasCliModelCatalogService;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Decision\ModelSelectionContractFactory;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineDevPlanBuilder;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Ai\Programming\ProgrammingIterationPolicy;
use App\Services\Ai\Programming\ProgrammingSurfaceContractFactory;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
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
        {--forge : Use the maximum-power programming profile behind atlas forge}
        {--repair : Mark this dev run as an explicit repair/fix intent}
        {--surface-origin= : Internal surface alias origin for thin wrapper commands}
        {--max-iterations=3 : Maximum repair iterations for the default complete dev loop}
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
        {--efficient : Use the Atlas Dev Efficient pipeline (workspace-bound flow: plan -> token -> run); skips legacy preflight}
        {--yes : Confirm execution non-interactively for --efficient runs; without it CLI prints the plan and stops}
        {--flow-origin= : Atlas AI Router origin tag (atlas_ai_router|direct) for --efficient runs}
        {--command-intent= : Atlas AI Router-resolved intent for --efficient runs (fix|explain|...)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the native Atlas CLI dev workflow with preflight, provider strategy and completion gate.';

    public function handle(
        AtlasCliDevWorkflowService $workflow,
        AtlasCliModelCatalogService $models,
        EngineeringTaskContractService $contracts,
        EngineeringBlueprintService $blueprints,
        EngineeringBlueprintSnapshotService $blueprintSnapshots,
        AtlasAiRuntimeSettings $settings,
        FairClaudePolicy $fairClaude,
        AtlasProgrammingOrchestrator $programming,
    ): int {
        $workspace = $this->workspace();
        $json = (bool) $this->option('json');
        $task = trim(implode(' ', (array) $this->argument('task')));

        if ((bool) $this->option('efficient')) {
            return $this->runEfficient($workspace, $task, $json);
        }

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
            $fairMode,
        );
        if ($modelSelection !== null) {
            $preflight['selected_model'] = $this->compactModelSelection($modelSelection);
        }
        $preflight['model_selection_contract'] = $this->modelSelectionContract(
            provider: $provider,
            modelSelection: $modelSelection,
            modelOverride: $modelOverride,
            fairMode: $fairMode,
            context: [
                'domain' => 'programming',
                'task' => $task,
            ],
        );
        if ($fairMode) {
            $preflight['fair_mode'] = $fairClaude->metadata();
        }
        $planOnly = (bool) $this->option('plan-only');
        $complete = true;
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
            'intent' => (bool) $this->option('repair') ? 'repair' : null,
        ]);
        $devPlan['orchestrator'] = 'AtlasProgrammingOrchestrator';
        $devPlan['programming_profile'] = $programmingProfile;
        $devPlan['programming_session_plan'] = $sessionPlan;
        $devPlan['quality_gate_policy'] = $workflow->qualityGatePolicy($complete, $maxIterations, $skills, fairMode: $fairMode);
        if ($modelSelection !== null) {
            $devPlan['selected_model'] = $this->compactModelSelection($modelSelection);
        }
        $devPlan['model_selection_contract'] = $this->modelSelectionContract(
            provider: $provider,
            modelSelection: $modelSelection,
            modelOverride: $modelOverride,
            fairMode: $fairMode,
            context: [
                'domain' => 'programming',
                'flow' => data_get($sessionPlan, 'flow'),
                'task' => $task,
            ],
        );
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
            'programming_intent' => (bool) $this->option('repair') ? 'repair' : 'implementation',
            'max_iterations' => $maxIterations,
            'no_stream' => (bool) $this->option('no-stream'),
            'open_brain' => $this->openBrainOperatorOptions($complete, $programmingProfile),
            'skills' => $skills,
            'image_count' => count((array) $this->option('image')) + ((bool) $this->option('clipboard-image') ? 1 : 0),
            'auto_image' => ! (bool) $this->option('no-auto-image'),
        ];
        if ($this->surfaceOrigin() === 'atlas_cli_fix') {
            $devPlan['operator_options']['surface_origin'] = 'atlas_cli_fix';
            $devPlan['fix_contract'] = app(ProgrammingSurfaceContractFactory::class)->fix(
                operatorOptions: $devPlan['operator_options'],
                command: $this->fixContractCommand(),
            );
        }
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
        $devPlan = $this->attachKernelPipelinePlan(
            devPlan: $devPlan,
            task: $task,
            workspace: $workspace,
            programmingProfile: $programmingProfile,
            inputMode: 'one_shot',
        );
        $providerPrompt = $workflow->promptWithEngineeringContract($task, $engineeringContract, $engineeringBlueprint);
        if ($fairMode) {
            $devPlan['fair_mode_prompt_contract'] = $workflow->fairClaudePromptContractMetadata($engineeringContract);
            $providerPrompt = $workflow->fairClaudePromptContract($providerPrompt, $engineeringContract);
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
                    'forge_contract' => app(ProgrammingSurfaceContractFactory::class)->forge($devPlan, $result),
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

        $command = $this->interactiveChatCommand(
            $workspace,
            $programming,
            $programmingProfile,
            $providerPrompt,
            $devPlan,
        );
        $run = $this->runProviderCommand($command, $workspace, passthrough: ! $json, tty: false);

        return (int) $run['exit_code'];
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

    private function runInteractiveDev(string $workspace, AtlasProgrammingOrchestrator $programming, string $programmingProfile): int
    {
        $command = $this->interactiveChatCommand($workspace, $programming, $programmingProfile);

        return (int) $this->runProviderCommand($command, $workspace, passthrough: true, tty: true)['exit_code'];
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @return array<int,string>
     */
    private function interactiveChatCommand(
        string $workspace,
        ?AtlasProgrammingOrchestrator $programming = null,
        string $programmingProfile = 'dev',
        ?string $task = null,
        array $devPlan = [],
    ): array {
        $programming ??= app(AtlasProgrammingOrchestrator::class);
        $complete = true;
        $autoTest = (bool) $this->option('auto-test') || $programmingProfile === 'forge';
        $sessionPlan = $devPlan !== [] ? $devPlan : $programming->sessionPlan($workspace, $programmingProfile, [
            'task' => $task,
            'interactive' => $task === null,
            'provider' => $this->provider(),
            'model' => $this->modelOption(),
            'complete' => $complete,
            'auto_test' => $autoTest,
            'max_iterations' => $this->maxIterations($programmingProfile),
        ]);
        if (is_array($sessionPlan)) {
            data_set($sessionPlan, 'operator_options.input_mode', $task === null ? 'interactive' : 'one_shot');
            $sessionPlan = $this->attachKernelPipelinePlan(
                devPlan: $sessionPlan,
                task: $task ?? 'atlas dev interactive cockpit',
                workspace: $workspace,
                programmingProfile: $programmingProfile,
                inputMode: $task === null ? 'interactive' : 'one_shot',
            );
        }
        $command = [
            AtlasPhpBinary::path(),
            base_path('artisan'),
            'atlas:ai:chat',
        ];

        if ($task !== null && trim($task) !== '') {
            $command[] = $task;
        }

        $command = array_merge($command, [
            '--dev',
            '--new-thread',
            '--workspace='.$workspace,
            '--permission='.$this->permission(),
            '--cockpit',
            '--no-skill-prompt',
            '--dev-plan='.json_encode($sessionPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        if (! (bool) $this->option('no-stream') && ! (bool) $this->option('json')) {
            $command[] = '--stream';
        }

        if ((bool) $this->option('json')) {
            $command[] = '--json';
        }

        if ((bool) $this->option('no-run')) {
            $command[] = '--no-run';
        }

        $command[] = '--timeout='.(int) $this->option('timeout');

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

        if ($autoTest) {
            $command[] = '--auto-test';
        }

        if ((bool) $this->option('claude-only')) {
            $command[] = '--claude-only';
        }

        if ((bool) $this->option('single-provider')) {
            $command[] = '--single-provider';
        }

        if ((bool) $this->option('no-decide')) {
            $command[] = '--no-decide';
        }

        if ((bool) $this->option('fallback-disabled')) {
            $command[] = '--fallback-disabled';
        }

        if ((bool) $this->option('no-open-brain')) {
            $command[] = '--no-open-brain';
        }

        if ((bool) $this->option('require-open-brain') || $programmingProfile === 'forge') {
            $command[] = '--require-open-brain';
        }

        if ((bool) $this->option('open-brain-refresh')) {
            $command[] = '--open-brain-refresh';
        }

        if (is_scalar($this->option('open-brain-budget')) && trim((string) $this->option('open-brain-budget')) !== '') {
            $command[] = '--open-brain-budget='.trim((string) $this->option('open-brain-budget'));
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
     * @param  array<string,mixed>  $devPlan
     * @return array<string,mixed>
     */
    private function attachKernelPipelinePlan(
        array $devPlan,
        string $task,
        string $workspace,
        string $programmingProfile,
        string $inputMode,
    ): array {
        if (is_array($devPlan['kernel_pipeline'] ?? null)) {
            return $devPlan;
        }

        $flow = $programmingProfile === 'forge'
            ? 'programming.forge'
            : ((bool) $this->option('repair') ? 'programming.repair' : 'programming.dev');
        $runtime = data_get($devPlan, 'programming_session_plan.executor_decision.executor')
            ?: data_get($devPlan, 'executor_decision.executor')
            ?: ($programmingProfile === 'forge' ? 'engineering_harness' : 'dev_repair_executor');

        return app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: $devPlan,
            text: $task,
            workspace: $workspace,
            surfaceId: $programmingProfile === 'forge' ? 'atlas_cli_forge' : 'atlas_cli_dev',
            command: 'atlas:cli:dev',
            inputMode: $inputMode,
            programmingProfile: $programmingProfile,
            flow: $flow,
            taskKind: (bool) $this->option('repair') ? 'repair' : 'implementation',
            runtime: (string) $runtime,
            inputType: $inputMode === 'interactive' ? 'interactive_session' : 'text',
        );
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

    /**
     * Atlas Dev Efficient short-circuit: bypass the legacy preflight / workflow
     * stack and drive the canonical orchestrator + DB-backed token + RunExecutor
     * via {@see \App\Services\Ai\Cli\AtlasCliDevEfficientHandler}.
     */
    private function runEfficient(string $workspace, string $task, bool $json): int
    {
        $handler = app(\App\Services\Ai\Cli\AtlasCliDevEfficientHandler::class);

        if ($task === '') {
            $payload = [
                'error' => [
                    'code' => 'ATLAS_DEV_PLAN_FAILED',
                    'message' => '--efficient requires a task description (e.g. atlas:cli:dev "fix failing test" --efficient).',
                ],
            ];
            $this->renderEfficient($payload, $json);

            return 64;
        }

        $input = [
            'workspace' => $workspace,
            'raw_intent' => $task,
            'user_constraints' => [],
            'operator_confirmed' => (bool) $this->option('yes'),
            'flow_origin' => $this->efficientStringOption('flow-origin'),
            'command_intent' => $this->efficientStringOption('command-intent'),
        ];

        $outcome = $handler->run(array_filter($input, fn ($v) => $v !== null && $v !== ''));

        $this->renderEfficient($outcome['payload'], $json);

        return (int) $outcome['exit_code'];
    }

    private function efficientStringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderEfficient(array $payload, bool $json): void
    {
        if ($json) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $this->line($encoded !== false ? $encoded : '{}');

            return;
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $code = (string) ($payload['error']['code'] ?? 'ATLAS_DEV_ERROR');
            $message = (string) ($payload['error']['message'] ?? '');
            $this->error("[{$code}] {$message}");

            return;
        }

        $this->line('Atlas Dev Efficient — plan summary');
        $this->line('  run_id           : '.(string) ($payload['run_id'] ?? '-'));
        $this->line('  surface_id       : '.(string) ($payload['surface_id'] ?? '-'));
        $this->line('  workspace_label  : '.(string) ($payload['workspace_label'] ?? '-'));
        $this->line('  routing.kind     : '.(string) ($payload['routing']['kind'] ?? '-'));
        $reasons = $payload['routing']['reasons'] ?? [];
        if (is_array($reasons) && $reasons !== []) {
            $this->line('  routing.reasons  : '.implode(', ', array_map('strval', $reasons)));
        }
        $blockers = $payload['routing']['blockers'] ?? [];
        if (is_array($blockers) && $blockers !== []) {
            $this->line('  routing.blockers : '.implode(', ', array_map('strval', $blockers)));
        }
        $suggested = $payload['routing']['suggested_flow'] ?? null;
        if (is_string($suggested) && $suggested !== '') {
            $this->line('  suggested_flow   : '.$suggested);
        }
        $this->line('  task_kind        : '.(string) ($payload['task_kind'] ?? '-'));
        $this->line('  risk_level       : '.(string) ($payload['risk_level'] ?? '-'));
        $this->line('  mode             : '.(string) ($payload['mode'] ?? '-'));
        $this->line('  operator_confirmed: '.((bool) ($payload['operator_confirmed'] ?? false) ? 'yes' : 'no'));

        if (! empty($payload['confirmation_required'])) {
            $this->warn(($payload['confirmation_hint'] ?? 'Re-run with --yes to execute.'));
        }

        $refs = $payload['persisted_artifact_refs'] ?? [];
        if (is_array($refs) && $refs !== []) {
            $this->line('  receipts:');
            foreach ($refs as $name => $ref) {
                $this->line("    - {$name}: {$ref}");
            }
        }

        if (isset($payload['run']) && is_array($payload['run'])) {
            $run = $payload['run'];
            $this->line('Run result:');
            $this->line('  completion_state : '.(string) ($run['completion_state'] ?? '-'));
            $this->line('  scope_guard      : '.(string) ($run['scope_guard_status'] ?? '-'));
            $this->line('  verification     : '.(string) ($run['verification_status'] ?? '-'));
            $runRefs = $run['persisted_receipt_refs'] ?? [];
            if (is_array($runRefs) && $runRefs !== []) {
                foreach ($runRefs as $name => $ref) {
                    $this->line("    - {$name}: {$ref}");
                }
            }
        }
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
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array
    {
        return app(ModelSelectionContractFactory::class)->forCliDev($provider, $modelSelection, $modelOverride, $fairMode, $context);
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
        return ProgrammingIterationPolicy::forProfile($this->option('max-iterations'), $programmingProfile);
    }

    private function surfaceOrigin(): ?string
    {
        $origin = $this->option('surface-origin');

        if (! is_scalar($origin)) {
            return null;
        }

        $origin = trim((string) $origin);

        return in_array($origin, ['atlas_cli_fix'], true) ? $origin : null;
    }

    /**
     * @return array<int,string>
     */
    private function fixContractCommand(): array
    {
        $command = [
            'atlas:cli:dev',
            '--surface-origin=atlas_cli_fix',
        ];

        foreach ([
            'repair' => '--repair',
            'allow-write' => '--allow-write',
            'auto-test' => '--auto-test',
            'plan-only' => '--plan-only',
        ] as $option => $flag) {
            if ((bool) $this->option($option)) {
                $command[] = $flag;
            }
        }

        return $command;
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
