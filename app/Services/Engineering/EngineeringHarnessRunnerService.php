<?php

namespace App\Services\Engineering;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Tools\AtlasToolGateService;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\Services\Engineering\EngineeringHarness\HarnessRunnerSupport;
use App\Services\Engineering\EngineeringHarness\HarnessControlsSection;
use App\Services\Engineering\EngineeringHarness\HarnessProviderSection;
use App\Services\Engineering\EngineeringHarness\HarnessEvidenceSection;
use App\Services\Engineering\EngineeringHarness\HarnessSummarySection;
use App\Services\Engineering\EngineeringHarness\HarnessOptionsSection;

class EngineeringHarnessRunnerService
{
    private readonly HarnessRunnerSupport $support;

    private readonly HarnessControlsSection $controls;

    private readonly HarnessProviderSection $provider;

    private readonly HarnessEvidenceSection $evidence;

    private readonly HarnessSummarySection $summary;

    private readonly HarnessOptionsSection $options;

    public function __construct(
        private readonly EngineeringTaskContractService $contracts,
        private readonly EngineeringBlueprintService $blueprints,
        private readonly EngineeringBlueprintSnapshotService $snapshots,
        private readonly EngineeringHarnessabilityService $harnessability,
        private readonly EngineeringWorkspaceService $workspaces,
        private readonly EngineeringProviderRuntimeService $providerRuntimes,
        private readonly EngineeringDockerHarnessService $dockerHarness,
        private readonly EngineeringModelPolicyService $modelPolicy,
        private readonly EngineeringControlRegistryService $controlRegistry,
        private readonly EngineeringContextPackService $contextPacks,
        private readonly EngineeringPatchArtifactService $patchArtifacts,
        private readonly EngineeringTestMatrixService $testMatrix,
        private readonly EngineeringRunScoringService $scoring,
        private readonly EngineeringRunArtifactService $artifacts,
        private readonly EngineeringReviewFindingService $reviewFindings,
        private readonly AtlasMemoryRegistryService $memoryRegistry,
        private readonly AtlasToolGateService $toolGate,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly ?AtlasWorkspacePathResolverService $workspacePaths = null,
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate = null,
        private readonly ?EngineeringHarnessRunnerInput $input = null,
    ) {
        $this->support = new HarnessRunnerSupport();
        $this->controls = new HarnessControlsSection($this->support, $this->controlRegistry, $this->toolGate, $this->ledger);
        $this->provider = new HarnessProviderSection($this->support, $this->providerRuntimes);
        $this->evidence = new HarnessEvidenceSection($this->artifacts, $this->controlRegistry, $this->workspaces, $this->reviewFindings, $this->workspacePaths, $this->workspaceGate);
        $this->summary = new HarnessSummarySection();
        $this->options = new HarnessOptionsSection();
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(AtlasTask $task, array $options): array
    {
        $workspace = $this->support->workspace($options['workspace'] ?? getcwd());
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $noProvider = (bool) ($options['no_provider'] ?? false);
        $requestedAutoTest = (bool) ($options['auto_test'] ?? false);
        $requestedMaxAttempts = $this->runnerInput()->maxAttempts($options['max_attempts'] ?? null);
        $requestedProvider = is_string($options['provider'] ?? null) && trim((string) $options['provider']) !== ''
            ? trim((string) $options['provider'])
            : null;
        $requestedModel = is_string($options['model'] ?? null) && trim((string) $options['model']) !== ''
            ? trim((string) $options['model'])
            : null;
        $requestedModelPolicy = is_string($options['model_policy'] ?? null) && trim((string) $options['model_policy']) !== ''
            ? trim((string) $options['model_policy'])
            : 'fixed';
        $requestedPermission = (string) ($options['permission'] ?? 'auto');
        $requestedSandbox = (string) ($options['sandbox'] ?? 'workspace');
        $keepWorkspace = (bool) ($options['keep_workspace'] ?? false);
        $applyIsolatedPatch = (bool) ($options['apply_isolated_patch'] ?? true);
        $controlProfile = is_string($options['control_profile'] ?? null) ? $options['control_profile'] : null;
        $testCommand = is_string($options['test_command'] ?? null) ? $options['test_command'] : null;
        $visualE2e = $this->options->visualE2eMode($options['visual_e2e'] ?? config('atlas.engineering.visual_e2e.mode', 'auto'));
        $qualityScan = $this->support->qualityScanMode($options['quality_scan'] ?? config('atlas.engineering.quality_scan.mode', 'off'));
        $qualityProfile = $this->support->qualityScanProfile($options['quality_profile'] ?? config('atlas.engineering.quality_scan.profile', 'auto'));
        $qualityChangedOnly = array_key_exists('quality_changed_only', $options)
            ? (bool) $options['quality_changed_only']
            : (bool) config('atlas.engineering.quality_scan.changed_only', true);
        $dockerOptions = $this->options->dockerOptions($options);
        $providerRuntimeOptions = $this->options->providerRuntimeOptions($options);
        $replay = is_array($options['replay'] ?? null) ? $options['replay'] : null;
        $fairModeOptions = $this->fairModeOptions($options);
        [$requestedProvider, $requestedModel, $requestedModelPolicy] = $this->fairProviderRequest(
            $requestedProvider,
            $requestedModel,
            $requestedModelPolicy,
            $fairModeOptions,
        );

        $awisBlock = $this->evidence->awisMutationBlock(
            workspace: $workspace,
            task: $task,
            dryRun: $dryRun,
            noProvider: $noProvider,
            requestedSandbox: $requestedSandbox,
            applyIsolatedPatch: $applyIsolatedPatch,
        );
        if ($awisBlock !== null) {
            return $awisBlock;
        }

        $task->loadMissing(['project', 'projectStep']);
        $contract = $this->contracts->forTask($task);
        $blueprint = $this->blueprints->forTask($task, $contract);
        $snapshot = $this->snapshots->freezeForTask($task, $contract, $blueprint);
        $harnessability = $this->harnessability->score($workspace);
        $modelSelection = $this->modelPolicy->select($task, [
            'contract' => $contract,
            'blueprint' => $blueprint,
            'harnessability' => $harnessability,
        ], [
            'provider' => $requestedProvider,
            'model' => $requestedModel,
            'model_policy' => $requestedModelPolicy,
            'dry_run' => $dryRun,
            'no_provider' => $noProvider,
        ]);
        $provider = is_string($modelSelection['selected_provider'] ?? null) && trim((string) $modelSelection['selected_provider']) !== ''
            ? trim((string) $modelSelection['selected_provider'])
            : $requestedProvider;
        $model = is_string($modelSelection['selected_model'] ?? null) && trim((string) $modelSelection['selected_model']) !== ''
            ? trim((string) $modelSelection['selected_model'])
            : null;
        $autonomyPolicy = $this->autonomyPolicy($harnessability, [
            'mode' => $options['harness_policy'] ?? 'auto',
            'dry_run' => $dryRun,
            'no_provider' => $noProvider,
            'permission' => $requestedPermission,
            'sandbox' => $requestedSandbox,
            'max_attempts' => $requestedMaxAttempts,
            'auto_test' => $requestedAutoTest,
            'force_sandbox_without_provider' => $replay !== null,
        ]);
        $permission = (string) $autonomyPolicy['effective_permission'];
        $effectiveSandbox = (string) $autonomyPolicy['effective_sandbox'];
        $maxAttempts = (int) $autonomyPolicy['effective_max_attempts'];
        $autoTest = (bool) $autonomyPolicy['effective_auto_test'];
        $controls = $this->controlRegistry->applicableControls(
            task: $task,
            workspace: $workspace,
            contract: $contract,
            blueprint: $blueprint,
            profile: $controlProfile,
            autoTest: $autoTest,
            testCommand: $testCommand,
        );
        $this->controlRegistry->persistControls($controls);

        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'project_step_id' => $task->project_step_id,
            'blueprint_snapshot_id' => $snapshot['id'] ?? null,
            'blueprint_id' => $blueprint['blueprint_id'] ?? null,
            'workspace_path_hash' => hash('sha256', $workspace),
            'workspace_label' => basename($workspace) ?: $workspace,
            'provider_strategy_json' => [
                'provider' => $provider,
                'model' => $model,
                'requested_provider' => $requestedProvider,
                'requested_model' => $requestedModel,
                'model_policy' => $modelSelection,
                'permission' => $permission,
                'requested_permission' => $requestedPermission,
                'mode' => ($options['complete'] ?? false) ? 'complete' : 'single_shot',
                'dry_run' => $dryRun,
                'no_provider' => $noProvider,
                'sandbox' => $requestedSandbox,
                'effective_sandbox' => $effectiveSandbox,
                'requested_max_attempts' => $requestedMaxAttempts,
                'effective_max_attempts' => $maxAttempts,
                'requested_auto_test' => $requestedAutoTest,
                'effective_auto_test' => $autoTest,
                'harness_policy' => $autonomyPolicy,
                'apply_isolated_patch' => $applyIsolatedPatch,
                'visual_e2e' => $visualE2e,
                'quality_scan' => [
                    'mode' => $qualityScan,
                    'profile' => $qualityProfile,
                    'changed_only' => $qualityChangedOnly,
                ],
                'docker' => $dockerOptions,
                'provider_runtime' => $providerRuntimeOptions,
                'replay' => $replay,
                'fair_mode' => $fairModeOptions,
            ],
            'harnessability_score' => (int) ($harnessability['score'] ?? 0),
            'status' => 'preparing',
            'max_attempts' => $maxAttempts,
            'started_at' => now(),
            'metadata' => [
                'sandbox_type' => $effectiveSandbox,
                'requested_sandbox_type' => $requestedSandbox,
                'sandbox_suppressed_reason' => $autonomyPolicy['sandbox_reason'] ?? ($effectiveSandbox !== $requestedSandbox ? 'provider_not_executed' : null),
                'requested_permission' => $requestedPermission,
                'effective_permission' => $permission,
                'requested_max_attempts' => $requestedMaxAttempts,
                'effective_max_attempts' => $maxAttempts,
                'requested_auto_test' => $requestedAutoTest,
                'effective_auto_test' => $autoTest,
                'autonomy_policy' => $autonomyPolicy,
                'docker_options' => $dockerOptions,
                'provider_runtime_options' => $providerRuntimeOptions,
                'visual_e2e' => $visualE2e,
                'quality_scan' => [
                    'mode' => $qualityScan,
                    'profile' => $qualityProfile,
                    'changed_only' => $qualityChangedOnly,
                ],
                'model_policy' => $modelSelection,
                'control_profile' => $controlProfile,
                'harnessability' => $harnessability,
                'blueprint_snapshot' => $snapshot,
                'replay' => $replay,
                'fair_mode' => $fairModeOptions,
            ],
        ]);
        $this->controls->recordHarnessLedgerEvent(LedgerEventType::ExecutionStarted, $run->refresh(), [
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'provider' => $provider,
            'model' => $model,
            'dry_run' => $dryRun,
            'no_provider' => $noProvider,
            'sandbox' => $effectiveSandbox,
            'permission' => $permission,
            'max_attempts' => $maxAttempts,
            'auto_test' => $autoTest,
            'harnessability_score' => (int) ($harnessability['score'] ?? 0),
            'context_pack_hash' => null,
        ]);

