<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Tools\AtlasToolGateService;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class EngineeringHarnessRunnerService
{
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
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(AtlasTask $task, array $options): array
    {
        $workspace = $this->workspace($options['workspace'] ?? getcwd());
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $noProvider = (bool) ($options['no_provider'] ?? false);
        $requestedAutoTest = (bool) ($options['auto_test'] ?? false);
        $requestedMaxAttempts = max(1, min(10, (int) ($options['max_attempts'] ?? 1)));
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
        $visualE2e = $this->visualE2eMode($options['visual_e2e'] ?? config('atlas.engineering.visual_e2e.mode', 'auto'));
        $qualityScan = $this->qualityScanMode($options['quality_scan'] ?? config('atlas.engineering.quality_scan.mode', 'off'));
        $qualityProfile = $this->qualityScanProfile($options['quality_profile'] ?? config('atlas.engineering.quality_scan.profile', 'auto'));
        $qualityChangedOnly = array_key_exists('quality_changed_only', $options)
            ? (bool) $options['quality_changed_only']
            : (bool) config('atlas.engineering.quality_scan.changed_only', true);
        $dockerOptions = $this->dockerOptions($options);
        $providerRuntimeOptions = $this->providerRuntimeOptions($options);
        $replay = is_array($options['replay'] ?? null) ? $options['replay'] : null;

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
            ],
        ]);

        $this->recordAutonomyPolicyControl($run->refresh(), $autonomyPolicy);
        $this->recordModelSelectionControl($run->refresh(), $modelSelection);
        $this->recordReplayControl($run->refresh(), $replay);
        $workspacePlan = $this->workspaces->prepare($workspace, $run, array_merge(['sandbox' => $effectiveSandbox], $dockerOptions));
        $executionWorkspace = (string) ($workspacePlan['execution_workspace'] ?? $workspace);
        $providerRuntimePlan = ($dryRun || $noProvider)
            ? $this->skippedProviderRuntimePlan($providerRuntimeOptions)
            : $this->providerRuntimes->plan($workspacePlan, $providerRuntimeOptions);
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'workspace_plan' => $this->compactWorkspacePlan($workspacePlan),
                'provider_runtime_plan' => $this->compactProviderRuntimePlan($providerRuntimePlan),
            ]),
        ])->save();
        $this->recordWorkspacePlanControl($run->refresh(), $workspacePlan);
        $dockerNetworkPolicy = $this->dockerHarness->networkPolicyStatus($workspacePlan);
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'docker_network_policy' => $this->compactDockerNetworkPolicy($dockerNetworkPolicy),
            ]),
        ])->save();
        $this->recordDockerNetworkControl($run->refresh(), $dockerNetworkPolicy, $workspacePlan);
        $this->recordProviderRuntimeControl($run->refresh(), $providerRuntimePlan);

        $contextPack = $this->contextPacks->build($task, $run, $executionWorkspace, $contract, $blueprint, $controls);
        $this->recordPrepareControls($run, $controls, $contract, $blueprint);

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
                'provider_runtime_plan' => $this->compactProviderRuntimePlan($providerRuntimePlan),
            ],
        ]);

        $providerRun = null;
        if (! $dryRun && ! $noProvider) {
            $providerRun = $this->runProvider($task, $executionWorkspace, [
                'provider' => $provider,
                'model' => $model,
                'permission' => $permission,
                'complete' => (bool) ($options['complete'] ?? false),
                'max_attempts' => $maxAttempts,
                'critical' => (bool) ($options['critical'] ?? false),
            ], $workspacePlan, $providerRuntimePlan);

            $attempt = $this->syncProviderAttempts($run->refresh(), $attempt, $providerRun, $provider);
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
        $this->recordPostAttemptControls($run->refresh(), $attempt->refresh(), $controls, $patch);
        $this->recordChangedFilesScopeControl($run->refresh(), $attempt->refresh(), $contract, $blueprint, $patch);
        $dockerHealthchecks = $autoTest
            ? $this->dockerHarness->runHealthchecks($workspacePlan)
            : ['status' => 'skipped', 'reason' => 'auto_test_disabled'];
        $run->forceFill([
            'metadata' => array_merge($run->metadata ?? [], [
                'docker_healthchecks' => $this->compactDockerHealthchecks($dockerHealthchecks),
            ]),
        ])->save();
        $this->recordDockerHealthcheckControl($run->refresh(), $dockerHealthchecks, $workspacePlan);

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
            ],
        );
        $testRuns = $autoTest && (($dockerHealthchecks['status'] ?? null) !== 'failed')
            ? $this->testMatrix->runCases($run->refresh(), $attempt->refresh(), $executionWorkspace, $cases, true, [
                'workspace_plan' => $workspacePlan,
            ])
            : collect();
        $this->recordToolRuntimeGateControl($run->refresh(), $attempt->refresh(), $executionWorkspace, [
            'quality_scan' => $qualityScan,
            'quality_profile' => $qualityProfile,
        ]);
        $this->recordVisualToolRuntimeGateControl($run->refresh(), $attempt->refresh(), $executionWorkspace, $visualE2e, $testRuns);
        $this->recordSkippedRequiredControls($run->refresh(), $attempt->refresh(), $controls);
        if ($providerRun !== null) {
            $this->recordProviderReviewFindings($run->refresh(), $attempt->refresh(), $providerRun);
        }

        $preliminaryScoring = $this->scoring->score($run->refresh(), $contract, $blueprint);
        $patchApply = $this->applyIsolatedPatch(
            run: $run->refresh(),
            attempt: $attempt->refresh(),
            workspacePlan: $workspacePlan,
            patch: $patch,
            scoring: $preliminaryScoring,
            enabled: $applyIsolatedPatch,
        );

        $scoring = $this->scoring->score($run->refresh(), $contract, $blueprint);
        $run->forceFill([
            'status' => $this->statusForDecision((string) $scoring['decision']),
            'decision' => $scoring['decision'],
            'score' => $scoring['score'],
            'attempt_count' => $run->attempts()->count(),
            'trace_id' => $attempt->trace_id ?: ($providerRun['trace_id'] ?? null),
            'finished_at' => now(),
            'metadata' => array_merge($run->metadata ?? [], [
                'score_components' => $scoring['components'] ?? [],
                'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
                'provider_run' => $providerRun ? $this->compactProviderPayload($providerRun) : null,
                'isolated_patch_apply' => $patchApply,
            ]),
        ])->save();

        $this->recordEvidence($task->refresh(), $run->refresh(), $scoring, $patch);
        $this->persistTaskSummary($task->refresh(), $run->refresh(), $scoring);
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
        $providerSelection = $this->replayProviderSelection($sourceRun, $options, $strategy);

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
            'max_attempts' => max(1, min(10, $maxAttempts)),
            'test_command' => is_string($options['test_command'] ?? null) && $options['test_command'] !== ''
                ? $options['test_command']
                : $this->sourceTestCommand($sourceRun),
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
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $strategy
     * @return array{provider:?string,source:string,attempt_id:?string,attempt_number:?int,attempt_score:?int,model:?string}
     */
    private function replayProviderSelection(AtlasEngineeringRun $sourceRun, array $options, array $strategy): array
    {
        $modelOverride = is_string($options['model'] ?? null) && trim((string) $options['model']) !== ''
            ? trim((string) $options['model'])
            : null;
        $useModelPolicy = $this->replayUsesModelPolicy($options);

        if (is_string($options['provider'] ?? null) && trim((string) $options['provider']) !== '') {
            return [
                'provider' => trim((string) $options['provider']),
                'source' => 'operator_override',
                'attempt_id' => null,
                'attempt_number' => null,
                'attempt_score' => null,
                'model' => $modelOverride,
            ];
        }

        if (is_string($options['source_attempt_provider'] ?? null) && trim((string) $options['source_attempt_provider']) !== '') {
            return [
                'provider' => trim((string) $options['source_attempt_provider']),
                'source' => 'source_attempt',
                'attempt_id' => is_string($options['source_attempt_id'] ?? null) ? $options['source_attempt_id'] : null,
                'attempt_number' => is_numeric($options['source_attempt_number'] ?? null) ? (int) $options['source_attempt_number'] : null,
                'attempt_score' => null,
                'model' => $modelOverride ?: ($useModelPolicy ? null : (is_string($options['source_attempt_model'] ?? null) ? $options['source_attempt_model'] : null)),
            ];
        }

        $comparison = $this->attemptComparison($sourceRun);
        $bestAttemptId = is_string($comparison['best_attempt_id'] ?? null) ? $comparison['best_attempt_id'] : null;
        $bestAttempt = $bestAttemptId
            ? $sourceRun->attempts->first(fn (AtlasEngineeringRunAttempt $attempt): bool => $attempt->id === $bestAttemptId)
            : null;
        $bestProvider = $bestAttempt instanceof AtlasEngineeringRunAttempt && is_string($bestAttempt->provider) && trim($bestAttempt->provider) !== ''
            ? trim($bestAttempt->provider)
            : null;

        if ($bestProvider !== null) {
            return [
                'provider' => $bestProvider,
                'source' => 'attempt_comparison',
                'attempt_id' => $bestAttempt->id,
                'attempt_number' => $bestAttempt->attempt_number,
                'attempt_score' => is_numeric($comparison['best_score'] ?? null) ? (int) $comparison['best_score'] : null,
                'model' => $modelOverride ?: ($useModelPolicy ? null : (is_string($bestAttempt->model) ? $bestAttempt->model : null)),
            ];
        }

        $strategyProvider = data_get($strategy, 'provider');
        $strategyModel = data_get($strategy, 'model');

        return [
            'provider' => is_string($strategyProvider) && trim($strategyProvider) !== '' ? trim($strategyProvider) : null,
            'source' => 'source_strategy',
            'attempt_id' => null,
            'attempt_number' => null,
            'attempt_score' => null,
            'model' => $modelOverride ?: ($useModelPolicy ? null : (is_string($strategyModel) && trim($strategyModel) !== '' ? trim($strategyModel) : null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function replayUsesModelPolicy(array $options): bool
    {
        $policy = is_string($options['model_policy'] ?? null)
            ? strtolower(str_replace('-', '_', trim((string) $options['model_policy'])))
            : 'fixed';

        return ! in_array($policy, ['', 'fixed', 'off'], true);
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
            $seedAttempt->forceFill([
                'trace_id' => $this->uuidOrNull($providerRun['trace_id'] ?? null),
                'status' => ((int) ($providerRun['exit_code'] ?? 1)) === 0 ? 'completed' : 'failed',
                'failure_summary' => ((int) ($providerRun['exit_code'] ?? 1)) === 0
                    ? null
                    : $this->failureSummary($providerRun),
                'finished_at' => now(),
                'metadata' => array_merge($seedAttempt->metadata ?? [], [
                    'provider_run' => $this->compactProviderPayload($providerRun),
                ]),
            ])->save();

            return $seedAttempt->refresh();
        }

        $selectedProvider = $provider ?: data_get($providerRun, 'decoded.workflow.selected_provider');
        $selectedModel = data_get($providerRun, 'decoded.dev_execution_plan.selected_model.model')
            ?: data_get($providerRun, 'decoded.workflow.selected_model.model')
            ?: data_get($providerRun, 'decoded.dev_execution_plan.operator_options.model')
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
                'trace_id' => $this->uuidOrNull($rawRun['trace_id'] ?? null),
                'provider' => is_string($selectedProvider) && $selectedProvider !== '' ? $selectedProvider : $provider,
                'model' => $attemptModel,
                'phase' => $attemptNumber === 1 ? 'edit' : 'repair',
                'prompt_hash' => $attempt->prompt_hash ?: $seedAttempt->prompt_hash,
                'input_summary_json' => $attempt->input_summary_json ?: $seedAttempt->input_summary_json,
                'status' => $exitCode === 0 ? 'completed' : 'failed',
                'failure_summary' => $exitCode === 0 ? null : $this->failureSummary($rawRun),
                'started_at' => $attempt->started_at ?: now(),
                'finished_at' => now(),
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'provider_run' => $this->compactSingleProviderRun($rawRun),
                    'dev_plan_id' => data_get($providerRun, 'decoded.dev_execution_plan.plan_id'),
                    'completion_status' => data_get($providerRun, 'decoded.completion.status'),
                ]),
            ])->save();

            $lastAttempt = $attempt->refresh();
        }

        return $lastAttempt;
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function recordAutonomyPolicyControl(AtlasEngineeringRun $run, array $policy): void
    {
        $actions = (array) ($policy['actions'] ?? []);
        $status = $actions === [] ? 'passed' : 'warning';

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'harnessability_autonomy_policy',
                'name' => 'Harnessability autonomy policy',
                'direction' => 'feedforward',
                'execution_type' => 'mixed',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => false,
                'failure_policy' => 'advisory',
            ],
            status: $status,
            summary: $actions === []
                ? 'Autonomia mantida pelo score de harnessability.'
                : 'Autonomia ajustada pelo score de harnessability: '.implode(', ', array_map('strval', $actions)),
            metadata: [
                'required' => false,
                'policy' => $policy,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $selection
     */
    private function recordModelSelectionControl(AtlasEngineeringRun $run, array $selection): void
    {
        $statusValue = (string) ($selection['status'] ?? 'skipped');
        $selectedModel = is_string($selection['selected_model'] ?? null) && trim((string) $selection['selected_model']) !== ''
            ? trim((string) $selection['selected_model'])
            : null;
        $summary = $selectedModel
            ? 'Modelo selecionado pelo Harness: '.$selectedModel.' via '.(string) ($selection['source'] ?? 'unknown').'.'
            : 'Selecao automatica de modelo nao aplicada: '.(string) ($selection['reason'] ?? 'fixed_default');

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'model_selection_policy',
                'name' => 'Model selection policy',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_quality',
                'timing' => 'prepare',
                'required' => false,
                'failure_policy' => 'advisory',
            ],
            status: $statusValue === 'selected' ? 'passed' : 'skipped',
            summary: $summary,
            metadata: [
                'required' => false,
                'selection' => $selection,
            ],
        );
    }

    /**
     * @param  array<string,mixed>|null  $replay
     */
    private function recordReplayControl(AtlasEngineeringRun $run, ?array $replay): void
    {
        if (! $replay) {
            return;
        }

        $providerReplay = (bool) ($replay['provider_replay'] ?? false);
        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'harness_replay_contract',
                'name' => 'Harness replay contract',
                'direction' => 'feedforward',
                'execution_type' => 'mixed',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => true,
                'failure_policy' => 'blocks_resolved',
            ],
            status: 'passed',
            summary: $providerReplay
                ? 'Replay controlado vai reexecutar provider em sandbox auditavel.'
                : 'Replay controlado em modo sensores, sem reexecutar provider.',
            metadata: array_merge($replay, ['required' => true]),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     */
    private function recordPrepareControls(AtlasEngineeringRun $run, array $controls, array $contract, array $blueprint): void
    {
        foreach ($controls as $control) {
            if (($control['timing'] ?? null) !== 'prepare') {
                continue;
            }

            $slug = (string) ($control['slug'] ?? '');
            $passed = match ($slug) {
                'engineering_task_contract' => trim((string) ($contract['goal'] ?? '')) !== '',
                'engineering_blueprint_snapshot' => trim((string) ($blueprint['blueprint_id'] ?? '')) !== '',
                default => true,
            };

            $this->controlRegistry->recordResult(
                run: $run,
                attempt: null,
                control: $control,
                status: $passed ? 'passed' : 'failed',
                summary: $passed ? 'Guide preparado: '.$slug : 'Guide incompleto: '.$slug,
                metadata: ['required' => (bool) ($control['required'] ?? false)],
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     */
    private function recordPostAttemptControls(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $controls,
        mixed $patch,
    ): void {
        foreach ($controls as $control) {
            $slug = (string) ($control['slug'] ?? '');
            if (! in_array($slug, ['git_status_snapshot', 'patch_artifact'], true)) {
                continue;
            }

            $dirtyCount = count((array) ($patch?->changed_files_json ?? []));
            $summary = $slug === 'git_status_snapshot'
                ? "Git status capturado com {$dirtyCount} arquivo(s) alterado(s)."
                : ($patch?->diff_hash ? 'Patch artifact capturado.' : 'Patch artifact capturado sem diff rastreavel.');

            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'passed',
                summary: $summary,
                outputExcerpt: $patch?->diff_excerpt,
                metadata: [
                    'required' => (bool) ($control['required'] ?? false),
                    'dirty_count' => $dirtyCount,
                    'diff_hash' => $patch?->diff_hash,
                ],
            );
        }
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     */
    private function recordChangedFilesScopeControl(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $contract,
        array $blueprint,
        mixed $patch,
    ): void {
        $changedFiles = $this->normalizedFileList((array) ($patch?->changed_files_json ?? []));
        $scope = $this->changedFilesScope($contract, $blueprint);
        $declaredScope = $scope['entries'];
        $strict = (bool) $scope['strict'];
        $outsideScope = $declaredScope === []
            ? []
            : collect($changedFiles)
                ->reject(fn (string $file): bool => $this->pathMatchesScope($file, $declaredScope))
                ->values()
                ->all();

        $required = $strict && $declaredScope !== [];
        $status = match (true) {
            $changedFiles === [] => 'passed',
            $declaredScope === [] => 'warning',
            $outsideScope === [] => 'passed',
            $strict => 'failed',
            default => 'warning',
        };
        $summary = match ($status) {
            'passed' => $changedFiles === []
                ? 'Nenhum arquivo alterado para validar contra o escopo.'
                : 'Arquivos alterados respeitam o escopo declarado.',
            'failed' => 'Diff alterou arquivo(s) fora do escopo estrito declarado.',
            default => $declaredScope === []
                ? 'Diff possui arquivos alterados, mas o contrato nao declarou escopo de arquivos.'
                : 'Diff alterou arquivo(s) fora da lista provavel; revisao de escopo recomendada.',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: [
                'slug' => 'changed_files_scope_policy',
                'name' => 'Changed files scope policy',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'post_attempt',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $status,
            summary: $summary,
            metadata: [
                'required' => $required,
                'strict' => $strict,
                'scope_source' => $scope['source'],
                'declared_scope' => $declaredScope,
                'changed_files' => $changedFiles,
                'outside_scope' => $outsideScope,
                'outside_scope_count' => count($outsideScope),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array{entries:array<int,string>,strict:bool,source:string}
     */
    private function changedFilesScope(array $contract, array $blueprint): array
    {
        $fileScope = is_array($contract['file_scope'] ?? null) ? $contract['file_scope'] : [];
        $scopePolicy = is_array($contract['scope_policy'] ?? null) ? $contract['scope_policy'] : [];
        $blueprintScope = is_array($blueprint['file_scope'] ?? null) ? $blueprint['file_scope'] : [];
        $explicitScope = $this->normalizedFileList(array_merge(
            (array) ($contract['allowed_files'] ?? []),
            (array) ($contract['allowed_paths'] ?? []),
            (array) ($fileScope['allowed_files'] ?? []),
            (array) ($fileScope['allowed_paths'] ?? []),
            (array) ($scopePolicy['allowed_files'] ?? []),
            (array) ($scopePolicy['allowed_paths'] ?? []),
            (array) ($blueprintScope['allowed_files'] ?? []),
            (array) ($blueprintScope['allowed_paths'] ?? []),
        ));
        $likelyScope = $this->normalizedFileList(array_merge(
            (array) ($contract['likely_files'] ?? []),
            (array) ($contract['files'] ?? []),
            (array) ($contract['target_files'] ?? []),
            (array) ($fileScope['likely_files'] ?? []),
            (array) ($scopePolicy['likely_files'] ?? []),
            (array) ($blueprintScope['likely_files'] ?? []),
        ));
        $strict = $explicitScope !== []
            || (bool) ($contract['strict_file_scope'] ?? false)
            || (bool) ($fileScope['strict'] ?? false)
            || in_array((string) ($fileScope['mode'] ?? ''), ['strict', 'allowlist'], true)
            || in_array((string) ($scopePolicy['mode'] ?? ''), ['strict', 'allowlist'], true)
            || in_array((string) ($scopePolicy['changed_files'] ?? ''), ['strict', 'allowlist'], true);

        if ($explicitScope !== []) {
            return ['entries' => $explicitScope, 'strict' => true, 'source' => 'explicit_allowed_scope'];
        }

        if ($likelyScope !== []) {
            return ['entries' => $likelyScope, 'strict' => $strict, 'source' => 'likely_files'];
        }

        return ['entries' => [], 'strict' => false, 'source' => 'none'];
    }

    /**
     * @param  array<int,mixed>  $files
     * @return array<int,string>
     */
    private function normalizedFileList(array $files): array
    {
        return collect($files)
            ->filter(fn (mixed $file): bool => is_scalar($file) && trim((string) $file) !== '')
            ->map(function (mixed $file): string {
                $path = str_replace('\\', '/', trim((string) $file));
                $path = preg_replace('#/+#', '/', $path) ?: $path;
                $path = preg_replace('#^\./#', '', $path) ?: $path;

                return ltrim(trim($path), '/');
            })
            ->filter(fn (string $file): bool => $file !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $scope
     */
    private function pathMatchesScope(string $file, array $scope): bool
    {
        $file = $this->normalizedFileList([$file])[0] ?? '';
        if ($file === '') {
            return false;
        }

        foreach ($scope as $entry) {
            $entry = $this->normalizedFileList([$entry])[0] ?? '';
            if ($entry === '') {
                continue;
            }

            if ($entry === $file) {
                return true;
            }

            if (str_ends_with($entry, '/*')) {
                $entry = substr($entry, 0, -1);
            }

            if (str_ends_with($entry, '/') && str_starts_with($file, $entry)) {
                return true;
            }

            if (str_contains($entry, '*') && fnmatch($entry, $file, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     */
    private function recordWorkspacePlanControl(AtlasEngineeringRun $run, array $workspacePlan): void
    {
        $requested = (string) ($workspacePlan['requested_mode'] ?? $workspacePlan['mode'] ?? 'workspace');
        if (! in_array($requested, ['worktree', 'docker'], true)) {
            return;
        }

        $dockerRequested = $requested === 'docker';
        $ready = $dockerRequested
            ? (bool) ($workspacePlan['containerized_execution'] ?? false)
            : (bool) ($workspacePlan['isolated'] ?? false);
        $status = $ready ? 'passed' : ($dockerRequested ? 'failed' : 'warning');
        $reason = (string) ($workspacePlan['fallback_reason'] ?? 'workspace_plan_not_ready');

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => $dockerRequested ? 'docker_harness_profile' : 'workspace_isolation',
                'name' => $dockerRequested ? 'Docker harness profile' : 'Workspace isolation',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => $dockerRequested,
                'failure_policy' => $dockerRequested ? 'blocks_resolved' : 'advisory',
            ],
            status: $status,
            summary: $ready
                ? ($dockerRequested ? 'Docker harness pronto para testes containerizados.' : 'Workspace isolado preparado.')
                : ($dockerRequested ? 'Docker harness indisponivel: '.$reason : 'Workspace isolado indisponivel: '.$reason),
            metadata: [
                'required' => $dockerRequested,
                'requested_mode' => $requested,
                'mode' => $workspacePlan['mode'] ?? null,
                'status' => $workspacePlan['status'] ?? null,
                'fallback_reason' => $workspacePlan['fallback_reason'] ?? null,
                'isolation_type' => $workspacePlan['isolation_type'] ?? null,
                'containerized_execution' => (bool) ($workspacePlan['containerized_execution'] ?? false),
                'docker' => $this->compactDockerPlan((array) ($workspacePlan['docker'] ?? [])),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $providerRuntimePlan
     */
    private function recordProviderRuntimeControl(AtlasEngineeringRun $run, array $providerRuntimePlan): void
    {
        $requested = (string) ($providerRuntimePlan['requested_runtime'] ?? 'host');
        $runtime = (string) ($providerRuntimePlan['runtime'] ?? 'host');
        $statusValue = (string) ($providerRuntimePlan['status'] ?? 'ready');
        if ($requested === 'host' && $runtime === 'host') {
            return;
        }
        if ($statusValue === 'skipped') {
            return;
        }

        $required = (bool) ($providerRuntimePlan['required'] ?? false);
        $ready = $runtime === 'docker' && $statusValue === 'ready';
        $status = $ready ? 'passed' : ($required ? 'failed' : 'warning');
        $reason = (string) ($providerRuntimePlan['fallback_reason'] ?? 'provider_runtime_not_ready');

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'provider_runtime_isolation',
                'name' => 'Provider runtime isolation',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'prepare',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $status,
            summary: $ready
                ? 'Provider runtime Docker pronto para executar atlas:cli:dev.'
                : 'Provider runtime Docker indisponivel: '.$reason,
            metadata: array_merge($this->compactProviderRuntimePlan($providerRuntimePlan), [
                'required' => $required,
            ]),
        );
    }

    /**
     * @param  array<string,mixed>  $networkPolicy
     * @param  array<string,mixed>  $workspacePlan
     */
    private function recordDockerNetworkControl(AtlasEngineeringRun $run, array $networkPolicy, array $workspacePlan): void
    {
        $statusValue = (string) ($networkPolicy['status'] ?? 'not_applicable');
        if ($statusValue === 'not_applicable') {
            return;
        }

        $mode = (string) ($networkPolicy['mode'] ?? 'profile');
        if ($mode === 'profile') {
            return;
        }

        $required = ($workspacePlan['mode'] ?? null) === 'docker' && (bool) ($networkPolicy['required'] ?? false);
        $passed = $statusValue === 'passed';

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'docker_network_policy',
                'name' => 'Docker network policy',
                'direction' => 'feedforward',
                'execution_type' => 'computational',
                'regulation_category' => 'security_privacy',
                'timing' => 'prepare',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $passed ? 'passed' : ($required ? 'failed' : 'warning'),
            summary: $passed
                ? 'Politica de rede Docker aplicada: '.$mode
                : 'Politica de rede Docker nao aplicavel: '.(string) ($networkPolicy['reason'] ?? 'network_policy_not_enforced'),
            metadata: array_merge($this->compactDockerNetworkPolicy($networkPolicy), [
                'required' => $required,
            ]),
        );
    }

    /**
     * @param  array<string,mixed>  $healthchecks
     * @param  array<string,mixed>  $workspacePlan
     */
    private function recordDockerHealthcheckControl(AtlasEngineeringRun $run, array $healthchecks, array $workspacePlan): void
    {
        $statusValue = (string) ($healthchecks['status'] ?? 'skipped');
        if (in_array($statusValue, ['not_applicable', 'skipped'], true)) {
            return;
        }

        $required = ($workspacePlan['mode'] ?? null) === 'docker';
        $passed = $statusValue === 'passed';

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: null,
            control: [
                'slug' => 'docker_service_healthchecks',
                'name' => 'Docker service healthchecks',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_safety',
                'timing' => 'pre_validation',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $passed ? 'passed' : ($required ? 'failed' : 'warning'),
            summary: $passed
                ? 'Servicos Docker dependentes estao prontos antes dos testes.'
                : 'Servicos Docker dependentes nao ficaram prontos: '.(string) ($healthchecks['reason'] ?? 'healthcheck_failed'),
            outputExcerpt: (string) ($healthchecks['stderr_excerpt'] ?? ''),
            metadata: array_merge($this->compactDockerHealthchecks($healthchecks), [
                'required' => $required,
            ]),
        );
    }

    /**
     * @param  array{quality_scan?:string,quality_profile?:string}  $options
     */
    private function recordToolRuntimeGateControl(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        string $workspace,
        array $options,
    ): void {
        $qualityScanMode = $this->qualityScanMode($options['quality_scan'] ?? 'off');
        if ($qualityScanMode === 'off') {
            return;
        }

        $qualityProfile = $this->qualityScanProfile($options['quality_profile'] ?? 'auto');
        $required = $qualityScanMode === 'required' || in_array($qualityProfile, ['release', 'deep'], true);
        $filters = [
            'workspace' => $workspace,
            'surface' => 'engineering_quality_scan',
            'run_context_type' => 'engineering_run',
            'run_context_id' => $run->id,
            'limit' => 100,
        ];
        $gate = $this->toolGate->evaluate($filters, [
            'require_evidence' => $required,
        ]);
        $gateStatus = (string) ($gate['status'] ?? 'warning');
        $controlStatus = match ($gateStatus) {
            'passed' => 'passed',
            'warning' => 'passed',
            'blocked' => $required ? 'failed' : 'warning',
            default => $required ? 'failed' : 'warning',
        };
        $summary = match ($gateStatus) {
            'passed' => 'Atlas Tool Runtime gate passou para evidencias do quality scan deste run.',
            'blocked' => 'Atlas Tool Runtime gate bloqueou evidencias do quality scan deste run.',
            default => 'Atlas Tool Runtime gate registrou avisos para evidencias do quality scan deste run.',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: [
                'slug' => 'atlas_tool_runtime_gate',
                'name' => 'Atlas Tool Runtime gate',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'delivery_quality',
                'timing' => 'post_attempt',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $controlStatus,
            summary: $summary,
            outputExcerpt: ($gate['blocking_failures'] ?? []) || ($gate['warnings'] ?? [])
                ? json_encode([
                    'blocking_failures' => $gate['blocking_failures'] ?? [],
                    'warnings' => $gate['warnings'] ?? [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            metadata: [
                'required' => $required,
                'quality_scan_mode' => $qualityScanMode,
                'quality_profile' => $qualityProfile,
                'tool_runtime_gate' => true,
                'gate' => $gate,
            ],
        );
    }

    private function recordVisualToolRuntimeGateControl(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        string $workspace,
        string $visualE2eMode,
        mixed $testRuns,
    ): void {
        $hasVisualRun = collect($testRuns)->contains(fn (mixed $testRun): bool => (bool) data_get($testRun, 'metadata.visual_e2e.managed_by_atlas', false));
        $required = $hasVisualRun && (
            $visualE2eMode === 'required'
            || collect($testRuns)->contains(fn (mixed $testRun): bool => (bool) data_get($testRun, 'metadata.visual_e2e.required', false))
        );

        if (! $hasVisualRun && ! $required) {
            return;
        }

        $filters = [
            'workspace' => $workspace,
            'surface' => 'engineering_visual_smoke',
            'run_context_type' => 'engineering_run',
            'run_context_id' => $run->id,
            'limit' => 50,
        ];
        $gate = $this->toolGate->evaluate($filters, [
            'require_evidence' => $required,
        ]);
        $gateStatus = (string) ($gate['status'] ?? 'warning');
        $controlStatus = match ($gateStatus) {
            'passed' => 'passed',
            'warning' => 'passed',
            'blocked' => $required ? 'failed' : 'warning',
            default => $required ? 'failed' : 'warning',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: [
                'slug' => 'atlas_tool_runtime_visual_gate',
                'name' => 'Atlas Tool Runtime visual gate',
                'direction' => 'feedback',
                'execution_type' => 'computational',
                'regulation_category' => 'behaviour',
                'timing' => 'post_attempt',
                'required' => $required,
                'failure_policy' => $required ? 'blocks_resolved' : 'advisory',
            ],
            status: $controlStatus,
            summary: $gateStatus === 'blocked'
                ? 'Atlas Tool Runtime visual gate bloqueou evidencias visuais deste run.'
                : 'Atlas Tool Runtime visual gate validou evidencias visuais deste run.',
            outputExcerpt: ($gate['blocking_failures'] ?? []) || ($gate['warnings'] ?? [])
                ? json_encode([
                    'blocking_failures' => $gate['blocking_failures'] ?? [],
                    'warnings' => $gate['warnings'] ?? [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            metadata: [
                'required' => $required,
                'visual_e2e_mode' => $visualE2eMode,
                'tool_runtime_gate' => true,
                'gate' => $gate,
            ],
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     */
    private function recordSkippedRequiredControls(AtlasEngineeringRun $run, AtlasEngineeringRunAttempt $attempt, array $controls): void
    {
        $recorded = AtlasEngineeringControlResult::query()
            ->where('engineering_run_id', $run->id)
            ->pluck('control_slug')
            ->all();

        foreach ($controls as $control) {
            $slug = (string) ($control['slug'] ?? '');
            if (! (bool) ($control['required'] ?? false) || in_array($slug, $recorded, true)) {
                continue;
            }

            if (($control['direction'] ?? null) !== 'feedback') {
                continue;
            }

            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'skipped',
                summary: 'Controle requerido nao foi executado neste run: '.$slug,
                metadata: ['required' => true],
            );
        }
    }

    /**
     * @param  array<string,mixed>  $providerOptions
     * @param  array<string,mixed>  $workspacePlan
     * @param  array<string,mixed>  $providerRuntimePlan
     * @return array<string,mixed>
     */
    private function runProvider(AtlasTask $task, string $workspace, array $providerOptions, array $workspacePlan, array $providerRuntimePlan): array
    {
        if (($providerRuntimePlan['runtime'] ?? null) === 'docker' && ($providerRuntimePlan['status'] ?? null) !== 'ready') {
            $reason = (string) ($providerRuntimePlan['fallback_reason'] ?? 'provider_runtime_unavailable');

            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Provider Docker runtime unavailable: '.$reason,
                'trace_id' => null,
                'decoded' => null,
                'runtime' => 'docker',
                'provider_runtime' => $this->compactProviderRuntimePlan($providerRuntimePlan),
            ];
        }

        $hostCommand = [
            AtlasPhpBinary::path(),
            base_path('artisan'),
            'atlas:cli:dev',
            '--task-id='.$task->id,
            '--workspace='.$workspace,
            '--json',
            '--no-progress',
            '--no-notify',
        ];

        if (is_string($providerOptions['provider'] ?? null) && $providerOptions['provider'] !== '') {
            $hostCommand[] = '--provider='.$providerOptions['provider'];
        }

        if (is_string($providerOptions['model'] ?? null) && trim((string) $providerOptions['model']) !== '') {
            $hostCommand[] = '--model='.trim((string) $providerOptions['model']);
        }

        if ((bool) ($providerOptions['complete'] ?? false)) {
            $hostCommand[] = '--complete';
            $hostCommand[] = '--max-iterations='.(string) ($providerOptions['max_attempts'] ?? 1);
        }

        if ((bool) ($providerOptions['critical'] ?? false)) {
            $hostCommand[] = '--critical';
        }

        $permission = (string) ($providerOptions['permission'] ?? 'auto');
        $hostCommand[] = '--permission='.$permission;
        if (in_array($permission, ['write', 'danger'], true)) {
            $hostCommand[] = '--allow-write';
        }
        if ($permission === 'danger') {
            $hostCommand[] = '--dangerously-allow-all';
            $hostCommand[] = '--allow-unsandboxed';
        }

        $runtimeCommand = $this->providerRuntimes->command($hostCommand, $workspacePlan, $providerRuntimePlan);
        $stdout = '';
        $stderr = '';
        $exitCode = 1;

        try {
            $process = new Process($runtimeCommand['command'], $runtimeCommand['cwd'], AtlasSecurity::processEnv(profile: 'provider_runner'));
            $process->setTimeout(max(60, (int) ($providerOptions['timeout_seconds'] ?? 1800)));
            $process->run();

            $exitCode = $process->getExitCode() ?? 1;
            $stdout = AtlasSecurity::redactString($process->getOutput());
            $stderr = AtlasSecurity::redactString($process->getErrorOutput());
        } catch (\Throwable $exception) {
            $stderr = AtlasSecurity::redactString($exception->getMessage());
        }

        $decoded = json_decode($stdout, true);
        $traceId = is_array($decoded) ? data_get($decoded, 'provider_runs.0.trace_id') : null;

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'trace_id' => is_string($traceId) && $traceId !== '' ? $traceId : null,
            'decoded' => is_array($decoded) ? $decoded : null,
            'runtime' => $runtimeCommand['runtime'],
            'command' => AtlasSecurity::redactCommand($runtimeCommand['command']),
            'command_display' => $runtimeCommand['command_display'],
            'provider_runtime' => $this->compactProviderRuntimePlan($providerRuntimePlan),
        ];
    }

    private function recordEvidence(AtlasTask $task, AtlasEngineeringRun $run, array $scoring, mixed $patch): void
    {
        $decision = (string) ($scoring['decision'] ?? 'partial');
        $status = match ($decision) {
            'resolved' => 'passed',
            'unsafe', 'unresolved' => 'failed',
            default => 'needs_review',
        };

        $this->artifacts->recordEvidence($task, [
            'evidence_type' => 'validation_evidence',
            'target_id' => 'engineering_harness_run:'.$run->id,
            'status' => $status,
            'confidence' => $decision === 'resolved' ? 0.92 : 0.7,
            'summary' => 'Engineering Harness Runner finalizou com decision='.$decision.' score='.(string) ($scoring['score'] ?? 0).'.',
            'files' => array_values((array) ($patch?->changed_files_json ?? [])),
            'metadata' => [
                'engineering_run_id' => $run->id,
                'decision' => $decision,
                'score' => $scoring['score'] ?? null,
                'components' => $scoring['components'] ?? [],
                'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
            ],
        ], 'atlas:engineering:runner');
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     * @param  array<string,mixed>  $scoring
     * @return array<string,mixed>
     */
    private function applyIsolatedPatch(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $workspacePlan,
        mixed $patch,
        array $scoring,
        bool $enabled,
    ): array {
        $control = [
            'slug' => 'isolated_patch_apply',
            'name' => 'Apply isolated patch to original workspace',
            'direction' => 'feedback',
            'execution_type' => 'computational',
            'regulation_category' => 'delivery_safety',
            'timing' => 'post_validation',
            'required' => false,
            'failure_policy' => 'blocks_resolved',
        ];

        if (! (bool) ($workspacePlan['isolated'] ?? false)) {
            return ['status' => 'not_applicable', 'reason' => 'workspace_not_isolated'];
        }

        if (! $enabled) {
            $result = ['status' => 'skipped', 'reason' => 'apply_isolated_patch_disabled'];
            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'skipped',
                summary: 'Aplicacao do patch isolado desabilitada.',
                metadata: ['required' => false],
            );

            return $result;
        }

        if (($scoring['decision'] ?? null) !== 'resolved') {
            $result = ['status' => 'skipped', 'reason' => 'preliminary_decision_not_resolved', 'decision' => $scoring['decision'] ?? null];
            $this->controlRegistry->recordResult(
                run: $run,
                attempt: $attempt,
                control: $control,
                status: 'skipped',
                summary: 'Patch isolado nao aplicado porque o run ainda nao esta resolved.',
                metadata: ['required' => false, 'decision' => $scoring['decision'] ?? null],
            );

            return $result;
        }

        $control['required'] = true;
        $result = $this->workspaces->applyPatchToOriginal($workspacePlan, $patch);
        $status = match ($result['status'] ?? null) {
            'applied' => 'passed',
            'blocked' => 'blocked',
            'failed' => 'failed',
            default => 'skipped',
        };

        $this->controlRegistry->recordResult(
            run: $run,
            attempt: $attempt,
            control: $control,
            status: $status,
            summary: match ($status) {
                'passed' => 'Patch isolado aplicado no workspace original.',
                'blocked' => 'Aplicacao do patch isolado bloqueada: '.(string) ($result['reason'] ?? 'blocked'),
                'failed' => 'Aplicacao do patch isolado falhou: '.(string) ($result['reason'] ?? 'failed'),
                default => 'Aplicacao do patch isolado nao executada: '.(string) ($result['reason'] ?? 'skipped'),
            },
            outputExcerpt: (string) ($result['stderr_excerpt'] ?? ''),
            metadata: array_merge($result, ['required' => true]),
        );

        return $result;
    }

    /**
     * @param  array<string,mixed>  $providerRun
     */
    private function recordProviderReviewFindings(
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        array $providerRun,
    ): void {
        $completionStatus = (string) data_get($providerRun, 'decoded.completion.status', '');
        $severity = $completionStatus === 'failed' ? 'p1' : 'p2';
        $traceId = $attempt->trace_id ?: ($providerRun['trace_id'] ?? null);
        $risks = collect((array) data_get($providerRun, 'decoded.completion.completion_packet.risks', []))
            ->filter(fn (mixed $risk): bool => is_scalar($risk) && trim((string) $risk) !== '')
            ->map(fn (mixed $risk): string => trim((string) $risk))
            ->unique()
            ->values();

        foreach ($risks as $risk) {
            $this->reviewFindings->record($run, [
                'attempt_id' => $attempt->id,
                'source' => 'provider_quality_gate',
                'severity' => $severity,
                'title' => Str::limit($risk, 120, ''),
                'body' => $risk,
                'evidence' => [
                    'completion_status' => $completionStatus,
                    'trace_id' => $traceId,
                ],
            ]);
        }

        $failedTests = collect((array) data_get($providerRun, 'decoded.completion.completion_packet.tests', []))
            ->filter(fn (mixed $entry): bool => is_array($entry) && ! (bool) ($entry['ok'] ?? false))
            ->values();
        foreach ($failedTests as $entry) {
            $command = trim((string) ($entry['command'] ?? 'unknown command'));
            $this->reviewFindings->record($run, [
                'attempt_id' => $attempt->id,
                'source' => 'provider_quality_gate',
                'severity' => 'p1',
                'title' => 'Provider quality test failed: '.$command,
                'body' => trim((string) (($entry['stderr'] ?? null) ?: ($entry['stdout'] ?? null) ?: 'Provider quality gate reported a failed test.')),
                'evidence' => [
                    'completion_status' => $completionStatus,
                    'trace_id' => $traceId,
                    'test' => $entry,
                ],
            ]);
        }

        if ($completionStatus === 'failed' && $risks->isEmpty() && $failedTests->isEmpty()) {
            $this->reviewFindings->record($run, [
                'attempt_id' => $attempt->id,
                'source' => 'provider_quality_gate',
                'severity' => 'p1',
                'title' => 'Provider quality gate failed',
                'body' => 'The provider workflow returned a failed completion status without a structured risk item.',
                'evidence' => [
                    'trace_id' => $traceId,
                    'exit_code' => $providerRun['exit_code'] ?? null,
                ],
            ]);
        }
    }

    private function persistTaskSummary(AtlasTask $task, AtlasEngineeringRun $run, array $scoring): void
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $history = is_array($metadata['engineering_harness_run_history'] ?? null)
            ? $metadata['engineering_harness_run_history']
            : [];
        $summary = [
            'run_id' => $run->id,
            'status' => $run->status,
            'decision' => $run->decision,
            'score' => $run->score,
            'attempt_count' => $run->attempt_count,
            'context_pack_hash' => $run->context_pack_hash,
            'blocking_reasons' => $scoring['blocking_reasons'] ?? [],
            'created_at' => $run->created_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];

        $metadata['latest_engineering_harness_run'] = $summary;
        $metadata['engineering_harness_run_history'] = array_slice([$summary, ...$history], 0, 20);
        $task->forceFill(['metadata' => $metadata])->save();
    }

    private function sourceTestCommand(AtlasEngineeringRun $sourceRun): ?string
    {
        $sourceRun->loadMissing('testRuns');
        $command = $sourceRun->testRuns
            ->map(fn ($testRun): ?string => is_string($testRun->command) ? trim($testRun->command) : null)
            ->first(fn (?string $command): bool => is_string($command) && $command !== '');

        return is_string($command) && $command !== '' ? $command : null;
    }

    private function statusForDecision(string $decision): string
    {
        return match ($decision) {
            'resolved' => 'passed',
            'blocked' => 'blocked',
            'unsafe', 'unresolved' => 'failed',
            default => 'reviewing',
        };
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
            'attempt_comparison' => $this->attemptComparison($run),
            'patch_artifacts' => $run->patchArtifacts->map(fn ($patch): array => [
                'id' => $patch->id,
                'attempt_id' => $patch->attempt_id,
                'base_ref' => $patch->base_ref,
                'head_ref' => $patch->head_ref,
                'diff_hash' => $patch->diff_hash,
                'diff_excerpt' => $patch->diff_excerpt,
                'diff_path' => $patch->diff_path,
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
            'timeline' => $this->timeline($run),
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attemptComparison(AtlasEngineeringRun $run): array
    {
        $attemptCount = $run->attempts->count();
        if ($attemptCount === 0) {
            return [
                'status' => 'empty',
                'best_attempt_id' => null,
                'best_attempt_number' => null,
                'best_score' => null,
                'best_recommendation' => null,
                'attempts' => [],
            ];
        }

        $ranked = $run->attempts
            ->map(fn (AtlasEngineeringRunAttempt $attempt): array => $this->attemptComparisonRow($run, $attempt, $attemptCount))
            ->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['attempt_number'] <=> $right['attempt_number']))
            ->values()
            ->map(fn (array $row, int $index): array => array_merge($row, ['rank' => $index + 1]))
            ->values();

        $best = $ranked->first();

        return [
            'status' => $attemptCount === 1 ? 'single_attempt' : 'ranked',
            'best_attempt_id' => $best['attempt_id'] ?? null,
            'best_attempt_number' => $best['attempt_number'] ?? null,
            'best_score' => $best['score'] ?? null,
            'best_recommendation' => $best['recommendation'] ?? null,
            'attempts' => $ranked->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attemptComparisonRow(AtlasEngineeringRun $run, AtlasEngineeringRunAttempt $attempt, int $attemptCount): array
    {
        $patches = $this->recordsForAttempt($run->patchArtifacts, $attempt, $attemptCount);
        $tests = $this->recordsForAttempt($run->testRuns, $attempt, $attemptCount);
        $controls = $this->recordsForAttempt($run->controlResults, $attempt, $attemptCount);
        $findings = $this->recordsForAttempt($run->reviewFindings, $attempt, $attemptCount);

        $passedTests = $tests->where('status', 'passed')->count();
        $failedTests = $tests->filter(fn ($test): bool => in_array((string) $test->status, ['failed', 'blocked', 'timed_out'], true))->count();
        $failedControls = $controls->filter(fn ($control): bool => in_array((string) $control->status, ['failed', 'blocked'], true))->count();
        $openFindings = $findings->where('status', 'open');
        $blockingFindings = $openFindings->whereIn('severity', ['p0', 'p1'])->count();
        $riskFlags = $patches
            ->flatMap(fn ($patch): array => (array) ($patch->risk_flags_json ?? []))
            ->filter()
            ->unique()
            ->values();
        $changedFiles = collect((array) ($attempt->changed_files_json ?? []))
            ->merge($patches->flatMap(fn ($patch): array => (array) ($patch->changed_files_json ?? [])))
            ->filter()
            ->unique()
            ->values();

        $score = 50;
        $score += match ((string) $attempt->status) {
            'completed', 'passed', 'resolved' => 20,
            'failed' => -20,
            'timed_out' => -25,
            'cancelled' => -30,
            default => 0,
        };
        $score += min(20, $passedTests * 8);
        $score += $patches->isNotEmpty() ? 10 : 0;
        $score += $changedFiles->isNotEmpty() ? 5 : 0;
        $score -= min(30, $failedTests * 15);
        $score -= min(20, $failedControls * 10);
        $score -= min(30, $blockingFindings * 20);
        $score -= min(20, $riskFlags->count() * 5);
        $score -= is_string($attempt->failure_summary) && trim($attempt->failure_summary) !== '' ? 10 : 0;
        $score = max(0, min(100, $score));

        $recommendation = match (true) {
            $score >= 85 && $failedTests === 0 && $failedControls === 0 && $blockingFindings === 0 => 'best_repair_base',
            $score >= 70 => 'review_before_replay',
            default => 'avoid_replay_base',
        };

        return [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'provider' => $attempt->provider,
            'model' => $attempt->model,
            'phase' => $attempt->phase,
            'status' => $attempt->status,
            'score' => $score,
            'recommendation' => $recommendation,
            'patch_hash' => $attempt->patch_hash,
            'changed_files_count' => $changedFiles->count(),
            'patch_count' => $patches->count(),
            'passed_tests' => $passedTests,
            'failed_tests' => $failedTests,
            'failed_controls' => $failedControls,
            'open_findings' => $openFindings->count(),
            'blocking_findings' => $blockingFindings,
            'risk_flags' => $riskFlags->all(),
            'signals' => $this->attemptComparisonSignals(
                $attempt,
                $patches->count(),
                $changedFiles->count(),
                $passedTests,
                $failedTests,
                $failedControls,
                $blockingFindings,
                $riskFlags->count(),
            ),
        ];
    }

    private function recordsForAttempt($records, AtlasEngineeringRunAttempt $attempt, int $attemptCount)
    {
        return $records->filter(function ($record) use ($attempt, $attemptCount): bool {
            $recordAttemptId = $record->attempt_id ?? null;

            return (string) $recordAttemptId === (string) $attempt->id
                || ($attemptCount === 1 && ($recordAttemptId === null || $recordAttemptId === ''));
        })->values();
    }

    /**
     * @return array<int,string>
     */
    private function attemptComparisonSignals(
        AtlasEngineeringRunAttempt $attempt,
        int $patchCount,
        int $changedFilesCount,
        int $passedTests,
        int $failedTests,
        int $failedControls,
        int $blockingFindings,
        int $riskFlagCount,
    ): array {
        return collect([
            in_array((string) $attempt->status, ['completed', 'passed', 'resolved'], true) ? 'attempt_completed' : 'attempt_not_completed',
            $patchCount > 0 ? 'patch_captured' : 'no_patch_artifact',
            $changedFilesCount > 0 ? 'changed_files_present' : 'no_changed_files',
            $passedTests > 0 ? 'tests_passed' : null,
            $failedTests > 0 ? 'tests_failed' : null,
            $failedControls > 0 ? 'controls_failed' : null,
            $blockingFindings > 0 ? 'blocking_findings_open' : null,
            $riskFlagCount > 0 ? 'risk_flags_present' : null,
            is_string($attempt->failure_summary) && trim($attempt->failure_summary) !== '' ? 'failure_summary_present' : null,
        ])->filter()->values()->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function timeline(AtlasEngineeringRun $run): array
    {
        $events = collect();

        foreach ($run->attempts as $attempt) {
            $events->push([
                'type' => 'attempt',
                'status' => $attempt->status,
                'label' => 'Attempt #'.$attempt->attempt_number.' '.$attempt->phase,
                'at' => $attempt->finished_at?->toJSON() ?: $attempt->created_at?->toJSON(),
                'ref_id' => $attempt->id,
            ]);
        }

        foreach ($run->testRuns as $testRun) {
            $events->push([
                'type' => 'test',
                'status' => $testRun->status,
                'label' => $testRun->command,
                'at' => $testRun->created_at?->toJSON(),
                'ref_id' => $testRun->id,
            ]);
        }

        foreach ($run->controlResults as $result) {
            $events->push([
                'type' => 'control',
                'status' => $result->status,
                'label' => $result->control_slug,
                'at' => $result->created_at?->toJSON(),
                'ref_id' => $result->id,
            ]);
        }

        foreach ($run->reviewFindings as $finding) {
            $events->push([
                'type' => 'review_finding',
                'status' => $finding->status,
                'label' => strtoupper((string) $finding->severity).': '.$finding->title,
                'at' => $finding->created_at?->toJSON(),
                'ref_id' => $finding->id,
            ]);
        }

        foreach ($run->operatorActions as $action) {
            $events->push([
                'type' => 'operator_action',
                'status' => $action->status_after,
                'label' => 'Operator '.$action->action,
                'at' => $action->acted_at?->toJSON() ?: $action->created_at?->toJSON(),
                'ref_id' => $action->id,
            ]);
        }

        return $events
            ->sortBy('at')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function compactWorkspacePlan(array $plan): array
    {
        return [
            'mode' => $plan['mode'] ?? null,
            'requested_mode' => $plan['requested_mode'] ?? null,
            'status' => $plan['status'] ?? null,
            'original_workspace_hash' => isset($plan['original_workspace']) ? hash('sha256', (string) $plan['original_workspace']) : null,
            'execution_workspace_hash' => isset($plan['execution_workspace']) ? hash('sha256', (string) $plan['execution_workspace']) : null,
            'repo_root_hash' => isset($plan['repo_root']) ? hash('sha256', (string) $plan['repo_root']) : null,
            'branch' => $plan['branch'] ?? null,
            'head' => $plan['head'] ?? null,
            'dirty_count' => count((array) ($plan['dirty_files'] ?? [])),
            'isolated' => (bool) ($plan['isolated'] ?? false),
            'isolation_type' => $plan['isolation_type'] ?? null,
            'containerized_execution' => (bool) ($plan['containerized_execution'] ?? false),
            'docker' => $this->compactDockerPlan((array) ($plan['docker'] ?? [])),
            'dirty_files_included' => $plan['dirty_files_included'] ?? null,
            'fallback_reason' => $plan['fallback_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function dockerOptions(array $options): array
    {
        return array_filter([
            'docker_service' => is_string($options['docker_service'] ?? null) ? $options['docker_service'] : null,
            'docker_image' => is_string($options['docker_image'] ?? null) ? $options['docker_image'] : null,
            'docker_workdir' => is_string($options['docker_workdir'] ?? null) ? $options['docker_workdir'] : null,
            'docker_cache' => is_string($options['docker_cache'] ?? null) ? $options['docker_cache'] : null,
            'docker_network' => is_string($options['docker_network'] ?? null) ? $options['docker_network'] : null,
            'docker_healthcheck_services' => is_array($options['docker_healthcheck_services'] ?? null) ? $options['docker_healthcheck_services'] : null,
            'docker_healthcheck_timeout' => is_numeric($options['docker_healthcheck_timeout'] ?? null) ? (int) $options['docker_healthcheck_timeout'] : null,
            'docker_artifact_paths' => is_array($options['docker_artifact_paths'] ?? null) ? $options['docker_artifact_paths'] : null,
            'docker_artifact_max_files' => is_numeric($options['docker_artifact_max_files'] ?? null) ? (int) $options['docker_artifact_max_files'] : null,
            'docker_artifact_max_bytes' => is_numeric($options['docker_artifact_max_bytes'] ?? null) ? (int) $options['docker_artifact_max_bytes'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && (! is_array($value) || $value !== []));
    }

    private function visualE2eMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'off', 'required'], true) ? $mode : 'auto';
    }

    private function qualityScanMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'off';

        return in_array($mode, ['auto', 'off', 'required'], true) ? $mode : 'off';
    }

    private function qualityScanProfile(mixed $value): string
    {
        $profile = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($profile, ['auto', 'fast', 'standard', 'release', 'deep'], true) ? $profile : 'auto';
    }

    /**
     * @param  array<string,mixed>  $harnessability
     * @param  array<string,mixed>  $requested
     * @return array<string,mixed>
     */
    private function autonomyPolicy(array $harnessability, array $requested): array
    {
        $mode = $this->harnessPolicyMode($requested['mode'] ?? 'auto');
        $score = max(0, min(100, (int) ($harnessability['score'] ?? 0)));
        $level = (string) ($harnessability['level'] ?? 'low');
        $providerExecuted = ! (bool) ($requested['dry_run'] ?? false) && ! (bool) ($requested['no_provider'] ?? false);
        $forceSandboxWithoutProvider = (bool) ($requested['force_sandbox_without_provider'] ?? false);
        $permission = $this->permissionMode($requested['permission'] ?? 'auto');
        $sandbox = $this->sandboxMode($requested['sandbox'] ?? 'workspace');
        $maxAttempts = max(1, min(10, (int) ($requested['max_attempts'] ?? 1)));
        $autoTest = (bool) ($requested['auto_test'] ?? false);
        $actions = [];
        $reasons = [];
        $testCommands = array_values((array) ($harnessability['test_commands'] ?? []));
        $thresholds = $this->harnessabilityThresholds($harnessability);
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

    private function harnessPolicyMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'off', 'strict'], true) ? $mode : 'auto';
    }

    private function permissionMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'read', 'write', 'danger'], true) ? $mode : 'auto';
    }

    private function sandboxMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'workspace';

        return in_array($mode, ['workspace', 'worktree', 'docker'], true) ? $mode : 'workspace';
    }

    /**
     * @param  array<string,mixed>  $harnessability
     * @return array<string,int|string>
     */
    private function harnessabilityThresholds(array $harnessability): array
    {
        $defaults = [
            'medium_min_score' => 55,
            'high_min_score' => 80,
            'require_worktree_below_score' => 80,
            'danger_permission_min_score' => 80,
            'write_permission_min_score' => 55,
            'cap_attempts_to_one_below_score' => 55,
            'cap_attempts_to_two_below_score' => 80,
            'require_auto_test_below_score' => 80,
            'policy_source' => 'static_default',
        ];
        $recommended = (array) data_get($harnessability, 'calibration.recommended_thresholds', []);
        $thresholds = array_merge($defaults, array_intersect_key($recommended, $defaults));

        foreach ($thresholds as $key => $value) {
            if ($key === 'policy_source') {
                $thresholds[$key] = is_string($value) && $value !== '' ? $value : 'static_default';

                continue;
            }

            $thresholds[$key] = max(0, min(100, (int) $value));
        }

        return $thresholds;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function providerRuntimeOptions(array $options): array
    {
        return array_filter([
            'provider_runtime' => is_string($options['provider_runtime'] ?? null) ? $options['provider_runtime'] : null,
            'provider_docker_compose_file' => is_string($options['provider_docker_compose_file'] ?? null) ? $options['provider_docker_compose_file'] : null,
            'provider_docker_service' => is_string($options['provider_docker_service'] ?? null) ? $options['provider_docker_service'] : null,
            'provider_docker_app_dir' => is_string($options['provider_docker_app_dir'] ?? null) ? $options['provider_docker_app_dir'] : null,
            'provider_docker_workspace_dir' => is_string($options['provider_docker_workspace_dir'] ?? null) ? $options['provider_docker_workspace_dir'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $providerRuntimeOptions
     * @return array<string,mixed>
     */
    private function skippedProviderRuntimePlan(array $providerRuntimeOptions): array
    {
        return [
            'requested_runtime' => (string) ($providerRuntimeOptions['provider_runtime'] ?? config('atlas.engineering.provider_runtime.default', 'host')),
            'runtime' => 'host',
            'status' => 'skipped',
            'required' => false,
            'fallback_reason' => 'provider_not_executed',
        ];
    }

    /**
     * @param  array<string,mixed>  $docker
     * @return array<string,mixed>|null
     */
    private function compactDockerPlan(array $docker): ?array
    {
        if ($docker === []) {
            return null;
        }

        return [
            'profile_found' => (bool) ($docker['profile_found'] ?? false),
            'usable' => (bool) ($docker['usable'] ?? false),
            'runtime' => $docker['runtime'] ?? null,
            'docker_available' => (bool) ($docker['docker_available'] ?? false),
            'compose_available' => (bool) ($docker['compose_available'] ?? false),
            'compose_files' => array_values((array) ($docker['compose_files'] ?? [])),
            'selected_compose_file' => $docker['selected_compose_file'] ?? null,
            'dockerfile' => $docker['dockerfile'] ?? null,
            'devcontainer' => $docker['devcontainer'] ?? null,
            'service' => $docker['service'] ?? null,
            'image' => $docker['image'] ?? null,
            'container_workdir' => $docker['container_workdir'] ?? null,
            'cache' => $this->compactDockerCachePlan((array) ($docker['cache'] ?? [])),
            'healthchecks' => $this->compactDockerHealthcheckPlan((array) ($docker['healthchecks'] ?? [])),
            'artifacts' => $this->compactDockerArtifactPlan((array) ($docker['artifacts'] ?? [])),
            'network' => $this->compactDockerNetworkPlan((array) ($docker['network'] ?? [])),
            'unusable_reason' => $docker['unusable_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $cache
     * @return array<string,mixed>|null
     */
    private function compactDockerCachePlan(array $cache): ?array
    {
        if ($cache === []) {
            return null;
        }

        return [
            'mode' => $cache['mode'] ?? null,
            'enabled' => (bool) ($cache['enabled'] ?? false),
            'root_hash' => isset($cache['root']) ? hash('sha256', (string) $cache['root']) : null,
            'mounts' => collect((array) ($cache['mounts'] ?? []))
                ->filter(fn (mixed $mount): bool => is_array($mount))
                ->map(fn (array $mount): array => [
                    'name' => $mount['name'] ?? null,
                    'host_path_hash' => isset($mount['host_path']) ? hash('sha256', (string) $mount['host_path']) : null,
                    'container_path' => $mount['container_path'] ?? null,
                    'env_keys' => array_keys((array) ($mount['env'] ?? [])),
                    'enabled' => (bool) ($mount['enabled'] ?? false),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $healthchecks
     * @return array<string,mixed>|null
     */
    private function compactDockerHealthcheckPlan(array $healthchecks): ?array
    {
        if ($healthchecks === []) {
            return null;
        }

        return [
            'services' => array_values((array) ($healthchecks['services'] ?? [])),
            'timeout_seconds' => $healthchecks['timeout_seconds'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @return array<string,mixed>|null
     */
    private function compactDockerArtifactPlan(array $artifacts): ?array
    {
        if ($artifacts === []) {
            return null;
        }

        return [
            'paths' => array_values((array) ($artifacts['paths'] ?? [])),
            'max_files' => $artifacts['max_files'] ?? null,
            'max_bytes' => $artifacts['max_bytes'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $network
     * @return array<string,mixed>|null
     */
    private function compactDockerNetworkPlan(array $network): ?array
    {
        if ($network === []) {
            return null;
        }

        return [
            'mode' => $network['mode'] ?? null,
            'runtime' => $network['runtime'] ?? null,
            'enforced' => (bool) ($network['enforced'] ?? false),
            'required' => (bool) ($network['required'] ?? false),
            'unavailable_reason' => $network['unavailable_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $healthchecks
     * @return array<string,mixed>
     */
    private function compactDockerHealthchecks(array $healthchecks): array
    {
        return [
            'status' => $healthchecks['status'] ?? null,
            'reason' => $healthchecks['reason'] ?? null,
            'service_count' => count((array) ($healthchecks['services'] ?? [])),
            'services' => collect((array) ($healthchecks['services'] ?? []))
                ->filter(fn (mixed $service): bool => is_array($service) || is_scalar($service))
                ->map(fn (mixed $service): mixed => is_array($service) ? [
                    'service' => $service['service'] ?? null,
                    'ready' => (bool) ($service['ready'] ?? false),
                    'state' => $service['state'] ?? null,
                    'health' => $service['health'] ?? null,
                    'exit_code' => $service['exit_code'] ?? null,
                ] : (string) $service)
                ->values()
                ->all(),
            'timeout_seconds' => $healthchecks['timeout_seconds'] ?? null,
            'started' => (bool) ($healthchecks['started'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function compactProviderRuntimePlan(array $plan): array
    {
        return [
            'requested_runtime' => $plan['requested_runtime'] ?? null,
            'runtime' => $plan['runtime'] ?? null,
            'status' => $plan['status'] ?? null,
            'required' => (bool) ($plan['required'] ?? false),
            'fallback_reason' => $plan['fallback_reason'] ?? null,
            'docker_available' => array_key_exists('docker_available', $plan) ? (bool) $plan['docker_available'] : null,
            'compose_available' => array_key_exists('compose_available', $plan) ? (bool) $plan['compose_available'] : null,
            'compose_file_hash' => isset($plan['compose_file']) ? hash('sha256', (string) $plan['compose_file']) : null,
            'service' => $plan['service'] ?? null,
            'service_found' => array_key_exists('service_found', $plan) ? (bool) $plan['service_found'] : null,
            'app_dir' => $plan['app_dir'] ?? null,
            'workspace_dir' => $plan['workspace_dir'] ?? null,
            'execution_workspace_hash' => $plan['execution_workspace_hash'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $networkPolicy
     * @return array<string,mixed>
     */
    private function compactDockerNetworkPolicy(array $networkPolicy): array
    {
        return [
            'status' => $networkPolicy['status'] ?? null,
            'reason' => $networkPolicy['reason'] ?? null,
            'mode' => $networkPolicy['mode'] ?? null,
            'runtime' => $networkPolicy['runtime'] ?? null,
            'enforced' => (bool) ($networkPolicy['enforced'] ?? false),
            'required' => (bool) ($networkPolicy['required'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $providerRun
     * @return array<string,mixed>
     */
    private function compactProviderPayload(array $providerRun): array
    {
        return [
            'exit_code' => $providerRun['exit_code'] ?? null,
            'trace_id' => $providerRun['trace_id'] ?? null,
            'runtime' => $providerRun['runtime'] ?? null,
            'command_display' => $providerRun['command_display'] ?? null,
            'provider_runtime' => $providerRun['provider_runtime'] ?? null,
            'phase' => data_get($providerRun, 'decoded.phase'),
            'ok' => data_get($providerRun, 'decoded.ok'),
            'completion_status' => data_get($providerRun, 'decoded.completion.status'),
            'dev_plan_id' => data_get($providerRun, 'decoded.dev_execution_plan.plan_id'),
            'provider_runs' => collect((array) data_get($providerRun, 'decoded.provider_runs', []))
                ->filter(fn (mixed $entry): bool => is_array($entry))
                ->map(fn (array $entry): array => $this->compactSingleProviderRun($entry))
                ->values()
                ->all(),
            'stderr_excerpt' => isset($providerRun['stderr']) ? Str::limit((string) $providerRun['stderr'], 1200) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $providerRun
     * @return array<string,mixed>
     */
    private function compactSingleProviderRun(array $providerRun): array
    {
        return [
            'iteration' => $providerRun['iteration'] ?? null,
            'trace_id' => $providerRun['trace_id'] ?? null,
            'exit_code' => $providerRun['exit_code'] ?? null,
            'stdout_excerpt' => isset($providerRun['stdout']) ? Str::limit((string) $providerRun['stdout'], 1200) : null,
            'stderr_excerpt' => isset($providerRun['stderr']) ? Str::limit((string) $providerRun['stderr'], 1200) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function failureSummary(array $payload): string
    {
        return Str::limit((string) ($payload['stderr'] ?? $payload['stdout'] ?? 'Provider execution failed.'), 2000);
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function workspace(mixed $workspace): string
    {
        $workspace = is_string($workspace) && $workspace !== '' ? $workspace : (getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