        $this->controls->recordAutonomyPolicyControl($run->refresh(), $autonomyPolicy);
        $this->controls->recordModelSelectionControl($run->refresh(), $modelSelection);
        $this->controls->recordReplayControl($run->refresh(), $replay);
        $workspacePlan = $this->workspaces->prepare($workspace, $run, array_merge(['sandbox' => $effectiveSandbox], $dockerOptions));
        $executionWorkspace = (string) ($workspacePlan['execution_workspace'] ?? $workspace);
        $providerRuntimePlan = ($dryRun || $noProvider)
            ? $this->options->skippedProviderRuntimePlan($providerRuntimeOptions)
            : $this->providerRuntimes->plan($workspacePlan, $providerRuntimeOptions);
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'workspace_plan' => $this->support->compactWorkspacePlan($workspacePlan),
                'provider_runtime_plan' => $this->support->compactProviderRuntimePlan($providerRuntimePlan),
            ]),
        ])->save();
        $this->controls->recordWorkspacePlanControl($run->refresh(), $workspacePlan);
        $dockerNetworkPolicy = $this->dockerHarness->networkPolicyStatus($workspacePlan);
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'docker_network_policy' => $this->support->compactDockerNetworkPolicy($dockerNetworkPolicy),
            ]),
        ])->save();
        $this->controls->recordDockerNetworkControl($run->refresh(), $dockerNetworkPolicy, $workspacePlan);
        $this->controls->recordProviderRuntimeControl($run->refresh(), $providerRuntimePlan);

        $contextPack = $this->contextPacks->build($task, $run, $executionWorkspace, $contract, $blueprint, $controls);
        $this->controls->recordHarnessLedgerEvent(LedgerEventType::ContextComposed, $run->refresh(), [
            'context_pack_hash' => $contextPack['hash'] ?? null,
            'control_count' => count($controls),
            'blueprint_id' => $blueprint['blueprint_id'] ?? null,
            'blueprint_snapshot_id' => $snapshot['id'] ?? null,
        ]);
        $this->controls->recordPrepareControls($run, $controls, $contract, $blueprint);

        $promptHash = hash('sha256', json_encode([$contract, $blueprint, $contextPack], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $inputSummary = [
            'task_id' => $task->id,
            'contract_goal' => $contract['goal'] ?? null,
            'context_pack_hash' => $contextPack['hash'] ?? null,
        ];

        $attempt = AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => $provider,
            'model' => $model,
            'phase' => $dryRun || $noProvider ? 'review' : 'edit',
            'prompt_hash' => $promptHash,
            'input_summary_json' => $inputSummary,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'dry_run' => $dryRun,
                'no_provider' => $noProvider,
                'provider_runtime_plan' => $this->support->compactProviderRuntimePlan($providerRuntimePlan),
            ],
        ]);

        $providerRun = null;
        if (! $dryRun && ! $noProvider) {
            $providerRun = $this->provider->runProvider($task, $executionWorkspace, [
                'provider' => $provider,
                'model' => $model,
                'permission' => $permission,
                'complete' => (bool) ($options['complete'] ?? false),
                'max_attempts' => $maxAttempts,
                'critical' => (bool) ($options['critical'] ?? false),
                'fair_mode' => $fairModeOptions,
                'timeout_seconds' => $options['provider_timeout_seconds'] ?? null,
            ], $workspacePlan, $providerRuntimePlan);

            $attempt = $this->syncProviderAttempts($run->refresh(), $attempt, $providerRun, $provider);
            $this->controls->recordHarnessLedgerEvent(LedgerEventType::ProviderReturned, $run->refresh(), [
                'attempt_id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'attempt_status' => $attempt->status,
                'provider' => $provider,
                'model' => $model,
                'trace_id' => $attempt->trace_id ?: ($providerRun['trace_id'] ?? null),
                'exit_code' => data_get($providerRun, 'exit_code'),
                'duration_ms' => data_get($providerRun, 'duration_ms'),
                'response_hash' => data_get($providerRun, 'response_hash'),
                'completion_status' => data_get($providerRun, 'decoded.completion.status'),
            ]);
        } else {
            $attempt->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'provider_skipped_reason' => $dryRun ? 'dry_run' : 'no_provider',
                ]),
            ])->save();
        }

        $patch = $this->patchArtifacts->capture($run, $attempt->refresh(), $executionWorkspace, [
            'source' => 'atlas:engineering:runner',
            'workspace_mode' => $workspacePlan['mode'] ?? 'workspace',
            'isolated_workspace' => (bool) ($workspacePlan['isolated'] ?? false),
        ]);
        $this->controls->recordPostAttemptControls($run->refresh(), $attempt->refresh(), $controls, $patch);
        $this->controls->recordChangedFilesScopeControl($run->refresh(), $attempt->refresh(), $contract, $blueprint, $patch);
        $dockerHealthchecks = $autoTest
            ? $this->dockerHarness->runHealthchecks($workspacePlan)
            : ['status' => 'skipped', 'reason' => 'auto_test_disabled'];
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'docker_healthchecks' => $this->support->compactDockerHealthchecks($dockerHealthchecks),
            ]),
        ])->save();
        $this->controls->recordDockerHealthcheckControl($run->refresh(), $dockerHealthchecks, $workspacePlan);

        $cases = $this->testMatrix->ensureCases(
            task: $task,
            blueprint: $blueprint,
            workspace: $executionWorkspace,
            controlDefinitions: $controls,
            explicitTestCommand: $testCommand,
            autoTest: $autoTest,
            options: [
                'visual_e2e' => $visualE2e,
                'quality_scan' => $qualityScan,
                'quality_profile' => $qualityProfile,
                'quality_changed_only' => $qualityChangedOnly,
                'test_timeout_seconds' => $options['test_timeout_seconds'] ?? null,
            ],
        );
        $testRuns = $autoTest && (($dockerHealthchecks['status'] ?? null) !== 'failed')
            ? $this->testMatrix->runCases($run->refresh(), $attempt->refresh(), $executionWorkspace, $cases, true, [
                'workspace_plan' => $workspacePlan,
            ])
            : collect();
        $this->controls->recordToolRuntimeGateControl($run->refresh(), $attempt->refresh(), $executionWorkspace, [
            'quality_scan' => $qualityScan,
            'quality_profile' => $qualityProfile,
        ]);
        $this->controls->recordVisualToolRuntimeGateControl($run->refresh(), $attempt->refresh(), $executionWorkspace, $visualE2e, $testRuns);
        $this->controls->recordSkippedRequiredControls($run->refresh(), $attempt->refresh(), $controls);
        if ($providerRun !== null) {
            $this->evidence->recordProviderReviewFindings($run->refresh(), $attempt->refresh(), $providerRun);
        }

        $preliminaryScoring = $this->scoring->score($run->refresh(), $contract, $blueprint);
        $patchApply = $this->evidence->applyIsolatedPatch(
            run: $run->refresh(),
            attempt: $attempt->refresh(),
            workspacePlan: $workspacePlan,
            patch: $patch,
            scoring: $preliminaryScoring,
            enabled: $applyIsolatedPatch,
        );

        $scoring = $this->scoring->score($run->refresh(), $contract, $blueprint);
        $run->forceFill([
            'status' => $this->support->statusForDecision((string) $scoring['decision']),
            'decision' => $scoring['decision'],
            'score' => $scoring['score'],
            'attempt_count' => $run->attempts()->count(),
            'trace_id' => $attempt->trace_id ?: ($providerRun['trace_id'] ?? null),
            'finished_at' => now(),
            'metadata' => array_merge($run->metadata ?? [], [
                'score_components' => $scoring['components'] ?? [],
                'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
                'provider_run' => $providerRun ? $this->support->compactProviderPayload($providerRun) : null,
                'fair_mode_result' => data_get($providerRun, 'decoded.fair_mode_result'),
                'isolated_patch_apply' => $patchApply,
            ]),
        ])->save();
        $terminalEvent = in_array((string) $run->status, ['resolved', 'partial', 'succeeded', 'passed'], true)
            || in_array((string) $run->decision, ['resolved', 'partial'], true)
            ? LedgerEventType::OperationCompleted
            : LedgerEventType::OperationFailed;
        $this->controls->recordHarnessLedgerEvent($terminalEvent, $run->refresh(), [
            'decision' => $run->decision,
            'status' => $run->status,
            'score' => $run->score,
            'attempt_count' => $run->attempt_count,
            'trace_id' => $run->trace_id,
            'test_run_count' => $testRuns->count(),
            'patch_artifact_id' => $patch?->id,
            'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
        ]);

        $this->evidence->recordEvidence($task->refresh(), $run->refresh(), $scoring, $patch);
        $this->evidence->persistTaskSummary($task->refresh(), $run->refresh(), $scoring);
        $this->memoryRegistry->recordHarnessLearning($run->refresh(), [
            'test_run_count' => $testRuns->count(),
            'patch_artifact_id' => $patch?->id,
            'source' => 'atlas:engineering:runner',
        ]);
        $workspaceRelease = $this->workspaces->release($workspacePlan, $keepWorkspace);
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'workspace_release' => $workspaceRelease,
            ]),
        ])->save();

        return $this->payload($run->refresh(), $contract, $blueprint, $contextPack, $controls, $harnessability, $scoring, $testRuns->count());
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function replay(AtlasEngineeringRun $sourceRun, array $options): array
    {
        $sourceRun->loadMissing(['task', 'testRuns', 'attempts', 'patchArtifacts', 'controlResults', 'reviewFindings']);
        $task = $sourceRun->task;
        if (! $task instanceof AtlasTask) {
            throw new \RuntimeException('Source engineering run is not attached to an Atlas task.');
        }

        $strategy = is_array($sourceRun->provider_strategy_json) ? $sourceRun->provider_strategy_json : [];
        $metadata = is_array($sourceRun->metadata) ? $sourceRun->metadata : [];
        $providerReplay = (bool) ($options['provider_replay'] ?? false);
        $sameSandbox = (bool) ($options['same_sandbox'] ?? false);
        $sourceRequestedSandbox = (string) (
            data_get($strategy, 'sandbox')
            ?? data_get($metadata, 'requested_sandbox_type')
            ?? 'workspace'
        );
        $sandbox = is_string($options['sandbox'] ?? null) && $options['sandbox'] !== ''
            ? (string) $options['sandbox']
            : ($sameSandbox ? $sourceRequestedSandbox : 'worktree');
        $autoTest = array_key_exists('auto_test', $options)
            ? (bool) $options['auto_test']
            : (bool) (data_get($strategy, 'effective_auto_test') ?? data_get($metadata, 'effective_auto_test') ?? true);
        $maxAttempts = array_key_exists('max_attempts', $options)
            ? (int) $options['max_attempts']
            : ($providerReplay ? (int) (data_get($strategy, 'requested_max_attempts') ?? data_get($strategy, 'effective_max_attempts') ?? 1) : 1);
        $replayMode = $providerReplay ? 'provider_replay' : 'sensor_replay';
        $providerSelection = $this->summary->replayProviderSelection($sourceRun, $options, $strategy);

        return $this->run($task, [
            'workspace' => $options['workspace'] ?? null,
            'provider' => $providerSelection['provider'],
            'model' => $providerSelection['model'],
            'model_policy' => $options['model_policy'] ?? data_get($strategy, 'model_policy.effective_policy', 'fixed'),
            'permission' => is_string($options['permission'] ?? null) && $options['permission'] !== ''
                ? $options['permission']
                : (data_get($strategy, 'requested_permission') ?: data_get($strategy, 'permission') ?: 'auto'),
            'sandbox' => $sandbox,
            'docker_service' => $options['docker_service'] ?? data_get($strategy, 'docker.docker_service'),
            'docker_image' => $options['docker_image'] ?? data_get($strategy, 'docker.docker_image'),
            'docker_workdir' => $options['docker_workdir'] ?? data_get($strategy, 'docker.docker_workdir'),
            'docker_cache' => $options['docker_cache'] ?? data_get($strategy, 'docker.docker_cache'),
            'docker_network' => $options['docker_network'] ?? data_get($strategy, 'docker.docker_network'),
            'docker_healthcheck_services' => $options['docker_healthcheck_services'] ?? data_get($strategy, 'docker.docker_healthcheck_services', []),
            'docker_healthcheck_timeout' => $options['docker_healthcheck_timeout'] ?? data_get($strategy, 'docker.docker_healthcheck_timeout'),
            'docker_artifact_paths' => $options['docker_artifact_paths'] ?? data_get($strategy, 'docker.docker_artifact_paths', []),
            'docker_artifact_max_files' => $options['docker_artifact_max_files'] ?? data_get($strategy, 'docker.docker_artifact_max_files'),
            'docker_artifact_max_bytes' => $options['docker_artifact_max_bytes'] ?? data_get($strategy, 'docker.docker_artifact_max_bytes'),
            'provider_runtime' => $options['provider_runtime'] ?? data_get($strategy, 'provider_runtime.provider_runtime', 'host'),
            'provider_docker_compose_file' => $options['provider_docker_compose_file'] ?? null,
            'provider_docker_service' => $options['provider_docker_service'] ?? data_get($strategy, 'provider_runtime.provider_docker_service'),
            'provider_docker_app_dir' => $options['provider_docker_app_dir'] ?? data_get($strategy, 'provider_runtime.provider_docker_app_dir'),
            'provider_docker_workspace_dir' => $options['provider_docker_workspace_dir'] ?? data_get($strategy, 'provider_runtime.provider_docker_workspace_dir'),
            'max_attempts' => $this->runnerInput()->maxAttempts($maxAttempts),
            'test_command' => is_string($options['test_command'] ?? null) && $options['test_command'] !== ''
                ? $options['test_command']
                : $this->support->sourceTestCommand($sourceRun),
            'visual_e2e' => $options['visual_e2e'] ?? data_get($strategy, 'visual_e2e', 'auto'),
            'quality_scan' => $options['quality_scan'] ?? data_get($strategy, 'quality_scan.mode', 'off'),
            'quality_profile' => $options['quality_profile'] ?? data_get($strategy, 'quality_scan.profile', 'auto'),
            'quality_changed_only' => array_key_exists('quality_changed_only', $options)
                ? (bool) $options['quality_changed_only']
                : (bool) data_get($strategy, 'quality_scan.changed_only', true),
            'harness_policy' => $options['harness_policy'] ?? data_get($strategy, 'harness_policy.mode', 'auto'),
            'control_profile' => $options['control_profile'] ?? data_get($metadata, 'control_profile'),
            'complete' => $providerReplay && (data_get($strategy, 'mode') === 'complete'),
            'auto_test' => $autoTest,
            'critical' => $providerReplay && (bool) ($options['critical'] ?? false),
            'dry_run' => false,
            'no_provider' => ! $providerReplay,
            'keep_workspace' => (bool) ($options['keep_workspace'] ?? false),
            'apply_isolated_patch' => (bool) ($options['apply_isolated_patch'] ?? false),
            'replay' => [
                'source_run_id' => $sourceRun->id,
                'scope' => $options['replay_scope'] ?? 'run',
                'mode' => $replayMode,
                'provider_replay' => $providerReplay,
                'same_sandbox' => $sameSandbox,
                'source_attempt_id' => $options['source_attempt_id'] ?? null,
                'source_attempt_number' => $options['source_attempt_number'] ?? null,
                'source_attempt_status' => $options['source_attempt_status'] ?? null,
                'source_attempt_phase' => $options['source_attempt_phase'] ?? null,
                'source_attempt_provider' => $options['source_attempt_provider'] ?? null,
                'source_attempt_model' => $options['source_attempt_model'] ?? null,
                'source_attempt_patch_hash' => $options['source_attempt_patch_hash'] ?? null,
                'source_recommended_attempt_id' => $providerSelection['attempt_id'],
                'source_recommended_attempt_number' => $providerSelection['attempt_number'],
                'source_recommended_attempt_score' => $providerSelection['attempt_score'],
                'source_recommended_attempt_model' => $providerSelection['model'],
                'replay_provider' => $providerSelection['provider'],
                'replay_provider_source' => $providerSelection['source'],
                'replay_model_policy' => $options['model_policy'] ?? data_get($strategy, 'model_policy.effective_policy', 'fixed'),
                'source_decision' => $sourceRun->decision,
                'source_score' => $sourceRun->score,
                'source_status' => $sourceRun->status,
                'source_context_pack_hash' => $sourceRun->context_pack_hash,
                'source_blueprint_id' => $sourceRun->blueprint_id,
                'source_workspace_path_hash' => $sourceRun->workspace_path_hash,
                'source_workspace_label' => $sourceRun->workspace_label,
                'source_head' => data_get($metadata, 'workspace_plan.head'),
                'source_provider_strategy_hash' => hash('sha256', json_encode($strategy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'),
                'requested_at' => now()->toJSON(),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function replayAttempt(AtlasEngineeringRunAttempt $sourceAttempt, array $options): array
    {
        $sourceAttempt->loadMissing(['run.task', 'run.testRuns']);
        $sourceRun = $sourceAttempt->run;
        if (! $sourceRun instanceof AtlasEngineeringRun) {
            throw new \RuntimeException('Source engineering attempt is not attached to an Atlas run.');
        }

        return $this->replay($sourceRun, array_merge($options, [
            'replay_scope' => 'attempt',
            'source_attempt_id' => $sourceAttempt->id,
            'source_attempt_number' => $sourceAttempt->attempt_number,
            'source_attempt_status' => $sourceAttempt->status,
            'source_attempt_phase' => $sourceAttempt->phase,
            'source_attempt_provider' => $sourceAttempt->provider,
            'source_attempt_model' => $sourceAttempt->model,
            'source_attempt_patch_hash' => $sourceAttempt->patch_hash,
        ]));
    }

    /**
     * @param  array<string,mixed>  $providerRun
     */
    public function syncProviderAttempts(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $seedAttempt,
        array $providerRun,
        ?string $provider,
    ): AtlasEngineeringRunAttempt {
        $decodedRuns = collect((array) data_get($providerRun, 'decoded.provider_runs', []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values();
        if ($decodedRuns->isEmpty()) {
            $decodedRuns = $this->provider->providerRunsFromTrace($providerRun);
        }

        if ($decodedRuns->isEmpty()) {
            $seedAttempt->forceFill([
                'trace_id' => $this->support->uuidOrNull($providerRun['trace_id'] ?? null),
                'status' => ((int) ($providerRun['exit_code'] ?? 1)) === 0 ? 'completed' : 'failed',
                'failure_summary' => ((int) ($providerRun['exit_code'] ?? 1)) === 0
                    ? null
                    : $this->support->failureSummary($providerRun),
                'finished_at' => now(),
                'metadata' => array_merge($seedAttempt->metadata ?? [], [
                    'provider_run' => $this->support->compactProviderPayload($providerRun),
                ]),
            ])->save();

            return $seedAttempt->refresh();
        }

        $selectedProvider = $provider
            ?: data_get($providerRun, 'decoded.workflow.selected_provider')
            ?: data_get($providerRun, 'decoded.provider');
        $selectedModel = data_get($providerRun, 'decoded.dev_execution_plan.selected_model.model')
            ?: data_get($providerRun, 'decoded.workflow.selected_model.model')
            ?: data_get($providerRun, 'decoded.dev_execution_plan.operator_options.model')
            ?: data_get($providerRun, 'decoded.model')
            ?: $seedAttempt->model;
        $lastAttempt = $seedAttempt;
        foreach ($decodedRuns as $index => $rawRun) {
            $attemptNumber = max(1, (int) ($rawRun['iteration'] ?? ($index + 1)));
            $exitCode = (int) ($rawRun['exit_code'] ?? 1);
            $attemptModel = is_string($rawRun['model'] ?? null) && trim((string) $rawRun['model']) !== ''
                ? trim((string) $rawRun['model'])
                : (is_string($selectedModel) && trim($selectedModel) !== '' ? trim($selectedModel) : null);
            $attempt = $attemptNumber === (int) $seedAttempt->attempt_number
                ? $seedAttempt
                : AtlasEngineeringRunAttempt::query()->firstOrNew([
                    'engineering_run_id' => $run->id,
                    'attempt_number' => $attemptNumber,
                ]);

            $attempt->forceFill([
                'engineering_run_id' => $run->id,
                'attempt_number' => $attemptNumber,
                'trace_id' => $this->support->uuidOrNull($rawRun['trace_id'] ?? null),
                'provider' => is_string($rawRun['provider'] ?? null) && trim((string) $rawRun['provider']) !== ''
                    ? trim((string) $rawRun['provider'])
                    : (is_string($selectedProvider) && $selectedProvider !== '' ? $selectedProvider : $provider),
                'model' => $attemptModel,
                'phase' => $attemptNumber === 1 ? 'edit' : 'repair',
                'prompt_hash' => $attempt->prompt_hash ?: $seedAttempt->prompt_hash,
                'input_summary_json' => $attempt->input_summary_json ?: $seedAttempt->input_summary_json,
                'status' => $exitCode === 0 ? 'completed' : 'failed',
                'failure_summary' => $exitCode === 0 ? null : $this->support->failureSummary($rawRun),
                'started_at' => $attempt->started_at ?: now(),
                'finished_at' => now(),
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'provider_run' => $this->support->compactSingleProviderRun($rawRun),
                    'dev_plan_id' => data_get($providerRun, 'decoded.dev_execution_plan.plan_id'),
                    'completion_status' => data_get($providerRun, 'decoded.completion.status'),
                ]),
            ])->save();

            $lastAttempt = $attempt->refresh();
        }

        return $lastAttempt;
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     * @return array<string,mixed>
     */
    private function payload(
        AtlasEngineeringRun $run,
        array $contract,
        array $blueprint,
        array $contextPack,
        array $controls,
        array $harnessability,
        array $scoring,
        int $testRunCount,
    ): array {
        $run->load(['attempts', 'patchArtifacts', 'controlResults', 'testRuns']);

        return [
            'ok' => in_array($run->decision, ['resolved', 'partial'], true),
            'run' => $this->runSummary($run),
            'contract' => $contract,
            'blueprint' => $blueprint,
            'context_pack' => [
                'id' => $contextPack['context_pack_id'] ?? null,
                'hash' => $contextPack['hash'] ?? null,
                'selected_files' => $contextPack['selected_files'] ?? [],
            ],
            'controls' => $controls,
            'harnessability' => $harnessability,
            'score' => $scoring,
            'test_run_count' => $testRunCount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runSummary(AtlasEngineeringRun $run): array
    {
        $run->loadMissing(['attempts', 'patchArtifacts', 'controlResults', 'testRuns', 'reviewFindings', 'operatorActions']);
        $openFindings = $run->reviewFindings->where('status', 'open');

        return [
            'id' => $run->id,
            'task_id' => $run->task_id,
            'status' => $run->status,
            'decision' => $run->decision,
            'score' => $run->score,
            'attempt_count' => $run->attempt_count,
            'context_pack_hash' => $run->context_pack_hash,
            'harnessability_score' => $run->harnessability_score,
            'autonomy_policy' => data_get($run->metadata, 'autonomy_policy'),
            'model_selection' => data_get($run->metadata, 'model_policy') ?: data_get($run->provider_strategy_json, 'model_policy'),
            'replay' => data_get($run->metadata, 'replay'),
            'fair_mode' => data_get($run->metadata, 'fair_mode'),
            'fair_mode_result' => data_get($run->metadata, 'fair_mode_result'),
            'scope_safety' => data_get($run->metadata, 'isolated_patch_apply'),
            'workspace' => [
                'mode' => data_get($run->metadata, 'workspace_plan.mode'),
                'status' => data_get($run->metadata, 'workspace_plan.status'),
                'isolated' => (bool) data_get($run->metadata, 'workspace_plan.isolated', false),
                'isolation_type' => data_get($run->metadata, 'workspace_plan.isolation_type'),
                'containerized_execution' => (bool) data_get($run->metadata, 'workspace_plan.containerized_execution', false),
                'fallback_reason' => data_get($run->metadata, 'workspace_plan.fallback_reason'),
                'release_status' => data_get($run->metadata, 'workspace_release.status'),
            ],
            'provider_runtime' => [
                'requested_runtime' => data_get($run->metadata, 'provider_runtime_plan.requested_runtime'),
                'runtime' => data_get($run->metadata, 'provider_runtime_plan.runtime'),
                'status' => data_get($run->metadata, 'provider_runtime_plan.status'),
                'fallback_reason' => data_get($run->metadata, 'provider_runtime_plan.fallback_reason'),
                'compose_file_hash' => data_get($run->metadata, 'provider_runtime_plan.compose_file_hash'),
                'service' => data_get($run->metadata, 'provider_runtime_plan.service'),
                'workspace_dir' => data_get($run->metadata, 'provider_runtime_plan.workspace_dir'),
            ],
            'docker_healthchecks' => [
                'status' => data_get($run->metadata, 'docker_healthchecks.status'),
                'reason' => data_get($run->metadata, 'docker_healthchecks.reason'),
                'service_count' => data_get($run->metadata, 'docker_healthchecks.service_count'),
                'timeout_seconds' => data_get($run->metadata, 'docker_healthchecks.timeout_seconds'),
            ],
            'docker_network_policy' => [
                'status' => data_get($run->metadata, 'docker_network_policy.status'),
                'mode' => data_get($run->metadata, 'docker_network_policy.mode'),
                'runtime' => data_get($run->metadata, 'docker_network_policy.runtime'),
                'enforced' => data_get($run->metadata, 'docker_network_policy.enforced'),
                'reason' => data_get($run->metadata, 'docker_network_policy.reason'),
            ],
            'attempts' => $run->attempts->map(fn ($attempt): array => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'phase' => $attempt->phase,
                'status' => $attempt->status,
                'trace_id' => $attempt->trace_id,
                'patch_hash' => $attempt->patch_hash,
                'diff_stat' => $attempt->diff_stat_json,
                'changed_files' => $attempt->changed_files_json,
                'failure_summary' => $attempt->failure_summary,
                'started_at' => $attempt->started_at?->toJSON(),
                'finished_at' => $attempt->finished_at?->toJSON(),
            ])->values()->all(),
            'attempt_comparison' => $this->summary->attemptComparison($run),
            'patch_artifacts' => $run->patchArtifacts->map(fn ($patch): array => [
                'id' => $patch->id,
                'attempt_id' => $patch->attempt_id,
                'base_ref' => $patch->base_ref,
                'head_ref' => $patch->head_ref,
                'diff_hash' => $patch->diff_hash,
                'diff_excerpt' => $patch->diff_excerpt,
                'diff_path' => $patch->diff_path,
                'integrity' => $this->summary->patchArtifactIntegrity($patch),
                'changed_files' => $patch->changed_files_json,
                'created_files' => $patch->created_files_json,
                'deleted_files' => $patch->deleted_files_json,
                'risk_flags' => $patch->risk_flags_json,
                'metadata' => $patch->metadata,
                'created_at' => $patch->created_at?->toJSON(),
            ])->values()->all(),
            'control_results' => $run->controlResults->map(fn ($result): array => [
                'id' => $result->id,
                'attempt_id' => $result->attempt_id,
                'control_id' => $result->control_id,
                'control_slug' => $result->control_slug,
                'control_definition_hash' => $result->control_definition_hash,
                'control_version' => $result->control_version,
                'status' => $result->status,
                'summary' => $result->signal_summary,
                'output_excerpt' => $result->output_excerpt,
                'duration_ms' => $result->duration_ms,
                'artifact_path' => $result->artifact_path,
                'metadata' => $result->metadata,
                'created_at' => $result->created_at?->toJSON(),
            ])->values()->all(),
            'test_runs' => $run->testRuns->map(fn ($testRun): array => [
                'id' => $testRun->id,
                'attempt_id' => $testRun->attempt_id,
                'test_case_id' => $testRun->test_case_id,
                'command' => $testRun->command,
                'status' => $testRun->status,
                'exit_code' => $testRun->exit_code,
                'duration_ms' => $testRun->duration_ms,
                'stdout_excerpt' => $testRun->stdout_excerpt,
                'stderr_excerpt' => $testRun->stderr_excerpt,
                'type' => data_get($testRun->metadata, 'test_case_type'),
                'source' => data_get($testRun->metadata, 'test_case_source'),
                'artifact_path' => $testRun->artifact_path,
                'metadata' => $testRun->metadata,
                'visual_artifact_export' => data_get($testRun->metadata, 'visual_artifact_export'),
                'visual_e2e' => data_get($testRun->metadata, 'visual_e2e'),
                'visual_smoke' => data_get($testRun->metadata, 'visual_smoke'),
                'quality_scan' => data_get($testRun->metadata, 'quality_scan'),
                'quality_scan_result' => data_get($testRun->metadata, 'quality_scan_result'),
                'quality_artifact_export' => data_get($testRun->metadata, 'quality_artifact_export'),
                'created_at' => $testRun->created_at?->toJSON(),
            ])->values()->all(),
            'review_summary' => [
                'open_count' => $openFindings->count(),
                'blocking_count' => $openFindings->whereIn('severity', ['p0', 'p1'])->count(),
                'by_severity' => $openFindings->groupBy('severity')->map->count()->all(),
            ],
            'review_findings' => $run->reviewFindings
                ->sortBy(fn ($finding): string => $finding->status.':'.$finding->severity.':'.$finding->created_at)
                ->take(30)
                ->map(fn ($finding): array => [
                    'id' => $finding->id,
                    'severity' => $finding->severity,
                    'status' => $finding->status,
                    'source' => $finding->source,
                    'title' => $finding->title,
                    'body' => $finding->body,
                    'file_path' => $finding->file_path,
                    'start_line' => $finding->start_line,
                    'end_line' => $finding->end_line,
                    'evidence' => $finding->evidence_json,
                    'resolution' => $finding->resolution_json,
                    'detected_at' => $finding->detected_at?->toJSON(),
                    'resolved_at' => $finding->resolved_at?->toJSON(),
                    'created_at' => $finding->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'operator_actions' => $run->operatorActions
                ->take(20)
                ->map(fn ($action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    'actor' => $action->actor,
                    'status_before' => $action->status_before,
                    'decision_before' => $action->decision_before,
                    'status_after' => $action->status_after,
                    'decision_after' => $action->decision_after,
                    'note' => $action->note,
                    'payload' => $action->payload_json,
                    'acted_at' => $action->acted_at?->toJSON(),
                    'created_at' => $action->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'timeline' => $this->summary->timeline($run),
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $harnessability
     * @param  array<string,mixed>  $requested
     * @return array<string,mixed>
     */
    private function autonomyPolicy(array $harnessability, array $requested): array
    {
        $mode = $this->options->harnessPolicyMode($requested['mode'] ?? 'auto');
        $score = max(0, min(100, (int) ($harnessability['score'] ?? 0)));
        $level = (string) ($harnessability['level'] ?? 'low');
        $providerExecuted = ! (bool) ($requested['dry_run'] ?? false) && ! (bool) ($requested['no_provider'] ?? false);
        $forceSandboxWithoutProvider = (bool) ($requested['force_sandbox_without_provider'] ?? false);
        $permission = $this->options->permissionMode($requested['permission'] ?? 'auto');
        $sandbox = $this->options->sandboxMode($requested['sandbox'] ?? 'workspace');
        $maxAttempts = $this->runnerInput()->maxAttempts($requested['max_attempts'] ?? null);
        $autoTest = (bool) ($requested['auto_test'] ?? false);
        $actions = [];
        $reasons = [];
        $testCommands = array_values((array) ($harnessability['test_commands'] ?? []));
        $thresholds = $this->options->harnessabilityThresholds($harnessability);
        $requireWorktreeBelow = (int) $thresholds['require_worktree_below_score'];
        $dangerPermissionMin = (int) $thresholds['danger_permission_min_score'];
        $writePermissionMin = (int) $thresholds['write_permission_min_score'];
        $capAttemptsToOneBelow = (int) $thresholds['cap_attempts_to_one_below_score'];
        $capAttemptsToTwoBelow = (int) $thresholds['cap_attempts_to_two_below_score'];
        $requireAutoTestBelow = (int) $thresholds['require_auto_test_below_score'];

        $effectivePermission = $permission;
        $effectiveSandbox = ($providerExecuted || $forceSandboxWithoutProvider) ? $sandbox : 'workspace';
        $effectiveMaxAttempts = $maxAttempts;
        $effectiveAutoTest = $autoTest;
        $sandboxReason = ($providerExecuted || $forceSandboxWithoutProvider) ? null : 'provider_not_executed';

        if ($mode !== 'off') {
            if ($providerExecuted && $sandbox === 'workspace' && ($score < $requireWorktreeBelow || $mode === 'strict')) {
                $effectiveSandbox = 'worktree';
                $sandboxReason = $score < $requireWorktreeBelow ? 'harnessability_requires_isolation' : 'strict_policy_requires_isolation';
                $actions[] = 'sandbox:workspace->worktree';
                $reasons[] = $sandboxReason;
            }

            if ($permission === 'danger' && ($score < $dangerPermissionMin || $mode === 'strict')) {
                $effectivePermission = 'write';
                $actions[] = 'permission:danger->write';
                $reasons[] = 'danger_permission_requires_high_harnessability';
            }

            if ($permission === 'write' && $mode === 'strict' && $score < $writePermissionMin) {
                $effectivePermission = 'auto';
                $actions[] = 'permission:write->auto';
                $reasons[] = 'strict_policy_caps_write_on_low_harnessability';
            }

            if ($maxAttempts > 1 && ($score < $capAttemptsToOneBelow || ($mode === 'strict' && $score < $capAttemptsToTwoBelow))) {
                $effectiveMaxAttempts = $score < $capAttemptsToOneBelow ? 1 : min($maxAttempts, 2);
                $actions[] = 'max_attempts:'.$maxAttempts.'->'.$effectiveMaxAttempts;
                $reasons[] = 'repair_loop_capped_by_harnessability';
            }

            if (! $autoTest && $testCommands !== [] && ($score < $requireAutoTestBelow || $mode === 'strict')) {
                $effectiveAutoTest = true;
                $actions[] = 'auto_test:false->true';
                $reasons[] = 'detected_tests_required_by_harnessability';
            }
        }

        return [
            'mode' => $mode,
            'score' => $score,
            'level' => $level,
            'status' => $actions === [] ? 'unchanged' : 'adjusted',
            'requested_permission' => $permission,
            'effective_permission' => $effectivePermission,
            'requested_sandbox' => $sandbox,
            'effective_sandbox' => $effectiveSandbox,
            'requested_max_attempts' => $maxAttempts,
            'effective_max_attempts' => $effectiveMaxAttempts,
            'requested_auto_test' => $autoTest,
            'effective_auto_test' => $effectiveAutoTest,
            'provider_executed' => $providerExecuted,
            'force_sandbox_without_provider' => $forceSandboxWithoutProvider,
            'sandbox_reason' => $sandboxReason,
            'thresholds' => $thresholds,
            'actions' => array_values(array_unique($actions)),
            'reasons' => array_values(array_unique($reasons)),
            'missing' => array_values((array) ($harnessability['missing'] ?? [])),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,bool>
     */
    private function fairModeOptions(array $options): array
    {
        $claudeOnly = (bool) ($options['claude_only'] ?? false);
        $singleProvider = (bool) ($options['single_provider'] ?? false) || $claudeOnly;
        $noDecide = (bool) ($options['no_decide'] ?? false) || $claudeOnly;
        $fallbackDisabled = (bool) ($options['fallback_disabled'] ?? false) || $claudeOnly;
        $fairMode = (bool) ($options['fair_mode'] ?? false)
            || $claudeOnly
            || $singleProvider
            || $noDecide
            || $fallbackDisabled;

        return [
            'fair_mode' => $fairMode,
            'claude_only' => $claudeOnly,
            'single_provider' => $fairMode,
            'no_decide' => $fairMode,
            'fallback_disabled' => $fairMode,
            'require_pass_without_human' => $fairMode,
        ];
    }

    /**
     * @param  array<string,mixed>  $fairMode
     * @return array{0:?string,1:?string,2:string}
     */
    private function fairProviderRequest(?string $provider, ?string $model, string $modelPolicy, array $fairMode): array
    {
        if (! (bool) ($fairMode['fair_mode'] ?? false)) {
            return [$provider, $model, $modelPolicy];
        }

        if ($provider !== null && $provider !== FairClaudePolicy::PROVIDER_LOCK) {
            throw new \InvalidArgumentException(FairClaudePolicy::ERROR_CODE.': Fair Claude benchmark requires provider '.FairClaudePolicy::PROVIDER_LOCK.'.');
        }
        if ($model !== null && ! $this->isFairClaudeModelLock($model)) {
            throw new \InvalidArgumentException(FairClaudePolicy::ERROR_CODE.': Fair Claude benchmark requires Claude Opus model.');
        }

        return [
            FairClaudePolicy::PROVIDER_LOCK,
            $model ?: FairClaudePolicy::MODEL_LOCK,
            'fixed',
        ];
    }

    private function isFairClaudeModelLock(string $model): bool
    {
        $model = strtolower(trim($model));
        $configured = strtolower(trim((string) config('atlas.ai.providers.claude_cli.premium_model', '')));

        return $model === FairClaudePolicy::MODEL_LOCK
            || ($configured !== '' && $model === $configured);
    }

    private function runnerInput(): EngineeringHarnessRunnerInput
    {
        return $this->input ?? app(EngineeringHarnessRunnerInput::class);
    }
}
