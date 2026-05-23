<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringControlRevision;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasEngineeringRunOperatorAction;
use App\Models\AtlasEngineeringTestRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasTask;
use App\Models\AtlasToolRun;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiPrompt;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Engineering\EngineeringBenchmarkService;
use App\Services\Engineering\EngineeringControlRegistryService;
use App\Services\Engineering\EngineeringDockerHarnessService;
use App\Services\Engineering\EngineeringHarnessExecutionService;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringPatchArtifactService;
use App\Services\Engineering\EngineeringReviewFindingService;
use App\Services\Engineering\EngineeringRunScoringService;
use App\Services\Engineering\EngineeringTestMatrixInput;
use App\Services\Engineering\EngineeringWorkspaceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EngineeringHarnessRunnerTest extends TestCase
{
    private string $workspace;

    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->resetMutableAtlasConfig();
        $this->createTables();
        $this->workspace = $this->createWorkspace();
        config()->set('atlas.ai.tool_permissions.allowed_roots', [dirname($this->workspace)]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectoryQuietly($this->workspace);
        $this->deleteDirectoryQuietly(storage_path('app/engineering-worktrees'));
        $this->deleteDirectoryQuietly(storage_path('app/engineering-runs'));
        $this->deleteDirectoryQuietly(storage_path('app/engineering-benchmark-runs'));
        $this->deleteDirectoryQuietly(storage_path('app/engineering-quality-scans'));
        $this->dropTables();

        parent::tearDown();
    }

    public function test_runner_records_run_attempt_patch_controls_tests_and_score(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'max_attempts' => 1,
        ]);

        $this->assertSame('resolved', data_get($payload, 'run.decision'));
        $this->assertSame(100, data_get($payload, 'run.score'));
        $this->assertSame(1, data_get($payload, 'test_run_count'));
        $this->assertNotEmpty(data_get($payload, 'context_pack.hash'));
        $this->assertNotEmpty(data_get($payload, 'run.patch_artifacts.0.diff_path'));
        $this->assertTrue(File::exists((string) data_get($payload, 'run.patch_artifacts.0.diff_path')));
        $this->assertTrue((bool) data_get($payload, 'run.patch_artifacts.0.integrity.checked'));
        $this->assertTrue((bool) data_get($payload, 'run.patch_artifacts.0.integrity.exists'));
        $this->assertTrue((bool) data_get($payload, 'run.patch_artifacts.0.integrity.hash_matches'));
        $this->assertDatabaseHas('atlas_engineering_runs', [
            'task_id' => $task->id,
            'decision' => 'resolved',
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'attempt_number' => 1,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'primary_test_command',
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'changed_files_scope_policy',
            'status' => 'passed',
        ]);
        $controlResult = AtlasEngineeringControlResult::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('control_slug', 'primary_test_command')
            ->firstOrFail();
        $this->assertSame(1, $controlResult->control_version);
        $this->assertNotEmpty($controlResult->control_definition_hash);
        $this->assertSame($controlResult->control_definition_hash, data_get($controlResult->metadata, 'control_definition_hash'));
        $this->assertSame(1, data_get($controlResult->metadata, 'control_version'));
        $this->assertTrue(AtlasEngineeringControlRevision::query()
            ->where('slug', 'primary_test_command')
            ->where('version', 1)
            ->where('definition_hash', $controlResult->control_definition_hash)
            ->exists());
        $this->assertDatabaseHas('atlas_engineering_test_runs', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_evidence', [
            'task_id' => $task->id,
            'source' => 'atlas:engineering:runner',
            'status' => 'passed',
        ]);

        $events = AtlasLedgerEvent::query()
            ->where('envelope_id', 'engineering_run:'.data_get($payload, 'run.id'))
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->pluck('event_type')
            ->all();
        $this->assertContains(LedgerEventType::ExecutionStarted->value, $events);
        $this->assertContains(LedgerEventType::ContextComposed->value, $events);
        $this->assertContains(LedgerEventType::OperationCompleted->value, $events);
    }

    public function test_runner_blocks_provider_execution_without_registered_awis_workspace(): void
    {
        $task = $this->task();

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => false,
            'dry_run' => false,
            'sandbox' => 'workspace',
            'max_attempts' => 1,
        ]);

        $this->assertSame('blocked', data_get($payload, 'run.status'));
        $this->assertSame('blocked', data_get($payload, 'run.decision'));
        $this->assertSame('awis_workspace_required_for_engineering_run', data_get($payload, 'awis_execution_gate.error'));
        $this->assertSame('workspace_not_registered', data_get($payload, 'awis_execution_gate.workspace_resolution.reason'));
        $this->assertDatabaseCount('atlas_engineering_runs', 0);
    }

    public function test_strict_changed_files_scope_blocks_resolved_when_diff_escapes_allowlist(): void
    {
        $task = $this->task([
            'goal' => 'Alterar apenas o arquivo principal.',
            'acceptance_criteria' => ['src/example.txt deve ser o unico arquivo alterado.'],
            'allowed_files' => ['src/example.txt'],
            'strict_file_scope' => true,
            'test_coverage' => ['Comando de validacao passa.'],
        ]);
        File::put($this->workspace.'/README.md', "# Test repo\n\nOutside scope\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'max_attempts' => 1,
        ]);

        $this->assertSame('unresolved', data_get($payload, 'run.decision'));
        $this->assertContains('Controle falhou: changed_files_scope_policy', data_get($payload, 'score.blocking_reasons', []));
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'changed_files_scope_policy',
            'status' => 'failed',
        ]);

        $control = AtlasEngineeringControlResult::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('control_slug', 'changed_files_scope_policy')
            ->firstOrFail();

        $this->assertTrue((bool) data_get($control->metadata, 'required'));
        $this->assertSame(['README.md'], data_get($control->metadata, 'outside_scope'));
        $this->assertSame(['src/example.txt'], data_get($control->metadata, 'declared_scope'));
    }

    public function test_engineering_run_command_outputs_json_payload(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $exitCode = Artisan::call('atlas:engineering:run', [
            '--task-id' => $task->id,
            '--workspace' => $this->workspace,
            '--no-provider' => true,
            '--auto-test' => true,
            '--test-command' => $this->passingPhpCommand(),
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode(substr($output, (int) strpos($output, '{')), true);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertIsArray($payload);
        $this->assertSame('resolved', data_get($payload, 'run.decision'));
        $this->assertContains('primary_test_command', collect(data_get($payload, 'run.control_results', []))->pluck('control_slug')->all());
    }

    public function test_harness_execution_service_creates_task_for_programming_request_without_task_id(): void
    {
        File::put($this->workspace.'/src/example.txt', "after\n");

        $result = app(EngineeringHarnessExecutionService::class)
            ->execute(ProgrammingExecutionRequest::fromArray([
                'profile' => 'forge',
                'workspace' => $this->workspace,
                'objective' => 'Executar harness sem task-id explicito',
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
                'max_attempts' => 1,
            ]))
            ->toArray();

        $this->assertSame('passed', data_get($result, 'status'));
        $this->assertSame('engineering_harness', data_get($result, 'executor'));
        $this->assertTrue((bool) data_get($result, 'created_task'));
        $this->assertNotEmpty(data_get($result, 'task_id'));
        $this->assertSame('resolved', data_get($result, 'harness_payload.run.decision'));
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($result, 'policy_contract_enforcement.effective_options.agent_behavior_contract.contract_id'));
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => data_get($result, 'task_id'),
            'title' => 'Executar harness sem task-id explicito',
        ]);
        $task = AtlasTask::query()->findOrFail(data_get($result, 'task_id'));
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($task->metadata, 'engineering_contract.agent_behavior_contract.contract_id'));
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($task->metadata, 'programming_orchestrator.agent_behavior_contract.contract_id'));
    }

    public function test_harness_execution_service_enforces_gate_policy_contracts(): void
    {
        File::put($this->workspace.'/src/example.txt', "after\n");

        $result = app(EngineeringHarnessExecutionService::class)
            ->execute(ProgrammingExecutionRequest::fromArray([
                'profile' => 'forge',
                'workspace' => $this->workspace,
                'objective' => 'Executar harness com gate strict',
                'no_provider' => true,
                'auto_test' => false,
                'quality_scan' => 'off',
                'harness_policy' => 'auto',
                'test_command' => $this->passingPhpCommand(),
                'max_attempts' => 1,
                'policy_contracts' => [
                    'gates' => [
                        'minimum_gate' => 'strict',
                        'evidence_required' => true,
                    ],
                    'tools' => [
                        'mode' => 'harness',
                        'workspace_write' => true,
                    ],
                ],
            ]))
            ->toArray();

        $this->assertSame('passed', data_get($result, 'status'));
        $this->assertTrue((bool) data_get($result, 'policy_contract_enforcement.gate_contract_enforced'));
        $this->assertSame(true, data_get($result, 'policy_contract_enforcement.effective_options.auto_test'));
        $this->assertSame('required', data_get($result, 'policy_contract_enforcement.effective_options.quality_scan'));
        $this->assertSame('strict', data_get($result, 'policy_contract_enforcement.effective_options.harness_policy'));
        $this->assertSame('strict', data_get($result, 'policy_contracts.gates.minimum_gate'));
        $this->assertNull(data_get($result, 'kernel_repair_decision'));

        $run = AtlasEngineeringRun::query()->findOrFail(data_get($result, 'harness_payload.run.id'));
        $this->assertSame('required', data_get($run->provider_strategy_json, 'quality_scan.mode'));
        $this->assertSame(true, data_get($run->provider_strategy_json, 'requested_auto_test'));
        $this->assertSame(true, data_get($run->provider_strategy_json, 'effective_auto_test'));
    }

    public function test_harness_execution_service_blocks_provider_run_when_tool_contract_is_read_only(): void
    {
        $result = app(EngineeringHarnessExecutionService::class)
            ->execute(ProgrammingExecutionRequest::fromArray([
                'profile' => 'forge',
                'workspace' => $this->workspace,
                'objective' => 'Tentativa de harness com contrato read-only',
                'no_provider' => false,
                'policy_contracts' => [
                    'tools' => [
                        'mode' => 'read_only',
                        'workspace_write' => false,
                    ],
                ],
            ]))
            ->toArray();

        $this->assertSame('blocked', data_get($result, 'status'));
        $this->assertSame('engineering_harness', data_get($result, 'executor'));
        $this->assertContains('tool_contract_blocks_workspace_write', data_get($result, 'blocking_failures'));
        $this->assertSame('tool_contract_blocks_workspace_write', data_get($result, 'policy_contract_enforcement.blocked_reason'));
        $this->assertSame('read_only', data_get($result, 'policy_contracts.tools.mode'));
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($result, 'policy_contract_enforcement.effective_options.agent_behavior_contract.contract_id'));
        $this->assertSame('needs_human_review', data_get($result, 'kernel_repair_decision.status'));
        $this->assertSame('human_review', data_get($result, 'kernel_repair_decision.strategy'));
        $this->assertSame('tool.policy_denied', data_get($result, 'kernel_repair_decision.evidence_payload.failure_classification.failure_domain'));
        $this->assertSame('AtlasRepairOrchestrator', data_get($result, 'repair_contract.orchestrator'));
        $this->assertTrue((bool) data_get($result, 'repair_contract.decision_required_before_enqueue'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'engineering_harness.repair',
        ]);
        $this->assertDatabaseMissing('atlas_tasks', [
            'title' => 'Tentativa de harness com contrato read-only',
        ]);
    }

    public function test_harness_execution_service_attaches_kernel_repair_decision_when_harness_blocks(): void
    {
        $task = $this->task([
            'goal' => 'Alterar apenas o arquivo principal.',
            'acceptance_criteria' => ['src/example.txt deve ser o unico arquivo alterado.'],
            'allowed_files' => ['src/example.txt'],
            'strict_file_scope' => true,
            'test_coverage' => ['Comando de validacao passa.'],
        ]);
        File::put($this->workspace.'/README.md', "# Test repo\n\nOutside scope\n");

        $result = app(EngineeringHarnessExecutionService::class)
            ->execute(ProgrammingExecutionRequest::fromArray([
                'profile' => 'forge',
                'task_id' => $task->id,
                'workspace' => $this->workspace,
                'objective' => 'Executar harness com bloqueio de escopo',
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
                'max_attempts' => 2,
            ]))
            ->toArray();

        $this->assertSame('blocked', data_get($result, 'status'));
        $this->assertContains('Controle falhou: changed_files_scope_policy', data_get($result, 'blocking_failures', []));
        $this->assertSame('repair_allowed', data_get($result, 'kernel_repair_decision.status'));
        $this->assertSame('rerun_harness', data_get($result, 'kernel_repair_decision.strategy'));
        $this->assertSame('harness.failed', data_get($result, 'kernel_repair_decision.evidence_payload.failure_classification.failure_domain'));
        $this->assertContains('engineering_run:'.data_get($result, 'harness_payload.run.id'), data_get($result, 'kernel_repair_decision.evidence_payload.evidence_refs', []));
        $this->assertSame('AtlasRepairOrchestrator', data_get($result, 'repair_contract.orchestrator'));
        $this->assertTrue((bool) data_get($result, 'repair_contract.blocks_when_kernel_blocks'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'engineering_run:'.data_get($result, 'harness_payload.run.id'),
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'engineering_harness.repair',
        ]);
    }

    public function test_atlas_cli_forge_routes_to_harness_without_task_id(): void
    {
        File::put($this->workspace.'/src/example.txt', "after\n");

        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['Executar', 'forge', 'via', 'harness'],
            '--workspace' => $this->workspace,
            '--forge' => true,
            '--no-run' => true,
            '--test-command' => $this->passingPhpCommand(),
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode(substr($output, (int) strpos($output, '{')), true);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertSame('forge_harness', data_get($payload, 'phase'));
        $this->assertSame('forge', data_get($payload, 'workflow.programming_profile'));
        $this->assertSame('engineering_harness', data_get($payload, 'dev_execution_plan.programming_session_plan.executor_decision.executor'));
        $this->assertSame('atlas.cli_forge.contract.v1', data_get($payload, 'forge_contract.schema_version'));
        $this->assertSame('atlas_cli_forge', data_get($payload, 'forge_contract.surface'));
        $this->assertSame('programming.forge', data_get($payload, 'forge_contract.flow'));
        $this->assertSame('engineering_harness', data_get($payload, 'forge_contract.runtime'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($payload, 'forge_contract.orchestrator'));
        $this->assertSame('engineering_harness', data_get($payload, 'forge_contract.executor'));
        $this->assertSame('atlas_cli_forge', data_get($payload, 'dev_execution_plan.kernel_pipeline.input.surface_id'));
        $this->assertSame('atlas_cli_forge', data_get($payload, 'dev_execution_plan.kernel_pipeline.surface_binding.surface'));
        $this->assertSame('programming.forge', data_get($payload, 'forge_contract.kernel_pipeline_flow'));
        $this->assertSame('engineering_harness', data_get($payload, 'forge_contract.kernel_pipeline_runtime'));
        $this->assertTrue(data_get($payload, 'forge_contract.quality_required'));
        $this->assertTrue(data_get($payload, 'forge_contract.evidence_required'));
        $this->assertSame('passed', data_get($payload, 'programming_result.status'));
        $this->assertSame('engineering_harness', data_get($payload, 'programming_result.executor'));
        $this->assertTrue((bool) data_get($payload, 'programming_result.created_task'));
        $this->assertSame('resolved', data_get($payload, 'programming_result.harness_payload.run.decision'));
        $this->assertNotEmpty(data_get($payload, 'programming_result.evidence_refs'));
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => data_get($payload, 'programming_result.task_id'),
            'title' => 'Executar forge via harness',
        ]);
    }

    public function test_ai_chat_dev_plan_dispatches_forge_message_to_harness(): void
    {
        File::put($this->workspace.'/src/example.txt', "after\n");

        $devPlan = [
            'schema_version' => 1,
            'plan_id' => 'interactive-forge-plan',
            'programming_profile' => 'forge',
            'execution_profile' => [
                'complete' => true,
                'auto_test' => true,
                'max_iterations' => 5,
            ],
            'operator_options' => [
                'harness_overrides' => [
                    'test_command' => $this->passingPhpCommand(),
                    'sandbox' => 'workspace',
                    'quality_scan' => 'off',
                ],
            ],
        ];

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'Executar forge interativo via harness',
            '--workspace' => $this->workspace,
            '--dev' => true,
            '--dev-plan' => json_encode($devPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '--no-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode(substr($output, (int) strpos($output, '{')), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('succeeded', data_get($payload, 'status'));
        $this->assertSame('engineering_harness', data_get($payload, 'provider'));
        $this->assertSame('forge', data_get($payload, 'programming_profile'));
        $this->assertSame('programming_orchestrator_harness', data_get($payload, 'programming_dispatch.dispatch_path'));
        $this->assertSame('engineering_harness', data_get($payload, 'programming_dispatch.executor'));
        $this->assertSame('executed', data_get($payload, 'programming_dispatch.status'));
        $this->assertSame('passed', data_get($payload, 'programming_completion.status'));
        $this->assertSame('engineering_harness', data_get($payload, 'programming_completion.executor'));
        $this->assertSame('programming_orchestrator_harness', data_get($payload, 'programming_completion.dispatch_path'));
        $this->assertSame('interactive-forge-plan', data_get($payload, 'programming_message_plan.parent_plan_id'));
        $this->assertSame('engineering_harness', data_get($payload, 'programming_message_plan.executor_decision.executor'));
        $this->assertSame('passed', data_get($payload, 'programming_result.status'));
        $this->assertSame('resolved', data_get($payload, 'programming_result.harness_payload.run.decision'));
        $this->assertDatabaseHas('atlas_tasks', [
            'id' => data_get($payload, 'programming_result.task_id'),
            'title' => 'Executar forge interativo via harness',
        ]);
    }

    public function test_ai_gateway_projects_provider_dispatch_metadata_for_non_harness_programming_message(): void
    {
        $gateway = app(AiGatewayService::class);
        $method = new \ReflectionMethod($gateway, 'programmingMetadata');
        $method->setAccessible(true);

        $metadata = $method->invoke($gateway, [
            'payload' => [
                'programming_profile' => 'dev',
                'programming_message_plan' => [
                    'plan_id' => 'provider-plan',
                    'parent_plan_id' => 'interactive-dev-plan',
                    'programming_profile' => 'dev',
                    'executor_decision' => [
                        'executor' => 'dev_repair_executor',
                    ],
                    'policy_profile' => [
                        'profile_context' => [
                            'programming' => true,
                            'forge' => false,
                        ],
                        'execution_policy' => [
                            'executor_preference' => 'dev_repair_executor',
                            'max_iterations' => 3,
                            'quality_required' => true,
                        ],
                        'policy_contracts' => [
                            'tools' => ['mode' => 'workspace_write'],
                            'gates' => ['minimum_gate' => 'strict'],
                        ],
                    ],
                ],
                'programming_dispatch' => [
                    'schema_version' => 1,
                    'status' => 'selected',
                    'source' => 'AtlasProgrammingOrchestrator',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'dev_repair_executor',
                    'plan_id' => 'provider-plan',
                ],
                'programming_repair' => [
                    'schema_version' => 1,
                    'status' => 'active',
                    'executor' => 'dev_repair_executor',
                    'enabled' => true,
                ],
            ],
        ]);

        $this->assertSame('dev', data_get($metadata, 'programming_profile'));
        $this->assertSame('ai_gateway_provider', data_get($metadata, 'programming_dispatch.dispatch_path'));
        $this->assertSame('dev_repair_executor', data_get($metadata, 'programming_dispatch.executor'));
        $this->assertTrue(data_get($metadata, 'programming_repair.enabled'));
        $this->assertSame('interactive-dev-plan', data_get($metadata, 'programming_message_plan.parent_plan_id'));
        $this->assertTrue((bool) data_get($metadata, 'programming_profile_context.programming'));
        $this->assertFalse((bool) data_get($metadata, 'programming_profile_context.forge'));
        $this->assertSame('dev_repair_executor', data_get($metadata, 'programming_execution_policy.executor_preference'));
        $this->assertSame(3, data_get($metadata, 'programming_execution_policy.max_iterations'));
        $this->assertSame('workspace_write', data_get($metadata, 'programming_policy_contracts.tools.mode'));
        $this->assertSame('strict', data_get($metadata, 'programming_policy_contracts.gates.minimum_gate'));
    }

    public function test_ai_gateway_projects_context_memory_and_skill_policy_contract_receipt(): void
    {
        $gateway = app(AiGatewayService::class);
        $method = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $method->setAccessible(true);

        $options = $method->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'context' => [
                            'depth' => 'deep',
                            'require_context_pack' => true,
                            'include_memory' => true,
                        ],
                        'memory' => [
                            'scope' => 'deep',
                            'privacy_gate' => 'provider_safe',
                            'recall' => ['thread', 'project', 'semantic'],
                        ],
                        'skills' => [
                            'mode' => 'domain',
                            'required_bundles' => ['programming'],
                            'require_skill_trace' => true,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: [
                'context_pack_id' => 'pack-1',
                'hash' => 'hash-1',
            ],
            activatedSkills: [
                ['name' => 'programming', 'sha256' => 'skill-hash'],
            ],
            openBrainInjection: [
                'status' => 'injected',
            ],
        ));

        $receipt = data_get($options, 'payload.programming_policy_contract_receipt');

        $this->assertSame('satisfied', data_get($receipt, 'context.status'));
        $this->assertSame('pack-1', data_get($receipt, 'context.context_pack_id'));
        $this->assertSame('satisfied', data_get($receipt, 'memory.status'));
        $this->assertSame('injected', data_get($receipt, 'memory.open_brain_status'));
        $this->assertSame('deep', data_get($receipt, 'memory.scope'));
        $this->assertSame('satisfied', data_get($receipt, 'skills.status'));
        $this->assertSame(['programming'], data_get($receipt, 'skills.activated'));
        $this->assertSame([], data_get($receipt, 'skills.missing'));
    }

    public function test_prompt_builder_enables_open_brain_auto_when_programming_contract_requires_memory(): void
    {
        $builder = app(AiPromptBuilder::class);
        $method = new \ReflectionMethod($builder, 'optionsWithPolicyRequiredOpenBrain');
        $method->setAccessible(true);

        $options = $method->invoke($builder, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'context' => [
                            'include_memory' => true,
                        ],
                        'memory' => [
                            'scope' => 'deep',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('auto', data_get($options, 'open_brain.mode'));
    }

    public function test_ai_gateway_blocks_missing_required_context_pack(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingContextContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'context' => [
                            'require_context_pack' => true,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: [],
            activatedSkills: [],
            openBrainInjection: [],
        ));

        $this->assertSame('missing', data_get($options, 'payload.programming_policy_contract_receipt.context.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_context_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_blocks_missing_required_open_brain_memory(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingMemoryContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'context' => [
                            'include_memory' => true,
                        ],
                        'memory' => [
                            'scope' => 'deep',
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: [],
        ));

        $this->assertSame('missing', data_get($options, 'payload.programming_policy_contract_receipt.memory.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_memory_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_allows_degraded_open_brain_memory_when_required(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingMemoryContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'context' => [
                            'include_memory' => true,
                        ],
                        'memory' => [
                            'scope' => 'project',
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: [
                'status' => 'degraded',
                'warnings' => ['no_provider_safe_memory_refs'],
            ],
        ));

        $this->assertSame('degraded', data_get($options, 'payload.programming_policy_contract_receipt.memory.status'));

        $assertMethod->invoke($gateway, $options);
        $this->assertTrue(true);
    }

    public function test_ai_gateway_blocks_write_permission_when_tool_contract_is_read_only(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'write',
                    'confirmed' => true,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'read_only',
                            'workspace_write' => false,
                            'destructive_requires_approval' => true,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        $this->assertSame('workspace_write_blocked', data_get($options, 'payload.programming_policy_contract_receipt.tools.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_tool_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_blocks_unconfirmed_danger_permission_when_tool_contract_requires_approval(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'danger',
                    'confirmed' => false,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'workspace_write',
                            'workspace_write' => true,
                            'destructive_requires_approval' => true,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        $this->assertSame('destructive_approval_missing', data_get($options, 'payload.programming_policy_contract_receipt.tools.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_tool_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_blocks_heavy_tool_tier_in_hot_path(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'read',
                    'max_execution_tier' => 'T2',
                    'hot_path' => true,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'read_only',
                            'workspace_write' => false,
                            'max_execution_tier' => 'T1',
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        $this->assertSame('execution_tier_hot_path_blocked', data_get($options, 'payload.programming_policy_contract_receipt.tools.status'));
        $this->assertSame('T2', data_get($options, 'payload.programming_policy_contract_receipt.tools.requested_execution_tier'));
        $this->assertSame('T1', data_get($options, 'payload.programming_policy_contract_receipt.tools.max_execution_tier'));
        $this->assertTrue((bool) data_get($options, 'payload.programming_policy_contract_receipt.tools.hot_path'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_tool_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_records_tool_contract_block_in_kernel_ledger(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'client_id' => 'client-tool-ap14',
            'provider' => 'codex_cli',
            'payload' => [
                'tenant_id' => 'tenant-a',
                'operator_id' => 'operator-a',
                'tool_permissions' => [
                    'mode' => 'read',
                    'max_execution_tier' => 'T2',
                    'hot_path' => true,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'read_only',
                            'workspace_write' => false,
                            'max_execution_tier' => 'T1',
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        try {
            $assertMethod->invoke($gateway, $options);
            $this->fail('Expected tool contract policy violation.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('atlas_tool_contract_policy_violation', $exception->getMessage());
        }

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::OperationBlocked->value)
            ->where('emitter_stage', 'atlas.policy_contract')
            ->latest('occurred_at')
            ->first();

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame('tenant-a', $event->tenant_id);
        $this->assertSame('operator-a', $event->operator_id);
        $this->assertSame('client-tool-ap14', $event->correlation_id);
        $this->assertSame('programming.tools.execution_tier_hot_path_blocked', data_get($event->payload, 'violation_code'));
        $this->assertSame('T2', data_get($event->payload, 'receipt.requested_execution_tier'));
        $this->assertSame('T1', data_get($event->payload, 'receipt.max_execution_tier'));
    }

    public function test_ai_gateway_blocks_tool_tier_above_contract_outside_hot_path(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'read',
                    'max_execution_tier' => 'T3',
                    'hot_path' => false,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'harness',
                            'workspace_write' => true,
                            'max_execution_tier' => 'T2',
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        $this->assertSame('execution_tier_above_contract', data_get($options, 'payload.programming_policy_contract_receipt.tools.status'));
        $this->assertSame('T3', data_get($options, 'payload.programming_policy_contract_receipt.tools.requested_execution_tier'));
        $this->assertSame('T2', data_get($options, 'payload.programming_policy_contract_receipt.tools.max_execution_tier'));
        $this->assertFalse((bool) data_get($options, 'payload.programming_policy_contract_receipt.tools.hot_path'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_tool_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_allows_confirmed_write_permission_when_tool_contract_allows_workspace_write(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'write',
                    'confirmed' => true,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'workspace_write',
                            'workspace_write' => true,
                            'destructive_requires_approval' => true,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        $this->assertSame('satisfied', data_get($options, 'payload.programming_policy_contract_receipt.tools.status'));

        $assertMethod->invoke($gateway, $options);
        $this->assertTrue(true);
    }

    public function test_ai_gateway_does_not_block_tool_permission_when_no_tool_contract_exists(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingToolContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'write',
                    'confirmed' => true,
                ],
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'context' => [
                            'require_context_pack' => false,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: ['schema_version' => 1],
            activatedSkills: [],
            openBrainInjection: ['status' => 'injected'],
        ));

        $this->assertFalse((bool) data_get($options, 'payload.programming_policy_contract_receipt.tools.required'));
        $this->assertSame('satisfied', data_get($options, 'payload.programming_policy_contract_receipt.tools.status'));
        $this->assertSame('write', data_get($options, 'payload.programming_policy_contract_receipt.tools.requested_permission_mode'));

        $assertMethod->invoke($gateway, $options);
        $this->assertTrue(true);
    }

    public function test_prompt_builder_activates_skill_bundles_required_by_programming_contracts(): void
    {
        $builder = app(AiPromptBuilder::class);
        $method = new \ReflectionMethod($builder, 'requestedBundleSkills');
        $method->setAccessible(true);

        $skills = $method->invoke($builder, [
            'activated_skills' => ['comunicador-claro'],
            'programming_message_plan' => [
                'policy_contracts' => [
                    'skills' => [
                        'required_bundles' => ['dev-quality-gate', 'engineering-blueprint'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['comunicador-claro', 'dev-quality-gate', 'engineering-blueprint'], $skills);
    }

    public function test_ai_gateway_blocks_missing_required_skill_trace(): void
    {
        $gateway = app(AiGatewayService::class);
        $promptMethod = new \ReflectionMethod($gateway, 'optionsWithPromptContracts');
        $promptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingSkillContractsAllowRuntime');
        $assertMethod->setAccessible(true);

        $options = $promptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'skills' => [
                            'mode' => 'domain',
                            'required_bundles' => ['dev-quality-gate'],
                            'require_skill_trace' => true,
                        ],
                    ],
                ],
            ],
        ], new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'dev',
            skillVersions: [],
            contextRefs: [],
            contextPack: [],
            activatedSkills: [],
            openBrainInjection: [],
        ));

        $this->assertSame('partial', data_get($options, 'payload.programming_policy_contract_receipt.skills.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_skill_contract_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_projects_model_graph_policy_contract_receipt(): void
    {
        $gateway = app(AiGatewayService::class);
        $method = new \ReflectionMethod($gateway, 'optionsWithProgrammingModelGraphReceipt');
        $method->setAccessible(true);

        $options = $method->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'model_graph' => [
                            'graph' => 'scout_execute_review',
                            'preset' => 'quality',
                            'nodes' => [
                                [
                                    'id' => 'scout',
                                    'role' => 'scout',
                                    'provider' => 'gemini_cli',
                                    'model' => 'gemini-3.1-pro-preview',
                                    'fallback_order' => ['codex_cli'],
                                ],
                                [
                                    'id' => 'executor',
                                    'role' => 'executor',
                                    'provider' => 'codex_cli',
                                    'model' => 'gpt-5.5',
                                    'fallback_order' => ['claude_cli'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 'codex_cli', 'gpt-5.5');

        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.model_graph');

        $this->assertSame('satisfied', data_get($receipt, 'status'));
        $this->assertSame('scout_execute_review', data_get($receipt, 'graph'));
        $this->assertSame('quality', data_get($receipt, 'preset'));
        $this->assertSame('executor', data_get($receipt, 'matched_node_id'));
        $this->assertSame('executor', data_get($receipt, 'matched_node_role'));
        $this->assertSame(['gemini_cli', 'codex_cli'], data_get($receipt, 'allowed_providers'));
        $this->assertSame(['codex_cli', 'claude_cli'], data_get($receipt, 'fallback_providers'));
    }

    public function test_ai_gateway_blocks_model_graph_out_of_contract_runtime(): void
    {
        $gateway = app(AiGatewayService::class);
        $receiptMethod = new \ReflectionMethod($gateway, 'optionsWithProgrammingModelGraphReceipt');
        $receiptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingModelGraphAllowsRuntime');
        $assertMethod->setAccessible(true);

        $options = $receiptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'model_graph' => [
                            'graph' => 'single',
                            'nodes' => [
                                [
                                    'id' => 'executor',
                                    'role' => 'executor',
                                    'provider' => 'codex_cli',
                                    'model' => 'gpt-5.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 'gemini_cli', 'gemini-3.1-pro-preview');

        $this->assertSame('out_of_contract', data_get($options, 'payload.programming_policy_contract_receipt.model_graph.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_model_graph_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_blocks_strict_model_graph_model_drift(): void
    {
        $gateway = app(AiGatewayService::class);
        $receiptMethod = new \ReflectionMethod($gateway, 'optionsWithProgrammingModelGraphReceipt');
        $receiptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingModelGraphAllowsRuntime');
        $assertMethod->setAccessible(true);

        $options = $receiptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'model_graph' => [
                            'graph' => 'single',
                            'preset' => 'fixed',
                            'strict_model_match' => true,
                            'nodes' => [
                                [
                                    'id' => 'executor',
                                    'role' => 'executor',
                                    'provider' => 'codex_cli',
                                    'model' => 'gpt-5.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 'codex_cli', 'gpt-5.3-codex-spark');

        $this->assertSame('provider_matched_model_drift', data_get($options, 'payload.programming_policy_contract_receipt.model_graph.status'));
        $this->assertTrue((bool) data_get($options, 'payload.programming_policy_contract_receipt.model_graph.strict_model_match'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modelo gpt-5.3-codex-spark diverge do model_graph fixo');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_ai_gateway_allows_council_when_model_graph_allows_runtime_providers(): void
    {
        $gateway = app(AiGatewayService::class);
        $receiptMethod = new \ReflectionMethod($gateway, 'optionsWithProgrammingModelGraphReceipt');
        $receiptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingModelGraphAllowsRuntime');
        $assertMethod->setAccessible(true);

        $options = $receiptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'model_graph' => [
                            'graph' => 'council_review',
                            'nodes' => [
                                [
                                    'id' => 'executor',
                                    'role' => 'executor',
                                    'provider' => 'codex_cli',
                                    'model' => 'gpt-5.5',
                                ],
                                [
                                    'id' => 'reviewer',
                                    'role' => 'reviewer',
                                    'provider' => 'claude_cli',
                                    'model' => 'claude-opus-4-1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 'claude_codex', 'claude_codex', ['claude_cli', 'codex_cli']);

        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.model_graph');

        $this->assertSame('satisfied', data_get($receipt, 'status'));
        $this->assertSame('council_runtime', data_get($receipt, 'matched_node_id'));
        $this->assertSame('council', data_get($receipt, 'matched_node_role'));
        $this->assertSame(['claude_cli', 'codex_cli'], data_get($receipt, 'runtime_providers'));

        $assertMethod->invoke($gateway, $options);
        $this->assertTrue(true);
    }

    public function test_ai_gateway_blocks_council_when_model_graph_excludes_runtime_provider(): void
    {
        $gateway = app(AiGatewayService::class);
        $receiptMethod = new \ReflectionMethod($gateway, 'optionsWithProgrammingModelGraphReceipt');
        $receiptMethod->setAccessible(true);
        $assertMethod = new \ReflectionMethod($gateway, 'assertProgrammingModelGraphAllowsRuntime');
        $assertMethod->setAccessible(true);

        $options = $receiptMethod->invoke($gateway, [
            'payload' => [
                'programming_message_plan' => [
                    'policy_contracts' => [
                        'model_graph' => [
                            'graph' => 'codex_only',
                            'nodes' => [
                                [
                                    'id' => 'executor',
                                    'role' => 'executor',
                                    'provider' => 'codex_cli',
                                    'model' => 'gpt-5.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 'claude_codex', 'claude_codex', ['claude_cli', 'codex_cli']);

        $this->assertSame('out_of_contract', data_get($options, 'payload.programming_policy_contract_receipt.model_graph.status'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_model_graph_policy_violation');

        $assertMethod->invoke($gateway, $options);
    }

    public function test_engineering_run_can_be_replayed_safely_from_existing_run(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $source = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'sandbox' => 'workspace',
        ]);
        $sourceRunId = (string) data_get($source, 'run.id');

        $response = $this->postJson("/engineering/runs/{$sourceRunId}/replay", [
            'workspace' => $this->workspace,
            'auto_test' => true,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('run.replay.source_run_id', $sourceRunId)
            ->assertJsonPath('run.replay.mode', 'sensor_replay')
            ->assertJsonPath('run.workspace.mode', 'worktree')
            ->assertJsonPath('run.control_results.0.id', fn ($id) => is_string($id) && $id !== '');

        $replayRunId = (string) data_get($response->json(), 'run.id');
        $this->assertNotSame($sourceRunId, $replayRunId);
        $this->assertContains('harness_replay_contract', collect(data_get($response->json(), 'run.control_results', []))->pluck('control_slug')->all());

        $replayRun = AtlasEngineeringRun::query()->findOrFail($replayRunId);
        $this->assertSame($sourceRunId, data_get($replayRun->metadata, 'replay.source_run_id'));
        $this->assertSame('sensor_replay', data_get($replayRun->metadata, 'replay.mode'));
        $this->assertFalse((bool) data_get($replayRun->provider_strategy_json, 'replay.provider_replay'));

        $exitCode = Artisan::call('atlas:engineering:replay', [
            'run' => $sourceRunId,
            '--workspace' => $this->workspace,
            '--auto-test' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode(substr($output, (int) strpos($output, '{')), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame($sourceRunId, data_get($payload, 'run.replay.source_run_id'));
        $this->assertSame('sensor_replay', data_get($payload, 'run.replay.mode'));

        $sourceAttemptId = (string) data_get($source, 'run.attempts.0.id');
        $attemptResponse = $this->postJson("/engineering/runs/{$sourceRunId}/attempts/{$sourceAttemptId}/replay", [
            'workspace' => $this->workspace,
            'auto_test' => true,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('run.replay.scope', 'attempt')
            ->assertJsonPath('run.replay.source_run_id', $sourceRunId)
            ->assertJsonPath('run.replay.source_attempt_id', $sourceAttemptId)
            ->assertJsonPath('run.replay.source_attempt_number', 1);
        $this->assertNotSame($sourceRunId, (string) data_get($attemptResponse->json(), 'run.id'));

        $exitCode = Artisan::call('atlas:engineering:replay', [
            'run' => $sourceRunId,
            '--attempt' => 1,
            '--workspace' => $this->workspace,
            '--auto-test' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('attempt', data_get($payload, 'run.replay.scope'));
        $this->assertSame(1, data_get($payload, 'run.replay.source_attempt_number'));
    }

    public function test_engineering_replay_apply_isolated_patch_requires_registered_awis_workspace(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $source = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'sandbox' => 'workspace',
        ]);
        $sourceRunId = (string) data_get($source, 'run.id');

        $exitCode = Artisan::call('atlas:engineering:replay', [
            'run' => $sourceRunId,
            '--workspace' => $this->workspace,
            '--apply-isolated-patch' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', data_get($payload, 'status'));
        $this->assertSame('awis_workspace_required_for_replay_patch', data_get($payload, 'error'));
        $this->assertSame('workspace_not_registered', data_get($payload, 'workspace_resolution.reason'));
    }

    public function test_operator_actions_transition_runs_and_remain_auditable(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace.':operator'),
            'workspace_label' => 'operator-actions',
            'provider_strategy_json' => ['mode' => 'host'],
            'status' => 'running',
            'decision' => null,
            'max_attempts' => 1,
            'attempt_count' => 1,
            'started_at' => now(),
            'metadata' => [],
        ]);
        AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'phase' => 'edit',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $this->postJson("/engineering/runs/{$run->id}/cancel", [
            'actor' => 'test-operator',
            'note' => 'Stop unsafe run.',
            'payload' => ['source' => 'feature_test'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('operator_action.action', 'cancel')
            ->assertJsonPath('operator_action.status_before', 'running')
            ->assertJsonPath('operator_action.status_after', 'cancelled')
            ->assertJsonPath('run.status', 'cancelled')
            ->assertJsonPath('run.decision', 'unresolved')
            ->assertJsonPath('run.operator_actions.0.action', 'cancel')
            ->assertJsonPath('run.timeline.0.type', 'attempt');

        $this->assertDatabaseHas('atlas_engineering_run_operator_actions', [
            'engineering_run_id' => $run->id,
            'action' => 'cancel',
            'actor' => 'test-operator',
            'status_after' => 'cancelled',
            'decision_after' => 'unresolved',
        ]);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'status' => 'cancelled',
        ]);

        $response = $this->postJson("/engineering/runs/{$run->id}/operator-action", [
            'action' => 'accept',
            'actor' => 'qa',
            'note' => 'Human QA approved the result.',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('operator_action.status_before', 'cancelled')
            ->assertJsonPath('operator_action.status_after', 'passed')
            ->assertJsonPath('run.status', 'passed')
            ->assertJsonPath('run.decision', 'resolved');

        $this->assertContains('accept', collect(data_get($response->json(), 'run.operator_actions', []))->pluck('action')->all());
        $this->assertContains('cancel', collect(data_get($response->json(), 'run.operator_actions', []))->pluck('action')->all());

        $summary = app(EngineeringHarnessRunnerService::class)->runSummary($run->refresh());
        $this->assertContains('operator_action', collect($summary['timeline'])->pluck('type')->all());
        $this->assertSame(2, AtlasEngineeringRunOperatorAction::query()->where('engineering_run_id', $run->id)->count());
    }

    public function test_harnessability_calibration_persists_thresholds_and_feeds_autonomy_policy(): void
    {
        $task = $this->task();

        foreach (range(1, 5) as $index) {
            $run = AtlasEngineeringRun::query()->create([
                'task_id' => $task->id,
                'workspace_path_hash' => hash('sha256', $this->workspace.':calibration:'.$index),
                'workspace_label' => 'calibration-'.$index,
                'provider_strategy_json' => ['mode' => 'no_provider'],
                'harnessability_score' => 85,
                'status' => 'passed',
                'decision' => 'resolved',
                'score' => 95,
                'max_attempts' => 1,
                'attempt_count' => 1,
                'started_at' => now()->subMinutes(10 - $index),
                'finished_at' => now()->subMinutes(9 - $index),
                'metadata' => [],
            ]);

            AtlasEngineeringControlResult::query()->create([
                'engineering_run_id' => $run->id,
                'control_slug' => 'synthetic_historical_quality_debt',
                'status' => 'failed',
                'signal_summary' => 'Synthetic historical debt for calibration.',
                'duration_ms' => 1,
                'metadata' => ['required' => true],
            ]);
        }

        $this->postJson('/engineering/harnessability/calibrate', [
            'limit' => 50,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('harnessability_calibration.sample_count', 5)
            ->assertJsonPath('harnessability_calibration.confidence', 'limited')
            ->assertJsonPath('harnessability_calibration.recommended_thresholds.policy_source', 'historical_calibration')
            ->assertJsonPath('harnessability_calibration.recommended_thresholds.high_min_score', 85)
            ->assertJsonPath('harnessability_calibration.recommended_thresholds.require_worktree_below_score', 85)
            ->assertJsonPath('harnessability_calibration.bucket_metrics.high.quality_debt_rate', 100);

        $this->assertDatabaseHas('atlas_engineering_harnessability_calibrations', [
            'sample_limit' => 50,
            'sample_count' => 5,
            'confidence' => 'limited',
        ]);

        $this->getJson('/engineering/harnessability/calibration', $this->headers)
            ->assertOk()
            ->assertJsonPath('harnessability_calibration.recommended_thresholds.danger_permission_min_score', 85);

        File::put($this->workspace.'/src/example.txt', "after\n");
        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'sandbox' => 'workspace',
            'permission' => 'danger',
            'provider_runtime' => 'docker',
            'provider_docker_compose_file' => $this->workspace.'/missing-compose.yml',
            'auto_test' => false,
            'max_attempts' => 3,
        ]);

        $this->assertSame('worktree', data_get($payload, 'run.autonomy_policy.effective_sandbox'));
        $this->assertSame('write', data_get($payload, 'run.autonomy_policy.effective_permission'));
        $this->assertSame('historical_calibration', data_get($payload, 'run.autonomy_policy.thresholds.policy_source'));
        $this->assertSame(85, data_get($payload, 'run.autonomy_policy.thresholds.danger_permission_min_score'));

        $exitCode = Artisan::call('atlas:engineering:harnessability:calibrate', [
            '--limit' => 50,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(6, data_get($payload, 'harnessability_calibration.sample_count'));
        $this->assertSame('historical_calibration', data_get($payload, 'harnessability_calibration.recommended_thresholds.policy_source'));
    }

    public function test_control_registry_versions_definition_changes(): void
    {
        $registry = app(EngineeringControlRegistryService::class);
        $control = [
            'slug' => 'custom_quality_gate',
            'name' => 'Custom quality gate',
            'direction' => 'feedback',
            'execution_type' => 'computational',
            'regulation_category' => 'maintainability',
            'timing' => 'post_attempt',
            'required' => true,
            'risk_level' => 'medium',
            'applies_when' => ['stack' => 'node'],
            'failure_policy' => 'blocks_resolved',
            'command' => 'npm test',
            'metadata' => [
                'profile' => 'unit_test',
                'task_id' => 'volatile-task-id',
            ],
        ];

        $first = $registry->persistControls([$control])->first();
        $same = $registry->persistControls([array_merge($control, [
            'metadata' => array_merge($control['metadata'], ['task_id' => 'another-volatile-task-id']),
        ])])->first();
        $changed = $registry->persistControls([array_merge($control, [
            'command' => 'npm run test:ci',
        ])])->first();

        $this->assertSame(1, $first?->version);
        $this->assertSame(1, $same?->version);
        $this->assertSame($first?->definition_hash, $same?->definition_hash);
        $this->assertSame(2, $changed?->version);
        $this->assertNotSame($first?->definition_hash, $changed?->definition_hash);
        $this->assertSame(2, AtlasEngineeringControlRevision::query()
            ->where('slug', 'custom_quality_gate')
            ->count());
        $this->assertDatabaseHas('atlas_engineering_control_revisions', [
            'slug' => 'custom_quality_gate',
            'version' => 2,
            'definition_hash' => $changed?->definition_hash,
        ]);
    }

    public function test_engineering_run_api_creates_and_lists_runs(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $response = $this->postJson("/tasks/{$task->id}/engineering/runs", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('run.decision', 'resolved');

        $runId = data_get($response->json(), 'run.id');
        $patchId = (string) data_get($response->json(), 'run.patch_artifacts.0.id');
        $this->getJson("/tasks/{$task->id}/engineering/runs", $this->headers)
            ->assertOk()
            ->assertJsonPath('runs.0.id', $runId);
        $this->getJson("/engineering/runs/{$runId}", $this->headers)
            ->assertOk()
            ->assertJsonPath('run.id', $runId)
            ->assertJsonPath('run.decision', 'resolved')
            ->assertJsonPath('run.attempts.0.status', 'completed')
            ->assertJsonPath('run.patch_artifacts.0.changed_files.0', 'src/example.txt')
            ->assertJsonPath('run.patch_artifacts.0.integrity.checked', true)
            ->assertJsonPath('run.patch_artifacts.0.integrity.exists', true)
            ->assertJsonPath('run.patch_artifacts.0.integrity.hash_matches', true)
            ->assertJsonPath('run.control_results.0.id', fn ($id) => is_string($id) && $id !== '')
            ->assertJsonPath('run.control_results.0.control_version', fn ($version) => is_int($version) && $version >= 1)
            ->assertJsonPath('run.control_results.0.control_definition_hash', fn ($hash) => is_string($hash) && strlen($hash) === 64)
            ->assertJsonPath('run.test_runs.0.stdout_excerpt', null)
            ->assertJsonPath('run.test_runs.0.metadata.test_case_type', 'unit');
        $diffResponse = $this->getJson("/engineering/runs/{$runId}/patch-artifacts/{$patchId}/diff", $this->headers)
            ->assertOk()
            ->assertJsonPath('patch_artifact.id', $patchId)
            ->assertJsonPath('patch_artifact.hash_matches', true)
            ->assertJsonPath('diff.source', fn ($source) => in_array($source, ['diff_path', 'excerpt'], true))
            ->assertJsonPath('diff.truncated', false);
        $this->assertStringContainsString('src/example.txt', (string) data_get($diffResponse->json(), 'diff.content'));

        $outsidePatch = AtlasEngineeringPatchArtifact::query()->create([
            'engineering_run_id' => $runId,
            'diff_path' => __FILE__,
            'changed_files_json' => [],
            'created_files_json' => [],
            'deleted_files_json' => [],
            'risk_flags_json' => [],
            'metadata' => [],
        ]);
        $this->getJson("/engineering/runs/{$runId}/patch-artifacts/{$outsidePatch->id}/diff", $this->headers)
            ->assertForbidden();
        $controlsResponse = $this->getJson('/engineering/controls', $this->headers)
            ->assertOk()
            ->assertJsonPath('controls.0.definition_hash', fn ($hash) => is_string($hash) && strlen($hash) === 64);
        $blueprintControl = collect(data_get($controlsResponse->json(), 'controls', []))
            ->firstWhere('slug', 'engineering_blueprint_snapshot');
        $this->assertSame(1, data_get($blueprintControl, 'version'));
        $this->getJson('/engineering/harnessability?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('workspace', $this->workspace);

        $findingResponse = $this->postJson("/engineering/runs/{$runId}/review-findings", [
            'severity' => 'p1',
            'title' => 'Missing regression assertion',
            'body' => 'The implementation needs a regression test before this can be considered resolved.',
            'file_path' => 'src/example.txt',
            'start_line' => 1,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('summary.blocking_count', 1);

        $findingId = data_get($findingResponse->json(), 'finding.id');
        $this->getJson("/engineering/runs/{$runId}/review-findings", $this->headers)
            ->assertOk()
            ->assertJsonPath('summary.open_count', 1)
            ->assertJsonPath('findings.0.id', $findingId);
        $this->getJson("/engineering/runs/{$runId}", $this->headers)
            ->assertOk()
            ->assertJsonPath('run.review_summary.open_count', 1)
            ->assertJsonPath('run.review_findings.0.body', 'The implementation needs a regression test before this can be considered resolved.');
        $this->patchJson("/engineering/review-findings/{$findingId}", [
            'status' => 'resolved',
            'resolution' => ['note' => 'Regression coverage added.'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('finding.status', 'resolved');
    }

    public function test_engineering_benchmark_api_and_command_run_suite_cases(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Runner smoke benchmark',
            'default_runner_options' => [
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
            ],
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('suite.slug', 'runner-smoke-benchmark');

        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'runner_smoke',
            'title' => 'Runner smoke',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 95,
            'corpus_tier' => 'release',
            'risk_profile' => 'medium',
            'curation_status' => 'curated',
            'tags' => ['smoke'],
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('case.case_code', 'runner_smoke')
            ->assertJsonPath('case.corpus_tier', 'release')
            ->assertJsonPath('case.risk_profile', 'medium')
            ->assertJsonPath('case.curation_status', 'curated');

        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/corpus/refresh", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('corpus_manifest.total_cases', 1)
            ->assertJsonPath('corpus_manifest.official_subsets.release', 1);

        $runResponse = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('benchmark_run.status', 'passed')
            ->assertJsonPath('benchmark_run.provider', 'no_provider')
            ->assertJsonPath('benchmark_run.model', 'default_model')
            ->assertJsonPath('benchmark_run.trend_status', 'first_baseline')
            ->assertJsonPath('benchmark_run.harness_version', 'runner-v1.visual-smoke')
            ->assertJsonPath('benchmark_run.release_gate_status', 'passed')
            ->assertJsonPath('benchmark_run.release_gate_profile', 'release')
            ->assertJsonPath('benchmark_run.rollout_status', 'release_ready')
            ->assertJsonPath('benchmark_run.total_attempts', 1)
            ->assertJsonPath('benchmark_run.failed_control_count', 0)
            ->assertJsonPath('benchmark_run.failed_test_count', 0)
            ->assertJsonPath('benchmark_run.blocking_review_finding_count', 0)
            ->assertJsonPath('benchmark_run.total_cases', 1)
            ->assertJsonPath('benchmark_run.passed_cases', 1)
            ->assertJsonPath('results.0.passed', true)
            ->assertJsonPath('results.0.case_code', 'runner_smoke')
            ->assertJsonPath('results.0.engineering_run.decision', 'resolved');

        $benchmarkRunId = (string) data_get($runResponse->json(), 'benchmark_run.id');
        $benchmarkRun = AtlasEngineeringBenchmarkRun::query()->findOrFail($benchmarkRunId);
        $this->assertSame('auto', data_get($benchmarkRun->runner_options_json, 'quality_scan'));
        $this->assertSame('standard', data_get($benchmarkRun->runner_options_json, 'quality_profile'));
        $this->assertTrue((bool) data_get($benchmarkRun->runner_options_json, 'quality_changed_only'));

        $this->patchJson("/engineering/benchmarks/runs/{$benchmarkRunId}/outcome", [
            'outcome_status' => 'healthy',
            'outcome_score' => 94,
            'summary' => 'Release monitorado sem regressao.',
            'recorded_by' => 'test',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('benchmark_run.outcome_status', 'healthy')
            ->assertJsonPath('benchmark_run.outcome_score', 94)
            ->assertJsonPath('benchmark_run.rollout_status', 'healthy');

        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/calibrate", [
            'limit' => 50,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('rollout_calibration.total_outcomes', 1)
            ->assertJsonPath('rollout_calibration.bad_outcome_rate', 0)
            ->assertJsonPath('rollout_calibration.recommended_policy.default_status_after_passed_gate', 'release_ready')
            ->assertJsonPath('suite.rollout_calibration.total_outcomes', 1)
            ->assertJsonPath('suite.rollout_policy.default_status_after_passed_gate', 'release_ready');

        $this->getJson("/engineering/benchmarks/runs/{$benchmarkRunId}", $this->headers)
            ->assertOk()
            ->assertJsonPath('benchmark_run.id', $benchmarkRunId)
            ->assertJsonPath('benchmark_run.outcome_status', 'healthy')
            ->assertJsonPath('results.0.status', 'passed');
        $this->getJson("/engineering/benchmarks/suites/{$suiteSlug}/trends", $this->headers)
            ->assertOk()
            ->assertJsonPath('runs.0.id', $benchmarkRunId)
            ->assertJsonPath('series.0.latest_trend_status', 'first_baseline');
        $this->getJson('/engineering/benchmarks/suites', $this->headers)
            ->assertOk()
            ->assertJsonPath('suites.0.slug', $suiteSlug);
        $this->getJson("/tasks/{$task->id}/engineering", $this->headers)
            ->assertOk()
            ->assertJsonPath('benchmark_cases.0.case_code', 'runner_smoke')
            ->assertJsonPath('benchmark_results.0.status', 'passed');

        $exitCode = Artisan::call('atlas:engineering:benchmark', [
            '--suite' => $suiteSlug,
            '--workspace' => $this->workspace,
            '--no-provider' => true,
            '--auto-test' => true,
            '--test-command' => $this->passingPhpCommand(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('passed', data_get($payload, 'benchmark_run.status'));
        $this->assertSame('stable', data_get($payload, 'benchmark_run.trend_status'));
        $this->assertSame('passed', data_get($payload, 'benchmark_run.release_gate_status'));
        $this->assertSame($benchmarkRunId, data_get($payload, 'benchmark_run.baseline_run_id'));
        $this->assertSame(1, data_get($payload, 'benchmark_run.total_attempts'));
        $this->assertSame(0, data_get($payload, 'benchmark_run.failed_control_count'));
        $this->assertTrue((bool) data_get($payload, 'results.0.passed'));

        $exitCode = Artisan::call('atlas:engineering:benchmark:calibrate', [
            '--suite' => $suiteSlug,
            '--limit' => 50,
            '--json' => true,
        ]);
        $calibrationPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, data_get($calibrationPayload, 'rollout_calibration.total_outcomes'));
        $this->assertSame('healthy', data_get($calibrationPayload, 'rollout_calibration.status'));
        $this->assertSame('release_ready', data_get($calibrationPayload, 'rollout_calibration.recommended_policy.default_status_after_passed_gate'));
        $this->assertDatabaseHas('atlas_engineering_benchmark_results', [
            'case_id' => data_get($runResponse->json(), 'results.0.case_id'),
            'status' => 'passed',
            'passed' => true,
        ]);
    }

    public function test_release_gate_blocks_benchmark_when_quality_debt_exists_even_if_case_passes(): void
    {
        $task = $this->task([
            'goal' => 'Corrigir layout visual no frontend mobile.',
            'acceptance_criteria' => ['Screenshot mobile sem sobreposicao.'],
            'likely_files' => ['src/example.txt'],
            'test_coverage' => ['Comando de validacao passa.'],
        ]);
        File::put($this->workspace.'/src/example.txt', "after\n");

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Release gate benchmark',
            'default_runner_options' => [
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
            ],
        ], $this->headers)
            ->assertCreated();
        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');

        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'visual_partial_expected',
            'title' => 'Visual partial expected',
            'workspace' => $this->workspace,
            'expected_decision' => 'partial',
            'min_score' => 0,
            'tags' => ['release_gate'],
        ], $this->headers)
            ->assertCreated();

        $runResponse = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'release_gate_profile' => 'release',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('benchmark_run.status', 'failed')
            ->assertJsonPath('benchmark_run.passed_cases', 1)
            ->assertJsonPath('benchmark_run.failed_cases', 0)
            ->assertJsonPath('benchmark_run.release_gate_status', 'failed')
            ->assertJsonPath('benchmark_run.release_gate_profile', 'release')
            ->assertJsonPath('benchmark_run.skipped_required_control_count', 1)
            ->assertJsonPath('results.0.passed', true);

        $this->assertDatabaseHas('ai_inbox_items', [
            'type' => 'alert',
            'category' => 'engineering_release_gate',
            'severity' => 'critical',
            'source_type' => 'atlas_engineering_benchmark_run',
            'source_id' => data_get($runResponse->json(), 'benchmark_run.id'),
            'dedupe_key' => 'engineering-release-gate:'.data_get($runResponse->json(), 'benchmark_run.id'),
        ]);
    }

    public function test_fair_claude_benchmark_api_fails_unverified_pass_without_human(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Fair Claude unverified benchmark',
            'default_runner_options' => [
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
            ],
        ], $this->headers)->assertCreated();

        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'fair_unverified',
            'title' => 'Fair unverified',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
            'tags' => ['fair_claude'],
        ], $this->headers)->assertCreated();

        $runResponse = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'claude_only' => true,
            'require_pass_without_human' => false,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('benchmark_run.status', 'failed')
            ->assertJsonPath('benchmark_run.failed_cases', 1)
            ->assertJsonPath('results.0.passed', false)
            ->assertJsonPath('results.0.observed.fair_scorecard.required', true)
            ->assertJsonPath('results.0.observed.fair_scorecard.passed', false);

        $this->assertStringContainsString(
            'fair_scorecard_failed',
            (string) data_get($runResponse->json(), 'results.0.failure_summary'),
        );

        $benchmarkRunId = (string) data_get($runResponse->json(), 'benchmark_run.id');
        $benchmarkRun = AtlasEngineeringBenchmarkRun::query()->findOrFail($benchmarkRunId);
        $this->assertTrue((bool) data_get($benchmarkRun->runner_options_json, 'claude_only'));
        $this->assertTrue((bool) data_get($benchmarkRun->runner_options_json, 'require_pass_without_human'));
        $this->assertSame('claude_cli', data_get($benchmarkRun->runner_options_json, 'provider'));
        $this->assertSame('opus', data_get($benchmarkRun->runner_options_json, 'model'));
        $this->assertSame('fixed', data_get($benchmarkRun->runner_options_json, 'model_policy'));
    }

    public function test_engineering_benchmark_records_claude_code_baseline_plan_when_opted_in(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Claude Code baseline benchmark',
            'default_runner_options' => [
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
            ],
        ], $this->headers)->assertCreated();

        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'claude_code_baseline_plan',
            'title' => 'Claude Code baseline plan',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
            'tags' => ['baseline'],
        ], $this->headers)->assertCreated();

        $runResponse = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'claude_code_baseline' => 'plan',
            'claude_code_baseline_model' => 'opus',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('benchmark_run.status', 'passed')
            ->assertJsonPath('benchmark_run.summary.claude_code_baseline.enabled', true)
            ->assertJsonPath('benchmark_run.summary.claude_code_baseline.planned_count', 1)
            ->assertJsonPath('benchmark_run.summary.paired_scorecard.enabled', true)
            ->assertJsonPath('benchmark_run.summary.paired_scorecard.comparable_count', 0)
            ->assertJsonPath('benchmark_run.summary.paired_scorecard.comparison_statuses.baseline_planned', 1)
            ->assertJsonPath('results.0.observed.claude_code_baseline.status', 'planned')
            ->assertJsonPath('results.0.observed.claude_code_baseline.executed', false)
            ->assertJsonPath('results.0.observed.claude_code_baseline.provider', 'claude_code_cli')
            ->assertJsonPath('results.0.observed.paired_scorecard.comparison_status', 'baseline_planned')
            ->assertJsonPath('results.0.observed.paired_scorecard.comparable', false);

        $this->assertStringContainsString(
            'opus',
            strtolower((string) data_get($runResponse->json(), 'results.0.observed.claude_code_baseline.model')),
        );

        $exitCode = Artisan::call('atlas:engineering:benchmark', [
            '--suite' => $suiteSlug,
            '--workspace' => $this->workspace,
            '--no-provider' => true,
            '--auto-test' => true,
            '--test-command' => $this->passingPhpCommand(),
            '--claude-code-baseline' => 'plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('planned', data_get($payload, 'results.0.observed.claude_code_baseline.status'));
        $this->assertSame('claude_code_cli', data_get($payload, 'results.0.observed.claude_code_baseline.provider'));
        $this->assertSame(1, data_get($payload, 'benchmark_run.summary.claude_code_baseline.planned_count'));
        $this->assertSame(0, data_get($payload, 'benchmark_run.summary.paired_scorecard.comparable_count'));
    }

    public function test_engineering_benchmark_uses_case_test_command_when_runner_override_is_absent(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'case-test-command-default',
            'name' => 'Case test command default',
            'status' => 'active',
            'default_runner_options_json' => [
                'no_provider' => true,
                'auto_test' => true,
            ],
            'metadata' => [],
        ]);
        app(EngineeringBenchmarkService::class)->registerCase($suite, [
            'task_id' => $task->id,
            'case_code' => 'case_test_command_default',
            'title' => 'Case test command default',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
            'task_contract' => [
                'schema_version' => 1,
                'test_commands' => [$this->passingPhpCommand()],
            ],
        ]);

        $exitCode = Artisan::call('atlas:engineering:benchmark', [
            '--suite' => $suite->slug,
            '--workspace' => $this->workspace,
            '--no-provider' => true,
            '--auto-test' => true,
            '--claude-code-baseline' => 'plan',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('passed', data_get($payload, 'benchmark_run.status'));
        $this->assertSame('planned', data_get($payload, 'results.0.observed.claude_code_baseline.status'));
        $this->assertTrue((bool) data_get($payload, 'results.0.observed.claude_code_baseline.replay_packet.deterministic_gate.command_present'));
        $this->assertSame(
            hash('sha256', $this->passingPhpCommand()),
            data_get($payload, 'results.0.observed.claude_code_baseline.replay_packet.deterministic_gate.command_hash'),
        );
    }

    public function test_fair_claude_baseline_only_facade_does_not_require_atlas_fair_scorecard(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");
        $baselineWorkspace = $this->createWorkspace();

        try {
            $suite = AtlasEngineeringBenchmarkSuite::query()->create([
                'slug' => 'fair-baseline-only-facade',
                'name' => 'Fair baseline only facade',
                'status' => 'active',
                'default_runner_options_json' => [
                    'no_provider' => true,
                    'auto_test' => true,
                ],
                'metadata' => [],
            ]);
            app(EngineeringBenchmarkService::class)->registerCase($suite, [
                'task_id' => $task->id,
                'case_code' => 'fair_baseline_only_facade',
                'title' => 'Fair baseline only facade',
                'workspace' => $this->workspace,
                'expected_decision' => 'resolved',
                'min_score' => 85,
                'task_contract' => [
                    'schema_version' => 1,
                    'test_commands' => [$this->passingPhpCommand()],
                ],
            ]);

            $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
                'action' => 'run-claude-code',
                '--suite' => $suite->slug,
                '--workspace' => $this->workspace,
                '--claude-code-baseline-workspace' => $baselineWorkspace,
                '--claude-code-baseline-binary' => '/bin/echo',
                '--gate-profile' => 'off',
                '--json' => true,
            ]);
            $benchmarkRun = AtlasEngineeringBenchmarkRun::query()
                ->where('suite_id', $suite->id)
                ->latest('created_at')
                ->firstOrFail();
            $payload = app(EngineeringBenchmarkService::class)->runPayload($benchmarkRun);

            $this->assertSame(0, $exitCode);
            $this->assertSame('passed', data_get($payload, 'benchmark_run.status'));
            $this->assertNull(data_get($payload, 'results.0.observed.fair_scorecard'));
            $this->assertSame('completed', data_get($payload, 'results.0.observed.claude_code_baseline.status'));
            $this->assertTrue((bool) data_get($payload, 'results.0.observed.claude_code_baseline.pass_without_human'));
        } finally {
            $this->deleteDirectoryQuietly($baselineWorkspace);
        }
    }

    public function test_engineering_benchmark_can_compare_executed_claude_code_baseline_with_deterministic_gate(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");
        $baselineWorkspace = $this->createWorkspace();

        try {
            $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
                'name' => 'Claude Code comparable baseline benchmark',
                'default_runner_options' => [
                    'no_provider' => true,
                    'auto_test' => true,
                    'test_command' => $this->passingPhpCommand(),
                ],
            ], $this->headers)->assertCreated();

            $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
            $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
                'task_id' => $task->id,
                'case_code' => 'claude_code_baseline_run',
                'title' => 'Claude Code baseline run',
                'workspace' => $this->workspace,
                'expected_decision' => 'resolved',
                'min_score' => 85,
                'tags' => ['baseline'],
            ], $this->headers)->assertCreated();

            $runResponse = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
                'workspace' => $this->workspace,
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
                'claude_code_baseline' => 'run',
                'claude_code_baseline_workspace' => $baselineWorkspace,
                'claude_code_baseline_model' => 'opus',
                'claude_code_baseline_binary' => '/bin/echo',
            ], $this->headers)
                ->assertCreated()
                ->assertJsonPath('benchmark_run.status', 'passed')
                ->assertJsonPath('benchmark_run.summary.claude_code_baseline.executed_count', 1)
                ->assertJsonPath('benchmark_run.summary.paired_scorecard.comparable_count', 1)
                ->assertJsonPath('benchmark_run.summary.paired_scorecard.tie_count', 1)
                ->assertJsonPath('benchmark_run.summary.replay_manifest.enabled', true)
                ->assertJsonPath('benchmark_run.summary.replay_manifest.packet_count', 1)
                ->assertJsonPath('benchmark_run.summary.replay_manifest.final_packet.kind', 'engineering_benchmark_final_packet')
                ->assertJsonPath('benchmark_run.summary.replay_manifest.final_packet.status', 'passed')
                ->assertJsonPath('benchmark_run.summary.replay_manifest.final_packet.benchmark_status', 'passed')
                ->assertJsonPath('benchmark_run.summary.replay_manifest.providers.0', 'claude_code_cli')
                ->assertJsonPath('benchmark_run.summary.replay_manifest.model_locks.0', 'opus')
                ->assertJsonPath('benchmark_run.summary.replay_manifest.deterministic_gate_packet_count', 1)
                ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.status', 'persisted')
                ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.integrity.checked', true)
                ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.integrity.exists', true)
                ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.integrity.hash_matches', true)
                ->assertJsonPath('results.0.observed.claude_code_baseline.deterministic_gates_passed', true)
                ->assertJsonPath('results.0.observed.claude_code_baseline.pass_without_human', true)
                ->assertJsonPath('results.0.observed.paired_scorecard.comparison_status', 'comparable')
                ->assertJsonPath('results.0.observed.paired_scorecard.winner', 'tie');

            $benchmarkRun = AtlasEngineeringBenchmarkRun::query()
                ->where('suite_id', data_get($suiteResponse->json(), 'suite.id'))
                ->latest('created_at')
                ->firstOrFail();
            $artifactPath = (string) data_get($benchmarkRun->summary_json, 'replay_manifest.artifact.path');
            $this->assertFileExists($artifactPath);
            $this->assertSame(
                hash('sha256', File::get($artifactPath)),
                data_get($benchmarkRun->summary_json, 'replay_manifest.artifact.sha256'),
            );
            $this->assertSame(
                'atlas benchmark claude-fair replay '.$benchmarkRun->id.' --json',
                data_get($runResponse->json(), 'benchmark_run.summary.replay_manifest.final_packet.replay_command'),
            );

            $manifestResponse = $this->getJson(
                "/engineering/benchmarks/runs/{$benchmarkRun->id}/replay-manifest",
                $this->headers,
            )
                ->assertOk()
                ->assertJsonPath('status', 'available')
                ->assertJsonPath('artifact.status', 'persisted')
                ->assertJsonPath('artifact.integrity.hash_matches', true)
                ->assertJsonPath('summary.enabled', true)
                ->assertJsonPath('summary.packet_count', 1)
                ->assertJsonPath('final_packet.kind', 'engineering_benchmark_final_packet')
                ->assertJsonPath('final_packet.status', 'passed')
                ->assertJsonPath('final_packet.replay_command', 'atlas benchmark claude-fair replay '.$benchmarkRun->id.' --json')
                ->assertJsonPath('replay_manifest.kind', 'engineering_benchmark_replay_manifest')
                ->assertJsonPath('replay_manifest.packet_count', 1)
                ->assertJsonPath('replay_manifest.final_packet.status', 'passed')
                ->assertJsonPath('replay_manifest.providers.0', 'claude_code_cli')
                ->assertJsonPath('replay_manifest.model_locks.0', 'opus');

            $this->assertSame(
                data_get($runResponse->json(), 'benchmark_run.summary.replay_manifest.manifest_hash'),
                data_get($manifestResponse->json(), 'replay_manifest.manifest_hash'),
            );
            $this->assertArrayNotHasKey('path', (array) data_get($manifestResponse->json(), 'artifact', []));
            $this->assertArrayNotHasKey(
                'path',
                (array) data_get($runResponse->json(), 'benchmark_run.summary.replay_manifest.artifact', []),
            );

            $finalPacket = (array) data_get($manifestResponse->json(), 'final_packet', []);
            $this->assertSame('atlas_deterministic', data_get($finalPacket, 'evaluator'));
            $this->assertNotEmpty(data_get($finalPacket, 'final_packet_hash'));
            $this->assertSame(
                hash('sha256', $this->canonicalJsonForHash(Arr::except(
                    Arr::except($finalPacket, ['final_packet_hash']),
                    ['generated_at'],
                ))),
                data_get($finalPacket, 'final_packet_hash'),
            );

            $manifestHash = (string) data_get($manifestResponse->json(), 'replay_manifest.manifest_hash');
            $packetHashes = (array) data_get($manifestResponse->json(), 'replay_manifest.packet_hashes', []);
            $sortedPacketHashes = $packetHashes;
            sort($sortedPacketHashes);
            $this->assertSame(
                hash('sha256', $this->canonicalJsonForHash($sortedPacketHashes)),
                $manifestHash,
            );

            $secondManifestResponse = $this->getJson(
                "/engineering/benchmarks/runs/{$benchmarkRun->id}/replay-manifest",
                $this->headers,
            )->assertOk();
            $this->assertSame(
                $manifestHash,
                data_get($secondManifestResponse->json(), 'replay_manifest.manifest_hash'),
            );
            $this->assertSame(
                data_get($finalPacket, 'final_packet_hash'),
                data_get($secondManifestResponse->json(), 'final_packet.final_packet_hash'),
            );

            $exitCode = Artisan::call('atlas:engineering:benchmark:replay-manifest', [
                'run' => $benchmarkRun->id,
                '--json' => true,
            ]);
            $commandPayload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('available', data_get($commandPayload, 'status'));
            $this->assertSame('passed', data_get($commandPayload, 'final_packet.status'));
            $this->assertSame('atlas benchmark claude-fair replay '.$benchmarkRun->id.' --json', data_get($commandPayload, 'final_packet.replay_command'));
            $this->assertSame('claude_code_cli', data_get($commandPayload, 'replay_manifest.providers.0'));
            $this->assertSame(
                data_get($manifestResponse->json(), 'replay_manifest.manifest_hash'),
                data_get($commandPayload, 'replay_manifest.manifest_hash'),
            );
            $this->assertArrayNotHasKey('path', (array) data_get($commandPayload, 'artifact', []));

            $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
                'action' => 'replay',
                '--run-id' => $benchmarkRun->id,
                '--json' => true,
            ]);
            $facadeReplayOutput = Artisan::output();
            $facadeReplayPayload = json_decode(substr($facadeReplayOutput, (int) strpos($facadeReplayOutput, '{')), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('available', data_get($facadeReplayPayload, 'status'));
            $this->assertSame(
                data_get($manifestResponse->json(), 'replay_manifest.manifest_hash'),
                data_get($facadeReplayPayload, 'replay_manifest.manifest_hash'),
            );

            $reportResponse = $this->getJson(
                "/engineering/benchmarks/suites/{$suiteSlug}/fair-claude-report",
                $this->headers,
            )
                ->assertOk()
                ->assertJsonPath('kind', 'fair_claude_benchmark_report')
                ->assertJsonPath('readiness.status', 'not_ready')
                ->assertJsonPath('readiness.comparable_count', 0)
                ->assertJsonPath('readiness.fair_mode_count', 0)
                ->assertJsonPath('scope.paired_result_count', 1)
                ->assertJsonPath('scope.fair_result_count', 0)
                ->assertJsonPath('scope.non_fair_paired_result_count', 1)
                ->assertJsonPath('paired_scorecard.enabled', false)
                ->assertJsonPath('all_paired_scorecard.comparable_count', 1)
                ->assertJsonPath('all_paired_scorecard.fair_mode_count', 0)
                ->assertJsonPath('all_paired_scorecard.tie_count', 1)
                ->assertJsonPath('claude_code_baseline.enabled', false)
                ->assertJsonPath('replay_manifest.artifact_integrity_failed_count', 0)
                ->assertJsonPath('runs.0.fair_report_scope', 'paired_non_fair');
            $this->assertContains('fair_atlas_arm_missing', data_get($reportResponse->json(), 'readiness.blocking_reasons', []));
            $this->assertSame('blocked', data_get($reportResponse->json(), 'runs.0.history_summary.health_status'));
            $this->assertSame('tie', data_get($reportResponse->json(), 'runs.0.history_summary.winner'));
            $this->assertContains(
                'not_official_fair_claude_scope',
                data_get($reportResponse->json(), 'runs.0.history_summary.blocking_reasons', []),
            );

            $exitCode = Artisan::call('atlas:engineering:benchmark:report', [
                '--suite' => $suiteSlug,
                '--json' => true,
            ]);
            $reportPayload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('fair_claude_benchmark_report', data_get($reportPayload, 'kind'));
            $this->assertSame(
                data_get($reportResponse->json(), 'readiness.status'),
                data_get($reportPayload, 'readiness.status'),
            );
            $this->assertSame(0, data_get($reportPayload, 'scope.fair_result_count'));
            $this->assertSame(1, data_get($reportPayload, 'all_paired_scorecard.comparable_count'));
            $this->assertSame(0, data_get($reportPayload, 'all_paired_scorecard.fair_mode_count'));
            $this->assertFalse((bool) data_get($reportPayload, 'claude_code_baseline.enabled'));
            $this->assertArrayNotHasKey(
                'path',
                (array) data_get($reportPayload, 'runs.0.replay_manifest.artifact', []),
            );

            $exitCode = Artisan::call('atlas:engineering:benchmark:report', [
                '--suite' => $suiteSlug,
            ]);
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Recent Rivals runs', Artisan::output());
        } finally {
            $this->deleteDirectoryQuietly($baselineWorkspace);
        }
    }

    public function test_engineering_benchmark_auto_prepares_isolated_claude_code_baseline_worktree(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Claude Code auto baseline worktree benchmark',
            'default_runner_options' => [
                'no_provider' => true,
                'auto_test' => true,
                'test_command' => $this->passingPhpCommand(),
            ],
        ], $this->headers)->assertCreated();

        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'claude_code_auto_baseline_worktree',
            'title' => 'Claude Code auto baseline worktree',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
            'tags' => ['baseline', 'worktree'],
        ], $this->headers)->assertCreated();

        $response = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'claude_code_baseline' => 'run',
            'claude_code_baseline_model' => 'opus',
            'claude_code_baseline_binary' => '/bin/echo',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('benchmark_run.status', 'passed')
            ->assertJsonPath('benchmark_run.summary.claude_code_baseline.executed_count', 1)
            ->assertJsonPath('benchmark_run.summary.paired_scorecard.comparable_count', 1)
            ->assertJsonPath('benchmark_run.summary.replay_manifest.enabled', true)
            ->assertJsonPath('benchmark_run.summary.replay_manifest.kind', 'engineering_benchmark_replay_manifest')
            ->assertJsonPath('benchmark_run.summary.replay_manifest.packet_count', 1)
            ->assertJsonPath('benchmark_run.summary.replay_manifest.modes.run', 1)
            ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.status', 'persisted')
            ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.integrity.checked', true)
            ->assertJsonPath('benchmark_run.summary.replay_manifest.artifact.integrity.hash_matches', true)
            ->assertJsonPath('results.0.observed.claude_code_baseline.deterministic_gates_passed', true)
            ->assertJsonPath('results.0.observed.claude_code_baseline.pass_without_human', true)
            ->assertJsonPath('results.0.observed.paired_scorecard.comparison_status', 'comparable')
            ->assertJsonPath('results.0.observed.paired_workspaces.mode', 'paired_git_worktree')
            ->assertJsonPath('results.0.observed.paired_workspaces.auto_prepared', true)
            ->assertJsonPath('results.0.observed.paired_workspaces.claude_code_baseline.status', 'ready')
            ->assertJsonPath('results.0.observed.paired_workspaces.claude_code_baseline.isolated', true)
            ->assertJsonPath('results.0.observed.paired_workspaces.claude_code_baseline.isolation_type', 'git_worktree')
            ->assertJsonPath('results.0.observed.paired_workspaces.claude_code_baseline.release.status', 'released');

        $this->assertNotEmpty(data_get($response->json(), 'results.0.observed.paired_workspaces.claude_code_baseline.execution_workspace_hash'));
        $this->assertNotEmpty(data_get($response->json(), 'results.0.observed.paired_workspaces.claude_code_baseline.worktree_path_hash'));
        $this->assertNotEmpty(data_get($response->json(), 'benchmark_run.summary.replay_manifest.manifest_hash'));
        $this->assertNotEmpty(data_get($response->json(), 'benchmark_run.summary.replay_manifest.packets.0.packet_hash'));
        $this->assertArrayNotHasKey(
            'path',
            (array) data_get($response->json(), 'benchmark_run.summary.replay_manifest.artifact', []),
        );
        $benchmarkRun = AtlasEngineeringBenchmarkRun::query()
            ->where('id', (string) data_get($response->json(), 'benchmark_run.id'))
            ->firstOrFail();
        $this->assertFileExists((string) data_get($benchmarkRun->summary_json, 'replay_manifest.artifact.path'));
        $this->assertArrayNotHasKey(
            'execution_workspace',
            (array) data_get($response->json(), 'results.0.observed.paired_workspaces.claude_code_baseline', []),
        );
        $this->assertArrayNotHasKey(
            'execution_workspace',
            (array) data_get($response->json(), 'results.0.observed.paired_workspaces.claude_code_baseline.release', []),
        );

        $baselineExecutionHash = (string) data_get(
            $response->json(),
            'results.0.observed.paired_workspaces.claude_code_baseline.execution_workspace_hash'
        );
        $atlasOriginalHash = (string) data_get(
            $response->json(),
            'results.0.observed.paired_workspaces.claude_code_baseline.original_workspace_hash'
        );
        $this->assertNotSame('', $baselineExecutionHash);
        $this->assertNotSame('', $atlasOriginalHash);
        $this->assertNotSame(
            $atlasOriginalHash,
            $baselineExecutionHash,
            'Atlas arm and Claude Code baseline arm must run on isolated workspace paths.'
        );
    }

    public function test_claude_code_baseline_rejects_nested_workspace_as_fair_mode_violation(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");
        $nestedWorkspace = $this->workspace.'/baseline-child';
        File::ensureDirectoryExists($nestedWorkspace);

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Claude Code nested baseline rejection benchmark',
            'default_runner_options' => [],
        ], $this->headers)->assertCreated();

        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'claude_code_nested_baseline_workspace',
            'title' => 'Claude Code nested baseline workspace',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
            'tags' => ['baseline', 'workspace_isolation'],
        ], $this->headers)->assertCreated();

        $response = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'claude_code_baseline' => 'run',
            'claude_code_baseline_workspace' => $nestedWorkspace,
            'claude_code_baseline_model' => 'opus',
            'claude_code_baseline_binary' => '/bin/echo',
        ], $this->headers)->assertCreated();

        $this->assertSame('failed', data_get($response->json(), 'benchmark_run.status'));
        $this->assertStringContainsString(
            'fair_mode_violation: Claude Code baseline run requires a workspace isolated from the Atlas arm.',
            (string) data_get($response->json(), 'results.0.failure_summary')
        );
    }

    public function test_claude_code_baseline_rejects_missing_explicit_workspace_before_process_start(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");
        $missingWorkspace = $this->workspace.'-missing-baseline';

        $suiteResponse = $this->postJson('/engineering/benchmarks/suites', [
            'name' => 'Claude Code missing baseline rejection benchmark',
            'default_runner_options' => [],
        ], $this->headers)->assertCreated();

        $suiteSlug = (string) data_get($suiteResponse->json(), 'suite.slug');
        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases", [
            'task_id' => $task->id,
            'case_code' => 'claude_code_missing_baseline_workspace',
            'title' => 'Claude Code missing baseline workspace',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
            'tags' => ['baseline', 'workspace_isolation'],
        ], $this->headers)->assertCreated();

        $response = $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/run", [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'claude_code_baseline' => 'run',
            'claude_code_baseline_workspace' => $missingWorkspace,
            'claude_code_baseline_model' => 'opus',
            'claude_code_baseline_binary' => '/bin/echo',
        ], $this->headers)->assertCreated();

        $this->assertSame('failed', data_get($response->json(), 'benchmark_run.status'));
        $this->assertStringContainsString(
            'fair_mode_violation: Claude Code baseline workspace must exist and be readable before execution.',
            (string) data_get($response->json(), 'results.0.failure_summary')
        );
    }

    public function test_workspace_release_kept_does_not_expose_raw_execution_workspace(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['sandbox' => 'worktree'],
            'status' => 'preparing',
            'max_attempts' => 1,
            'started_at' => now(),
            'metadata' => [],
        ]);

        $plan = app(EngineeringWorkspaceService::class)->prepare($this->workspace, $run, ['sandbox' => 'worktree']);
        $this->assertTrue((bool) $plan['isolated']);
        $executionWorkspace = (string) $plan['execution_workspace'];

        try {
            $release = app(EngineeringWorkspaceService::class)->release($plan, true);

            $this->assertSame('kept', $release['status']);
            $this->assertArrayNotHasKey('execution_workspace', $release);
            $this->assertSame(hash('sha256', $executionWorkspace), $release['execution_workspace_hash']);
        } finally {
            app(EngineeringWorkspaceService::class)->release($plan);
        }
    }

    public function test_fair_claude_report_limit_applies_after_paired_run_filtering(): void
    {
        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-report-limit-filter',
            'name' => 'Fair report limit filter',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);
        $case = app(EngineeringBenchmarkService::class)->registerCase($suite, [
            'case_code' => 'fair_report_limit',
            'title' => 'Fair report limit',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
        ]);

        foreach (range(1, 120) as $minutes) {
            AtlasEngineeringBenchmarkRun::query()->create([
                'suite_id' => $suite->id,
                'benchmark_key' => 'normal:'.$minutes,
                'provider' => 'codex_cli',
                'model' => 'codex-test',
                'mode' => 'single_shot',
                'status' => 'passed',
                'total_cases' => 1,
                'passed_cases' => 1,
                'failed_cases' => 0,
                'runner_options_json' => [],
                'summary_json' => [],
                'started_at' => now()->subMinutes($minutes),
                'finished_at' => now()->subMinutes($minutes),
                'created_at' => now()->subMinutes($minutes),
                'updated_at' => now()->subMinutes($minutes),
                'metadata' => [],
            ]);
        }

        $fairRun = AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'fair:opus',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-test',
            'mode' => 'single_shot',
            'status' => 'passed',
            'total_cases' => 1,
            'passed_cases' => 1,
            'failed_cases' => 0,
            'cost_microusd' => 2500000,
            'runner_options_json' => ['claude_only' => true],
            'summary_json' => [
                'paired_scorecard' => [
                    'enabled' => true,
                    'case_count' => 1,
                    'fair_mode_count' => 1,
                    'comparable_count' => 1,
                    'inconclusive_count' => 0,
                    'winners' => ['atlas' => 1],
                    'atlas_win_count' => 1,
                    'claude_code_baseline_win_count' => 0,
                    'tie_count' => 0,
                ],
            ],
            'started_at' => now()->subMinutes(121),
            'finished_at' => now()->subMinutes(121),
            'created_at' => now()->subMinutes(121),
            'updated_at' => now()->subMinutes(121),
            'metadata' => [],
        ]);
        AtlasEngineeringBenchmarkResult::query()->create([
            'benchmark_run_id' => $fairRun->id,
            'suite_id' => $suite->id,
            'case_id' => $case->id,
            'status' => 'passed',
            'decision' => 'resolved',
            'score' => 95,
            'passed' => true,
            'duration_ms' => 1000,
            'expectation_json' => [],
            'observed_json' => [
                'paired_scorecard' => [
                    'schema_version' => 1,
                    'fair_mode' => true,
                    'comparison_status' => 'comparable',
                    'comparable' => true,
                    'winner' => 'atlas',
                    'atlas' => [
                        'verified' => true,
                        'pass_without_human' => true,
                        'duration_ms' => 1200,
                        'repair_attempt_count' => 1,
                        'repair_used' => true,
                        'converted_to_green' => true,
                    ],
                    'claude_code_baseline' => [
                        'verified' => true,
                        'pass_without_human' => true,
                        'duration_ms' => 1800,
                    ],
                ],
                'claude_code_baseline' => [
                    'enabled' => true,
                    'status' => 'completed',
                    'executed' => true,
                    'provider' => 'claude_code_cli',
                    'model' => 'opus',
                ],
            ],
            'metadata' => [],
        ]);

        $this->getJson("/engineering/benchmarks/suites/{$suite->slug}/fair-claude-report?limit=1", $this->headers)
            ->assertOk()
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('scan_limit', 250)
            ->assertJsonPath('scanned_run_count', 121)
            ->assertJsonPath('scope.paired_run_count', 1)
            ->assertJsonPath('scope.fair_run_count', 1)
            ->assertJsonPath('runs.0.id', $fairRun->id)
            ->assertJsonPath('runs.0.fair_report_scope', 'official_fair_claude')
            ->assertJsonPath('runs.0.history_summary.health_status', 'blocked')
            ->assertJsonPath('runs.0.history_summary.winner', 'atlas')
            ->assertJsonPath('runs.0.history_summary.atlas_win_count', 1)
            ->assertJsonPath('runs.0.history_summary.claude_code_baseline_win_count', 0)
            ->assertJsonPath('runs.0.history_summary.comparable_count', 1)
            ->assertJsonPath('runs.0.history_summary.invalid_case_count', 0)
            ->assertJsonPath('runs.0.history_summary.result_integrity_status', 'comparable_score_blocked')
            ->assertJsonPath('runs.0.history_summary.score_admitted', true)
            ->assertJsonPath('runs.0.history_summary.claim_winner_admitted', false)
            ->assertJsonPath('history_timeline.schema_version', 'atlas.fair_claude.history_timeline.v1')
            ->assertJsonPath('history_timeline.summary.run_count', 1)
            ->assertJsonPath('history_timeline.summary.comparable_case_count', 1)
            ->assertJsonPath('history_timeline.summary.invalid_case_count', 0)
            ->assertJsonPath('history_timeline.entries.0.run_id', $fairRun->id)
            ->assertJsonPath('history_timeline.entries.0.health_status', 'blocked')
            ->assertJsonPath('history_timeline.entries.0.result_integrity_status', 'comparable_score_blocked')
            ->assertJsonPath('history_timeline.entries.0.score_admitted', true)
            ->assertJsonPath('history_timeline.entries.0.claim_winner_admitted', false)
            ->assertJsonPath('runs.0.history_summary.baseline_executed_count', 1)
            ->assertJsonPath('runs.0.history_summary.replay_packet_count', 0)
            ->assertJsonPath('runs.0.history_summary.replay_integrity_failed_count', 0)
            ->assertJsonPath('paired_scorecard.fair_mode_count', 1)
            ->assertJsonPath('paired_scorecard.atlas_win_count', 1)
            ->assertJsonPath('paired_scorecard.pass_without_human_rate', 100)
            ->assertJsonPath('paired_scorecard.baseline_pass_without_human_rate', 100)
            ->assertJsonPath('paired_scorecard.repair_conversion_rate', 100)
            ->assertJsonPath('paired_scorecard.time_to_green.atlas_avg_ms', 1200)
            ->assertJsonPath('paired_scorecard.time_to_green.claude_code_baseline_avg_ms', 1800)
            ->assertJsonPath('paired_scorecard.cost_per_green_case.microusd', 2500000)
            ->assertJsonPath('paired_scorecard.cost_per_green_case.usd', 2.5)
            ->assertJsonPath('paired_scorecard.provider_violation_count', 0)
            ->assertJsonPath('paired_scorecard.fallback_violation_count', 0)
            ->assertJsonPath('scope.case_comparison_count', 1)
            ->assertJsonPath('case_comparisons.0.case_code', 'fair_report_limit')
            ->assertJsonPath('case_comparisons.0.comparison_status', 'comparable')
            ->assertJsonPath('case_comparisons.0.winner', 'atlas')
            ->assertJsonPath('case_comparisons.0.winner_reason', 'atlas_verified_better_or_baseline_failed')
            ->assertJsonPath('case_comparisons.0.atlas.pass_without_human', true)
            ->assertJsonPath('case_comparisons.0.atlas.repair_attempt_count', 1)
            ->assertJsonPath('case_comparisons.0.claude_code_baseline.verified', true)
            ->assertJsonPath('case_comparisons.0.claude_code_baseline.duration_ms', 1800)
            ->assertJsonPath('executive_summary.claim_status', 'atlas_leading_but_blocked')
            ->assertJsonPath('executive_summary.winner', 'atlas')
            ->assertJsonPath('executive_summary.sample.comparable_cases', 1)
            ->assertJsonPath('battery_execution_contract.schema_version', 'atlas.fair_claude.battery_execution_contract.v1')
            ->assertJsonPath('battery_execution_contract.operator_required', true)
            ->assertJsonPath('battery_execution_contract.agent_auto_execution_allowed', false)
            ->assertJsonPath('battery_execution_contract.provider_dispatch_required', true)
            ->assertJsonPath('battery_execution_contract.current_state.comparable_case_count', 1)
            ->assertJsonPath('battery_execution_contract.commands.runbook', 'atlas rivals runbook --json')
            ->assertJsonPath('evidence_packet.kind', 'fair_claude_claim_evidence_packet')
            ->assertJsonPath('evidence_packet.protocol.atlas_provider_lock', 'claude_cli')
            ->assertJsonPath('evidence_packet.protocol.atlas_model_lock', 'opus')
            ->assertJsonPath('evidence_packet.claim.winner', null)
            ->assertJsonPath('evidence_packet.claim.provisional_leader', 'atlas')
            ->assertJsonPath('evidence_packet.claim.claim_winner_admitted', false)
            ->assertJsonPath('evidence_packet.result_integrity.status', 'limited_sample_not_claimable')
            ->assertJsonPath('evidence_packet.result_integrity.winner_for_claim', null)
            ->assertJsonPath('evidence_packet.audit.case_comparison_count', 1)
            ->assertJsonPath('evidence_packet.case_outcomes.0.case_code', 'fair_report_limit')
            ->assertJsonPath('evidence_packet.case_outcomes.0.winner', 'atlas')
            ->assertJsonFragment([
                'id' => 'verify_replay_manifest',
                'severity' => 'critical',
                'command' => 'atlas rivals report --json',
            ]);

        $payload = $this->getJson("/engineering/benchmarks/suites/{$suite->slug}/fair-claude-report?limit=1", $this->headers)
            ->json();
        $this->assertIsString(data_get($payload, 'evidence_packet.evidence_hash'));
        $this->assertSame(64, strlen((string) data_get($payload, 'evidence_packet.evidence_hash')));
        $this->assertStringContainsString('# Atlas Rivals Fair Claude Report', (string) data_get($payload, 'claim_markdown'));
        $this->assertStringContainsString('## Auditability', (string) data_get($payload, 'claim_markdown'));
        $this->assertStringContainsString('fair_report_limit', (string) data_get($payload, 'claim_markdown'));
    }

    public function test_fair_claude_report_detects_legacy_runs_from_result_scorecard(): void
    {
        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-report-legacy-result-scope',
            'name' => 'Fair report legacy result scope',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);
        $case = app(EngineeringBenchmarkService::class)->registerCase($suite, [
            'case_code' => 'fair_report_legacy',
            'title' => 'Fair report legacy',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 85,
        ]);

        $run = AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'fair:legacy',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-test',
            'mode' => 'single_shot',
            'status' => 'passed',
            'total_cases' => 1,
            'passed_cases' => 1,
            'failed_cases' => 0,
            'runner_options_json' => ['claude_only' => true],
            'summary_json' => [
                'paired_scorecard' => [
                    'enabled' => true,
                    'case_count' => 1,
                    'comparable_count' => 1,
                    'inconclusive_count' => 0,
                ],
            ],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
            'metadata' => [],
        ]);

        AtlasEngineeringBenchmarkResult::query()->create([
            'benchmark_run_id' => $run->id,
            'suite_id' => $suite->id,
            'case_id' => $case->id,
            'status' => 'passed',
            'decision' => 'resolved',
            'score' => 95,
            'passed' => true,
            'duration_ms' => 1000,
            'expectation_json' => [],
            'observed_json' => [
                'paired_scorecard' => [
                    'schema_version' => 1,
                    'fair_mode' => true,
                    'comparison_status' => 'comparable',
                    'comparable' => true,
                    'winner' => 'atlas',
                    'atlas' => ['verified' => true],
                    'claude_code_baseline' => ['verified' => true],
                ],
                'claude_code_baseline' => [
                    'enabled' => true,
                    'status' => 'completed',
                    'executed' => true,
                    'provider' => 'claude_code_cli',
                    'model' => 'opus',
                ],
            ],
            'metadata' => [],
        ]);

        $this->getJson("/engineering/benchmarks/suites/{$suite->slug}/fair-claude-report?limit=1", $this->headers)
            ->assertOk()
            ->assertJsonPath('scope.paired_run_count', 1)
            ->assertJsonPath('scope.fair_run_count', 1)
            ->assertJsonPath('scope.fair_result_count', 1)
            ->assertJsonPath('runs.0.id', $run->id)
            ->assertJsonPath('runs.0.fair_report_scope', 'official_fair_claude')
            ->assertJsonPath('paired_scorecard.fair_mode_count', 1)
            ->assertJsonPath('paired_scorecard.atlas_win_count', 1)
            ->assertJsonPath('paired_scorecard.protocol_validity_rate', 100)
            ->assertJsonPath('paired_scorecard.autonomous_success_lift', 0);
    }

    public function test_fair_claude_benchmark_command_facade_reports_json(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-command-report',
            'name' => 'Fair command report',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'report',
            '--suite' => 'fair-command-report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('fair_claude_benchmark_report', data_get($payload, 'kind'));
        $this->assertSame('fair-command-report', data_get($payload, 'suite.slug'));
        $this->assertSame('not_ready', data_get($payload, 'readiness.status'));
        $this->assertSame(0, data_get($payload, 'readiness.release_corpus_case_count'));
        $this->assertContains('fair_release_corpus_below_minimum', data_get($payload, 'readiness.blocking_reasons', []));
    }

    public function test_rivals_report_json_delegates_to_fair_claude_and_returns_report(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'atlas-fair-claude-v1',
            'name' => 'Atlas Fair Claude v1',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'report',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('fair_claude_benchmark_report', data_get($payload, 'kind'));
        $this->assertSame('atlas-fair-claude-v1', data_get($payload, 'suite.slug'));
        $this->assertSame('not_ready', data_get($payload, 'readiness.status'));
    }

    public function test_rivals_report_markdown_exports_claim_report(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'atlas-fair-claude-v1',
            'name' => 'Atlas Fair Claude v1',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'report',
            '--markdown' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('# Atlas Rivals Fair Claude Report', $output);
        $this->assertStringContainsString('## Executive Summary', $output);
        $this->assertStringContainsString('## Next Actions', $output);
        $this->assertStringContainsString('## Case Outcomes', $output);
    }

    public function test_rivals_report_output_dir_writes_export_bundle(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'atlas-fair-claude-v1',
            'name' => 'Atlas Fair Claude v1',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);
        $directory = sys_get_temp_dir().'/atlas-rivals-export-'.bin2hex(random_bytes(4));

        try {
            $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'report',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame($directory, data_get($payload, 'written_export_bundle.directory'));
            foreach (['report.json', 'evidence.json', 'claim.md', 'case-comparisons.json', 'history-timeline.json', 'manifest.json'] as $filename) {
                $path = $directory.DIRECTORY_SEPARATOR.$filename;
                $this->assertFileExists($path);
                $this->assertSame(
                    hash_file('sha256', $path),
                    $payload['written_export_bundle']['files'][$filename]['sha256'] ?? null,
                );
            }
            $this->assertStringContainsString('# Atlas Rivals Fair Claude Report', File::get($directory.DIRECTORY_SEPARATOR.'claim.md'));
            $this->assertSame('atlas.fair_claude.history_timeline.v1', data_get(
                json_decode(File::get($directory.DIRECTORY_SEPARATOR.'history-timeline.json'), true),
                'schema_version',
            ));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_rivals_verify_output_dir_validates_export_bundle_integrity(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'atlas-fair-claude-v1',
            'name' => 'Atlas Fair Claude v1',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);
        $directory = sys_get_temp_dir().'/atlas-rivals-verify-'.bin2hex(random_bytes(4));

        try {
            $exportExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'report',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $this->assertSame(0, $exportExitCode);

            $verifyExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'verify',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $verification = json_decode(Artisan::output(), true);

            $this->assertSame(0, $verifyExitCode);
            $this->assertSame('passed', $verification['status'] ?? null);
            $this->assertTrue($verification['verified'] ?? false);
            $this->assertSame(6, $verification['file_count'] ?? null);
            $this->assertSame('passed', $verification['files']['claim.md']['status'] ?? null);
            $this->assertSame('passed', $verification['files']['history-timeline.json']['status'] ?? null);
            $this->assertTrue($verification['files']['claim.md']['hash_matches'] ?? false);
            $this->assertSame('passed', data_get($verification, 'semantic_checks.status'));
            $this->assertTrue(data_get($verification, 'semantic_checks.claim_markdown_contains_result_integrity'));
            $this->assertTrue(data_get($verification, 'semantic_checks.claim_markdown_contains_experiment_validity'));
            $this->assertContains(data_get($verification, 'semantic_checks.experiment_validity_status'), [
                'not_enough_comparable_data',
                'limited_valid_comparable_sample',
                'blocked_by_confounders',
                'valid_for_claim',
            ]);

            $evidencePath = $directory.DIRECTORY_SEPARATOR.'evidence.json';
            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            $evidence = json_decode(File::get($evidencePath), true);
            $manifest = json_decode(File::get($manifestPath), true);
            data_set($evidence, 'claim.winner', 'atlas');
            $evidence['evidence_hash'] = hash('sha256', $this->canonicalJsonForTest(Arr::except($evidence, ['generated_at', 'evidence_hash'])));
            $evidenceJson = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            File::put($evidencePath, $evidenceJson);
            $manifest['evidence_hash'] = $evidence['evidence_hash'];
            $manifest['files']['evidence.json']['bytes'] = strlen($evidenceJson);
            $manifest['files']['evidence.json']['sha256'] = hash('sha256', $evidenceJson);
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

            $semanticTamperedExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'verify',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $semanticTampered = json_decode(Artisan::output(), true);

            $this->assertSame(1, $semanticTamperedExitCode);
            $this->assertSame('failed', $semanticTampered['status'] ?? null);
            $this->assertContains('claim_winner_present_without_admission', $semanticTampered['blocking_reasons'] ?? []);
            $this->assertContains('ui_contract_winner_violation', $semanticTampered['blocking_reasons'] ?? []);
            $this->assertSame('failed', data_get($semanticTampered, 'semantic_checks.status'));
            $this->assertSame('failed', $semanticTampered['files']['evidence.json']['status'] ?? null);

            $exportExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'report',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $this->assertSame(0, $exportExitCode);
            File::put($directory.DIRECTORY_SEPARATOR.'claim.md', 'tampered benchmark claim');
            $tamperedExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'verify',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $tampered = json_decode(Artisan::output(), true);

            $this->assertSame(1, $tamperedExitCode);
            $this->assertSame('failed', $tampered['status'] ?? null);
            $this->assertFalse($tampered['verified'] ?? true);
            $this->assertContains('export_file_hash_mismatch', $tampered['blocking_reasons'] ?? []);
            $this->assertSame('failed', $tampered['files']['claim.md']['status'] ?? null);
            $this->assertFalse($tampered['files']['claim.md']['hash_matches'] ?? true);

            $exportExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'report',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $this->assertSame(0, $exportExitCode);
            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            $manifest = json_decode(File::get($manifestPath), true);
            unset($manifest['files']['history-timeline.json']);
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $missingManifestEntryExitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
                'action' => 'verify',
                '--output-dir' => $directory,
                '--json' => true,
            ]);
            $missingManifestEntry = json_decode(Artisan::output(), true);

            $this->assertSame(1, $missingManifestEntryExitCode);
            $this->assertSame('failed', $missingManifestEntry['status'] ?? null);
            $this->assertFalse($missingManifestEntry['verified'] ?? true);
            $this->assertContains('manifest_required_file_missing', $missingManifestEntry['blocking_reasons'] ?? []);
            $this->assertSame('failed', $missingManifestEntry['files']['history-timeline.json']['status'] ?? null);
            $this->assertSame('manifest_required_file_missing', $missingManifestEntry['files']['history-timeline.json']['blocking_reason'] ?? null);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_rivals_report_json_rejects_unknown_profile(): void
    {
        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'report',
            '--profile' => 'atlas-full-vs-claude',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('unsupported_profile', data_get($payload, 'error'));
        $this->assertContains('fair-claude', data_get($payload, 'supported_profiles', []));
    }

    public function test_rivals_rejects_unknown_preset(): void
    {
        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'runbook',
            '--preset' => 'giant',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('unsupported_preset', data_get($payload, 'error'));
        $this->assertSame(['quick', 'medium', 'full'], data_get($payload, 'supported_presets'));
    }

    public function test_fair_claude_command_returns_json_errors_for_invalid_report_and_replay_requests(): void
    {
        $missingSuiteExitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'report',
            '--suite' => 'missing-suite',
            '--json' => true,
        ]);
        $missingSuite = json_decode(Artisan::output(), true);

        $this->assertSame(1, $missingSuiteExitCode);
        $this->assertSame('benchmark_suite_not_found', data_get($missingSuite, 'error'));
        $this->assertSame('missing-suite', data_get($missingSuite, 'suite'));

        $missingRunExitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'replay',
            '--json' => true,
        ]);
        $missingRun = json_decode(Artisan::output(), true);

        $this->assertSame(1, $missingRunExitCode);
        $this->assertSame('run_id_required', data_get($missingRun, 'error'));

        $unknownActionExitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'not-a-real-action',
            '--json' => true,
        ]);
        $unknownAction = json_decode(Artisan::output(), true);

        $this->assertSame(1, $unknownActionExitCode);
        $this->assertSame('unknown_action', data_get($unknownAction, 'error'));
        $this->assertContains('run', data_get($unknownAction, 'supported_actions', []));
    }

    public function test_fair_claude_prepare_seeds_versioned_corpus_cases(): void
    {
        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'prepare',
            '--suite' => 'fair-corpus-seed',
            '--json' => true,
        ]);
        $suite = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', 'fair-corpus-seed')
            ->with('cases')
            ->firstOrFail();
        $payload = [
            'suite' => app(EngineeringBenchmarkService::class)->suitePayload($suite)['suite'] ?? null,
            'corpus_manifest' => data_get($suite->metadata, 'corpus_manifest'),
            'promoted_count' => $suite->cases->count(),
            'promoted_cases' => $suite->cases
                ->map(fn (AtlasEngineeringBenchmarkCase $case): array => app(EngineeringBenchmarkService::class)->casePayload($case))
                ->values()
                ->all(),
        ];

        $this->assertSame(0, $exitCode);
        $this->assertSame('fair-corpus-seed', data_get($payload, 'suite.slug'));
        $this->assertSame(8, data_get($payload, 'promoted_count'));
        $this->assertSame(8, data_get($payload, 'corpus_manifest.total_cases'));
        $this->assertSame(6, data_get($payload, 'corpus_manifest.official_subsets.release'));

        $cases = collect((array) data_get($payload, 'promoted_cases', []))->keyBy('case_code');
        $this->assertTrue($cases->has('fair_cli_provider_lock_drift'));
        $this->assertTrue($cases->has('fair_repair_capsule_gate_failure'));
        $this->assertTrue($cases->has('fair_benchmark_replay_packet_integrity'));
        $this->assertTrue($cases->has('fair_dirty_workspace_scope_block'));
        $this->assertTrue($cases->has('fair_context_pack_budget_pressure'));
        $this->assertTrue($cases->has('fair_prompt_contract_acceptance_matrix'));
        $this->assertTrue($cases->has('fair_final_packet_no_self_judge'));
        $this->assertTrue($cases->has('fair_paired_baseline_workspace_isolation'));
        $this->assertSame('hard', data_get($cases->get('fair_repair_capsule_gate_failure'), 'task_contract.difficulty'));
        $this->assertSame('context_pack', data_get($cases->get('fair_context_pack_budget_pressure'), 'domain_slug'));
        $this->assertSame('candidate', data_get($cases->get('fair_paired_baseline_workspace_isolation'), 'curation_status'));
        $this->assertSame('curated', data_get($cases->get('fair_benchmark_replay_packet_integrity'), 'curation_status'));
        $this->assertTrue((bool) data_get($cases->get('fair_benchmark_replay_packet_integrity'), 'task_contract.strict_file_scope'));
        $this->assertSame([
            'app/Services/Engineering/EngineeringBenchmarkService.php',
            'app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php',
            'app/Console/Commands/AtlasRivalsCommand.php',
            'tests/Feature/EngineeringHarnessRunnerTest.php',
        ], data_get($cases->get('fair_benchmark_replay_packet_integrity'), 'task_contract.allowed_files'));
        $this->assertTrue((bool) data_get($cases->get('fair_cli_provider_lock_drift'), 'metadata.fair_claude_official'));

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'readiness',
            '--suite' => 'fair-corpus-seed',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(6, data_get($report, 'readiness.release_corpus_case_count'));
        $this->assertSame(8, data_get($report, 'readiness.active_corpus_case_count'));
        $this->assertNotContains('fair_release_corpus_below_minimum', data_get($report, 'readiness.blocking_reasons', []));
    }

    public function test_fair_claude_prepare_api_seeds_corpus_for_mobile_rivals(): void
    {
        $this->postJson('/engineering/benchmarks/fair-claude/prepare', [
            'suite' => 'atlas-fair-claude-v1',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('suite.slug', 'atlas-fair-claude-v1')
            ->assertJsonPath('promoted_count', 8)
            ->assertJsonPath('corpus_manifest.official_subsets.release', 6);

        $this->getJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/fair-claude-report', $this->headers)
            ->assertOk()
            ->assertJsonPath('readiness.release_corpus_case_count', 6)
            ->assertJsonPath('readiness.active_corpus_case_count', 8)
            ->assertJsonPath('battery_execution_contract.status', 'operator_execution_required')
            ->assertJsonPath('battery_execution_contract.current_state.corpus_prepared', true)
            ->assertJsonPath('battery_execution_contract.current_state.comparable_case_count', 0)
            ->assertJsonPath('battery_execution_contract.external_cost_possible', true)
            ->assertJsonFragment([
                'id' => 'run_battery_runbook',
                'command' => 'atlas rivals runbook --json',
            ])
            ->assertJsonMissingPath('error');
    }

    public function test_fair_claude_report_exposes_result_integrity_for_invalid_battery(): void
    {
        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-report-invalid-integrity',
            'name' => 'Fair report invalid integrity',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [
                'corpus_manifest' => [
                    'active_cases' => 6,
                    'official_subsets' => ['release' => 6],
                ],
            ],
        ]);
        $case = app(EngineeringBenchmarkService::class)->registerCase($suite, [
            'case_code' => 'invalid_protocol_case',
            'title' => 'Invalid protocol case',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 90,
            'corpus_tier' => 'release',
        ]);
        $run = AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'fair:invalid-integrity',
            'provider' => 'claude_cli',
            'model' => 'opus',
            'mode' => 'single_shot',
            'status' => 'failed',
            'total_cases' => 1,
            'passed_cases' => 0,
            'failed_cases' => 1,
            'runner_options_json' => ['claude_only' => true],
            'summary_json' => [
                'paired_scorecard' => [
                    'enabled' => true,
                    'case_count' => 1,
                    'fair_mode_count' => 1,
                    'comparable_count' => 1,
                    'winners' => ['claude_code_baseline' => 1],
                    'claude_code_baseline_win_count' => 1,
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
            'metadata' => [],
        ]);
        AtlasEngineeringBenchmarkResult::query()->create([
            'benchmark_run_id' => $run->id,
            'suite_id' => $suite->id,
            'case_id' => $case->id,
            'status' => 'failed',
            'decision' => 'unsafe',
            'score' => 37,
            'passed' => false,
            'duration_ms' => 1000,
            'failure_summary' => 'fair protocol invalid',
            'expectation_json' => [],
            'observed_json' => [
                'paired_scorecard' => [
                    'schema_version' => 1,
                    'fair_mode' => true,
                    'comparison_status' => 'comparable',
                    'comparable' => true,
                    'winner' => 'claude_code_baseline',
                    'atlas' => [
                        'verified' => false,
                        'protocol_valid' => false,
                        'provider_violation_count' => 0,
                        'fallback_violation_count' => 0,
                    ],
                    'claude_code_baseline' => [
                        'verified' => true,
                        'passed' => true,
                        'pass_without_human' => true,
                        'score' => 100,
                    ],
                    'blocking_reasons' => ['atlas_not_verified_pass'],
                ],
                'claude_code_baseline' => [
                    'enabled' => true,
                    'status' => 'completed',
                    'executed' => true,
                    'provider' => 'claude_code_cli',
                    'model' => 'opus',
                ],
            ],
            'metadata' => [],
        ]);

        $this->getJson("/engineering/benchmarks/suites/{$suite->slug}/fair-claude-report?limit=1", $this->headers)
            ->assertOk()
            ->assertJsonPath('result_integrity.schema_version', 'atlas.fair_claude.result_integrity.v1')
            ->assertJsonPath('result_integrity.status', 'invalid_battery_no_comparable_score')
            ->assertJsonPath('result_integrity.score_admitted', false)
            ->assertJsonPath('result_integrity.claim_winner_admitted', false)
            ->assertJsonPath('result_integrity.winner_for_claim', null)
            ->assertJsonPath('result_integrity.provisional_leader', null)
            ->assertJsonPath('result_integrity.policy.invalid_cases_count_as_losses', false)
            ->assertJsonPath('result_integrity.policy.only_comparable_cases_enter_win_loss_math', true)
            ->assertJsonPath('result_integrity.experiment_validity.schema_version', 'atlas.fair_claude.experiment_validity.v1')
            ->assertJsonPath('result_integrity.experiment_validity.status', 'blocked_by_confounders')
            ->assertJsonPath('result_integrity.experiment_validity.ab_test_validity_model.same_case_snapshot_required', true)
            ->assertJsonPath('result_integrity.experiment_validity.ab_test_validity_model.equivalent_initial_state_required', true)
            ->assertJsonPath('result_integrity.experiment_validity.ab_test_validity_model.no_provider_specific_case_filtering', true)
            ->assertJsonPath('result_integrity.experiment_validity.external_variable_policy.non_evaluated_variables_cannot_decide_winner', true)
            ->assertJsonPath('result_integrity.experiment_validity.external_variable_policy.non_evaluated_variables_can_only_block_comparability', true)
            ->assertJsonPath('result_integrity.experiment_validity.score_policy.invalid_cases_excluded_from_win_loss_math', true)
            ->assertJsonPath('result_integrity.experiment_validity.observed.observed_confounders.0', 'fair_protocol_not_valid')
            ->assertJsonPath('result_integrity.ui_contract.must_not_render_winner', true)
            ->assertJsonPath('result_integrity.counts.comparable_count', 0)
            ->assertJsonPath('result_integrity.counts.invalid_case_count', 1)
            ->assertJsonPath('history_timeline.schema_version', 'atlas.fair_claude.history_timeline.v1')
            ->assertJsonPath('history_timeline.summary.run_count', 1)
            ->assertJsonPath('history_timeline.summary.comparable_case_count', 0)
            ->assertJsonPath('history_timeline.summary.invalid_case_count', 1)
            ->assertJsonPath('history_timeline.entries.0.result_integrity_status', 'invalid_battery_no_comparable_score')
            ->assertJsonPath('history_timeline.entries.0.score_admitted', false)
            ->assertJsonPath('history_timeline.entries.0.claim_winner_admitted', false)
            ->assertJsonPath('paired_scorecard.claude_code_baseline_win_count', 0)
            ->assertJsonPath('case_comparisons.0.comparison_status', 'atlas_protocol_invalid')
            ->assertJsonPath('case_comparisons.0.winner', null)
            ->assertJsonPath('executive_summary.winner', null)
            ->assertJsonPath('executive_summary.result_integrity.status', 'invalid_battery_no_comparable_score')
            ->assertJsonPath('evidence_packet.claim.winner', null)
            ->assertJsonPath('evidence_packet.claim.claim_winner_admitted', false)
            ->assertJsonPath('evidence_packet.result_integrity.ui_contract.must_not_render_winner', true)
            ->assertJsonPath('evidence_packet.result_integrity.experiment_validity.external_variable_policy.non_evaluated_variables_cannot_decide_winner', true)
            ->assertJsonFragment([
                'id' => 'triage_invalid_battery_before_provider_rerun',
                'severity' => 'critical',
                'command' => null,
            ]);

        $payload = $this->getJson("/engineering/benchmarks/suites/{$suite->slug}/fair-claude-report?limit=1", $this->headers)
            ->json();
        $this->assertNotContains('run_paired_battery', collect(data_get($payload, 'next_actions', []))->pluck('id')->all());
        $this->assertStringContainsString('## Result Integrity', (string) data_get($payload, 'claim_markdown'));
        $this->assertStringContainsString('## Experiment Validity', (string) data_get($payload, 'claim_markdown'));
        $this->assertStringContainsString('Score admitted: `false`', (string) data_get($payload, 'claim_markdown'));
        $this->assertStringContainsString('Winner for claim: `none`', (string) data_get($payload, 'claim_markdown'));
    }

    public function test_rivals_battery_plan_is_read_only_and_requires_operator_confirmation(): void
    {
        $this->postJson('/engineering/benchmarks/fair-claude/prepare', [
            'suite' => 'atlas-fair-claude-v1',
        ], $this->headers)->assertOk();

        $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/rivals/battery-plan', [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'baseline_workspace' => $this->createWorkspace(),
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('battery_plan.schema_version', 'atlas.rivals.battery_plan.v1')
            ->assertJsonPath('battery_plan.status', 'ready_for_operator_confirmation')
            ->assertJsonPath('battery_plan.ready', true)
            ->assertJsonPath('battery_plan.operator_report.schema_version', 'atlas.rivals.battery_plan_operator_report.v1')
            ->assertJsonPath('battery_plan.operator_report.status_label', 'Ready for review')
            ->assertJsonPath('battery_plan.operator_report.primary_blocker', null)
            ->assertJsonPath('battery_plan.operator_report.score_admitted', false)
            ->assertJsonPath('battery_plan.operator_report.claim_winner_admitted', false)
            ->assertJsonPath('battery_plan.operator_required', true)
            ->assertJsonPath('battery_plan.cost_acknowledgement_required', true)
            ->assertJsonPath('battery_plan.agent_auto_execution_allowed', false)
            ->assertJsonPath('battery_plan.safety.no_provider_call', true)
            ->assertJsonPath('battery_plan.safety.no_benchmark_run_created', true)
            ->assertJsonPath('battery_plan.execution_intent.baseline', 'paired')
            ->assertJsonPath('battery_plan.execution_intent.fair_claim_eligible', true)
            ->assertJsonPath('battery_plan.preflight.workspace_git.status', 'clean')
            ->assertJsonPath('battery_plan.preflight.workspace_status', 'clean')
            ->assertJsonPath('battery_plan.preflight.workspace_dirty_count', 0)
            ->assertJsonPath('battery_plan.preflight.baseline_workspace_git.status', 'clean')
            ->assertJsonPath('battery_plan.preflight.baseline_workspace_status', 'clean')
            ->assertJsonPath('battery_plan.preflight.baseline_workspace_dirty_count', 0)
            ->assertJsonPath('battery_plan.safety.clean_git_workspaces_required', true)
            ->assertJsonPath('battery_plan.selection_contract.schema_version', 'atlas.rivals.battery_selection_contract.v1')
            ->assertJsonPath('battery_plan.selection_contract.allowed_modes.0.id', 'official_fair')
            ->assertJsonPath('battery_plan.selection_contract.allowed_modes.1.id', 'same_model')
            ->assertJsonPath('battery_plan.selection_contract.allowed_modes.2.id', 'max')
            ->assertJsonPath('battery_plan.selection_contract.ui_must_send_provider_and_model_for_modes.0', 'official_fair')
            ->assertJsonPath('battery_plan.selection_contract.provider_model_options.0.provider', 'claude_cli')
            ->assertJsonPath('battery_plan.corpus.release_case_count', 6)
            ->assertJsonMissingPath('error');

        $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/rivals/battery-plan', [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('battery_plan.status', 'blocked')
            ->assertJsonPath('battery_plan.ready', false)
            ->assertJsonFragment(['separate_baseline_workspace_required']);
    }

    public function test_rivals_battery_api_plan_blocks_dirty_and_non_git_workspaces(): void
    {
        $this->postJson('/engineering/benchmarks/fair-claude/prepare', [
            'suite' => 'fair-api-workspace-preflight',
        ], $this->headers)->assertOk();
        $baselineWorkspace = $this->createWorkspace();
        File::put($this->workspace.'/src/api-dirty.txt', "dirty\n");

        $this->postJson('/engineering/benchmarks/suites/fair-api-workspace-preflight/rivals/battery-plan', [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'baseline_workspace' => $baselineWorkspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('battery_plan.status', 'blocked')
            ->assertJsonPath('battery_plan.ready', false)
            ->assertJsonPath('battery_plan.operator_report.status_label', 'Blocked')
            ->assertJsonPath('battery_plan.operator_report.primary_blocker', 'atlas_workspace_dirty')
            ->assertJsonPath('battery_plan.operator_report.workspace_status', 'dirty')
            ->assertJsonPath('battery_plan.operator_report.workspace_dirty_count', 1)
            ->assertJsonPath('battery_plan.preflight.workspace_git.status', 'dirty')
            ->assertJsonPath('battery_plan.preflight.workspace_dirty_count', 1)
            ->assertJsonPath('battery_plan.safety.no_provider_call', true)
            ->assertJsonFragment(['atlas_workspace_dirty']);

        $nonGitBaseline = sys_get_temp_dir().'/atlas-fair-api-non-git-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($nonGitBaseline);
        $this->postJson('/engineering/benchmarks/suites/fair-api-workspace-preflight/rivals/battery-plan', [
            'mode' => 'official_fair',
            'workspace' => $baselineWorkspace,
            'baseline_workspace' => $nonGitBaseline,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('battery_plan.status', 'blocked')
            ->assertJsonPath('battery_plan.ready', false)
            ->assertJsonPath('battery_plan.preflight.baseline_workspace_git.status', 'not_git_workspace')
            ->assertJsonFragment(['claude_code_baseline_workspace_not_git_worktree']);

        $this->deleteDirectoryQuietly($baselineWorkspace);
        $this->deleteDirectoryQuietly($nonGitBaseline);
    }

    public function test_rivals_battery_plan_requires_atlas_token(): void
    {
        $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/rivals/battery-plan', [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'baseline_workspace' => $this->createWorkspace(),
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], ['X-Atlas-Token' => 'wrong-token-with-enough-length'])
            ->assertUnauthorized()
            ->assertJsonPath('error.message', 'Invalid or missing X-Atlas-Token.');
    }

    public function test_rivals_battery_run_requires_review_cost_acknowledgement_and_current_plan_hash(): void
    {
        $this->postJson('/engineering/benchmarks/fair-claude/prepare', [
            'suite' => 'atlas-fair-claude-v1',
        ], $this->headers)->assertOk();

        $baselineWorkspace = $this->createWorkspace();
        $plan = $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/rivals/battery-plan', [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'baseline_workspace' => $baselineWorkspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], $this->headers)
            ->assertOk()
            ->json('battery_plan');

        $runPayload = [
            'workspace' => $this->workspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'model_policy' => 'fixed',
            'fair_mode' => true,
            'claude_only' => true,
            'single_provider' => true,
            'no_decide' => true,
            'fallback_disabled' => true,
            'require_pass_without_human' => true,
            'claude_code_baseline' => 'run',
            'claude_code_baseline_mode' => 'run',
            'claude_code_baseline_model' => 'opus',
            'claude_code_baseline_workspace' => $baselineWorkspace,
            'baseline_runner' => 'run',
            'baseline_model' => 'opus',
            'limit' => 6,
            'corpus_tier' => 'release',
            'rivals_battery_mode' => 'official_fair',
            'rivals_battery_plan_hash' => data_get($plan, 'plan_hash'),
        ];
        $before = AtlasEngineeringBenchmarkRun::query()->count();

        $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/run', $runPayload, $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('error', 'rivals_battery_plan_review_required');

        $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/run', [
            ...$runPayload,
            'operator_plan_reviewed' => true,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('error', 'rivals_battery_cost_acknowledgement_required');

        $this->postJson('/engineering/benchmarks/suites/atlas-fair-claude-v1/run', [
            ...$runPayload,
            'operator_plan_reviewed' => true,
            'operator_cost_acknowledged' => true,
            'rivals_battery_plan_hash' => str_repeat('0', 64),
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('error', 'rivals_battery_plan_hash_mismatch')
            ->assertJsonPath('battery_plan.safety.no_provider_call', true);

        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());
    }

    public function test_rivals_battery_api_blocks_historical_invalid_battery_before_provider_run(): void
    {
        $suite = $this->seedInvalidFairClaudeBattery('fair-api-invalid-battery-rerun');
        $baselineWorkspace = $this->createWorkspace();

        $plan = $this->postJson("/engineering/benchmarks/suites/{$suite->slug}/rivals/battery-plan", [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'baseline_workspace' => $baselineWorkspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('battery_plan.status', 'blocked')
            ->assertJsonPath('battery_plan.ready', false)
            ->assertJsonPath('battery_plan.result_integrity.triage_required_before_rerun', true)
            ->assertJsonPath('battery_plan.safety.no_provider_call', true)
            ->assertJsonFragment(['historical_invalid_battery_requires_triage'])
            ->json('battery_plan');

        $before = AtlasEngineeringBenchmarkRun::query()->count();
        $this->postJson("/engineering/benchmarks/suites/{$suite->slug}/run", [
            'workspace' => $this->workspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'model_policy' => 'fixed',
            'fair_mode' => true,
            'claude_only' => true,
            'single_provider' => true,
            'no_decide' => true,
            'fallback_disabled' => true,
            'require_pass_without_human' => true,
            'claude_code_baseline' => 'run',
            'claude_code_baseline_mode' => 'run',
            'claude_code_baseline_model' => 'opus',
            'claude_code_baseline_workspace' => $baselineWorkspace,
            'baseline_runner' => 'run',
            'baseline_model' => 'opus',
            'limit' => 6,
            'corpus_tier' => 'release',
            'rivals_battery_mode' => 'official_fair',
            'rivals_battery_plan_hash' => data_get($plan, 'plan_hash'),
            'operator_plan_reviewed' => true,
            'operator_cost_acknowledged' => true,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('error', 'rivals_battery_plan_blocked')
            ->assertJsonPath('battery_plan.result_integrity.triage_required_before_rerun', true)
            ->assertJsonFragment(['historical_invalid_battery_requires_triage']);

        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());

        $this->deleteDirectoryQuietly($baselineWorkspace);
    }

    public function test_invalid_fair_battery_can_be_quarantined_without_admitting_score_or_deleting_history(): void
    {
        $suite = $this->seedInvalidFairClaudeBattery('fair-invalid-battery-quarantine');
        $baselineWorkspace = $this->createWorkspace();

        $beforeRuns = AtlasEngineeringBenchmarkRun::query()->count();
        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'triage-invalid-battery',
            '--suite' => $suite->slug,
            '--reason' => 'Historical Atlas protocol failure was reviewed; exclude it from rerun blocking, not from audit history.',
            '--confirm-invalid-battery-quarantine' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('triaged_quarantined', data_get($payload, 'status'));
        $this->assertContains(data_get($payload, 'invalid_battery_fingerprint'), data_get($payload, 'accepted_fingerprints', []));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'record.record_count'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_score_admitted'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_history_deleted'));
        $this->assertSame($beforeRuns, AtlasEngineeringBenchmarkRun::query()->count());

        $report = app(EngineeringBenchmarkService::class)->fairClaudeReportPayload($suite->refresh(), ['limit' => 20]);
        $this->assertSame('invalid_battery_no_comparable_score', data_get($report, 'result_integrity.status'));
        $this->assertSame('triaged_quarantined', data_get($report, 'result_integrity.invalid_battery_triage.status'));
        $this->assertContains(data_get($report, 'result_integrity.invalid_battery_triage.invalid_battery_fingerprint'), data_get($report, 'result_integrity.invalid_battery_triage.accepted_fingerprints', []));
        $this->assertGreaterThanOrEqual(1, data_get($report, 'result_integrity.invalid_battery_triage.accepted_record_count'));
        $this->assertFalse((bool) data_get($report, 'result_integrity.triage_required_before_rerun'));
        $this->assertFalse((bool) data_get($report, 'result_integrity.score_admitted'));
        $this->assertFalse((bool) data_get($report, 'result_integrity.claim_winner_admitted'));
        $this->assertSame(1, AtlasEngineeringBenchmarkRun::query()->where('suite_id', $suite->id)->count());

        $plan = $this->postJson("/engineering/benchmarks/suites/{$suite->slug}/rivals/battery-plan", [
            'mode' => 'official_fair',
            'workspace' => $this->workspace,
            'baseline_workspace' => $baselineWorkspace,
            'provider' => 'claude_cli',
            'model' => 'opus',
            'limit' => 6,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('battery_plan.safety.no_provider_call', true)
            ->json('battery_plan');

        $this->assertNotContains('historical_invalid_battery_requires_triage', data_get($plan, 'blocking_reasons', []));
        $this->assertFalse((bool) data_get($plan, 'result_integrity.triage_required_before_rerun'));
        $this->assertSame('triaged_quarantined', data_get($plan, 'operator_report.invalid_battery_triage_status'));
        $this->assertFalse((bool) data_get($plan, 'operator_report.score_admitted'));
        $this->assertFalse((bool) data_get($plan, 'operator_report.claim_winner_admitted'));

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--suite' => $suite->slug,
            '--workspace' => $this->workspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--json' => true,
        ]);
        $runbook = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse((bool) data_get($runbook, 'preflight.historical_invalid_battery_requires_triage'));
        $this->assertNotContains('historical_invalid_battery_requires_triage', data_get($runbook, 'start_blocking_reasons', []));

        $this->deleteDirectoryQuietly($baselineWorkspace);
    }

    public function test_atlas_rivals_wrapper_can_quarantine_invalid_battery_without_provider_call(): void
    {
        $suite = $this->seedInvalidFairClaudeBattery('fair-rivals-wrapper-invalid-battery-quarantine');
        $beforeRuns = AtlasEngineeringBenchmarkRun::query()->count();

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'triage-invalid-battery',
            '--suite' => $suite->slug,
            '--reason' => 'Reviewed through Atlas Rivals wrapper; quarantine invalid battery without admitting score.',
            '--confirm-invalid-battery-quarantine' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('triaged_quarantined', data_get($payload, 'status'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_score_admitted'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_history_deleted'));
        $this->assertSame($beforeRuns, AtlasEngineeringBenchmarkRun::query()->count());

        $report = app(EngineeringBenchmarkService::class)->fairClaudeReportPayload($suite->refresh(), ['limit' => 20]);

        $this->assertSame('triaged_quarantined', data_get($report, 'result_integrity.invalid_battery_triage.status'));
        $this->assertFalse((bool) data_get($report, 'result_integrity.triage_required_before_rerun'));
        $this->assertFalse((bool) data_get($report, 'result_integrity.score_admitted'));
        $this->assertFalse((bool) data_get($report, 'result_integrity.claim_winner_admitted'));
    }

    public function test_fair_claude_runbook_reports_real_battery_commands_and_start_blockers(): void
    {
        Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'prepare',
            '--suite' => 'fair-runbook-ready',
            '--json' => true,
        ]);
        $baselineWorkspace = $this->createWorkspace();

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--suite' => 'fair-runbook-ready',
            '--workspace' => $this->workspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('fair_claude_battery_runbook', data_get($payload, 'kind'));
        $this->assertSame('ready_to_start', data_get($payload, 'start_status'));
        $this->assertTrue((bool) data_get($payload, 'ready_to_start_battery'));
        $this->assertSame([], data_get($payload, 'start_blocking_reasons'));
        $this->assertSame(6, data_get($payload, 'preflight.release_corpus_case_count'));
        $this->assertTrue((bool) data_get($payload, 'preflight.baseline_workspace_separate'));
        $this->assertTrue((bool) data_get($payload, 'preflight.claude_code_binary_found'));
        $this->assertSame('clean', data_get($payload, 'preflight.workspace_git.status'));
        $this->assertSame('clean', data_get($payload, 'preflight.baseline_workspace_git.status'));
        $this->assertStringContainsString('run ', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('git worktree add', (string) data_get($payload, 'commands.prepare_clean_atlas_worktree'));
        $this->assertStringContainsString('git -C <clean-atlas-workspace> status --short', (string) data_get($payload, 'commands.verify_atlas_worktree_clean'));
        $this->assertStringContainsString('--model=opus', (string) data_get($payload, 'commands.doctor'));
        $this->assertStringContainsString('--model-policy=fixed', (string) data_get($payload, 'commands.doctor'));
        $this->assertStringContainsString('--model=opus', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--quality-changed-only', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--provider-timeout=600', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--case-timeout=1800', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--test-timeout=300', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--quality-changed-only', (string) data_get($payload, 'commands.run_atlas_arm_only'));
        $this->assertStringContainsString('--confirm-runbook-reviewed', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--confirm-provider-cost', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertSame('claude_cli', data_get($payload, 'protocol.atlas_provider_lock'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.confirm_runbook_reviewed_required'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.confirm_provider_cost_required'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.ready_runbook_required'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.git_worktree_required'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.invalid_battery_triage_required_before_rerun'));
        $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.clean_atlas_workspace_required'));

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--suite' => 'fair-runbook-ready',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $blockedPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', data_get($blockedPayload, 'start_status'));
        $this->assertContains('claude_code_baseline_workspace_missing_or_unreadable', data_get($blockedPayload, 'start_blocking_reasons', []));
    }

    public function test_fair_claude_runbook_blocks_laravel_workspaces_without_runtime_artifacts_before_provider_spend(): void
    {
        Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'prepare',
            '--suite' => 'fair-runbook-runtime-preflight',
            '--json' => true,
        ]);

        $atlasWorkspace = $this->createWorkspace();
        $baselineWorkspace = $this->createWorkspace();

        try {
            foreach ([$atlasWorkspace, $baselineWorkspace] as $workspace) {
                File::put($workspace.'/artisan', "<?php // laravel marker\n");
                File::put($workspace.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
                (new Process(['git', 'add', 'artisan', 'composer.json'], $workspace))->run();
                (new Process(['git', 'commit', '-m', 'Add laravel markers'], $workspace))->run();
            }

            $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
                'action' => 'runbook',
                '--suite' => 'fair-runbook-runtime-preflight',
                '--workspace' => $atlasWorkspace,
                '--claude-code-baseline-workspace' => $baselineWorkspace,
                '--claude-code-baseline-binary' => '/bin/echo',
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(1, $exitCode, Artisan::output());
            $this->assertSame('blocked', data_get($payload, 'start_status'));
            $this->assertContains('atlas_workspace_vendor_autoload_missing', data_get($payload, 'start_blocking_reasons', []));
            $this->assertContains('claude_code_baseline_vendor_autoload_missing', data_get($payload, 'start_blocking_reasons', []));
            $this->assertContains('atlas_workspace_env_missing', data_get($payload, 'start_blocking_reasons', []));
            $this->assertContains('claude_code_baseline_env_missing', data_get($payload, 'start_blocking_reasons', []));
            $this->assertTrue((bool) data_get($payload, 'preflight.workspace_runtime.laravel_runtime_detected'));
            $this->assertFalse((bool) data_get($payload, 'preflight.workspace_runtime.vendor_autoload_exists'));
            $this->assertTrue((bool) data_get($payload, 'provider_execution_guard.laravel_runtime_preflight_required'));
        } finally {
            $this->deleteDirectoryQuietly($atlasWorkspace);
            $this->deleteDirectoryQuietly($baselineWorkspace);
        }
    }

    public function test_laravel_pint_control_uses_high_memory_php_invocation(): void
    {
        File::put($this->workspace.'/artisan', "<?php // laravel marker\n");
        File::put($this->workspace.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
        File::ensureDirectoryExists($this->workspace.'/vendor/bin');
        File::put($this->workspace.'/vendor/bin/pint', "#!/usr/bin/env php\n<?php\n");

        $controls = app(EngineeringControlRegistryService::class)->applicableControls(
            $this->task(),
            $this->workspace,
            [],
            [],
        );
        $pint = collect($controls)->firstWhere('slug', 'laravel_pint_check');

        $this->assertIsArray($pint);
        $this->assertStringContainsString('-d memory_limit=1024M', (string) ($pint['command'] ?? ''));
        $this->assertStringContainsString('vendor/bin/pint --test', (string) ($pint['command'] ?? ''));
        $this->assertSame('1024M', data_get($pint, 'metadata.memory_limit'));
    }

    public function test_fair_claude_provider_run_requires_explicit_runbook_and_cost_confirmation(): void
    {
        $before = AtlasEngineeringBenchmarkRun::query()->count();

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'run',
            '--suite' => 'fair-provider-confirmation',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_claude_provider_execution_confirmation_required', data_get($payload, 'error'));
        $this->assertContains('confirm_runbook_reviewed', data_get($payload, 'missing_confirmations', []));
        $this->assertContains('confirm_provider_cost', data_get($payload, 'missing_confirmations', []));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_benchmark_run_created'));
        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());
    }

    public function test_fair_claude_provider_run_blocks_dirty_workspace_even_with_cost_confirmed(): void
    {
        Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'prepare',
            '--suite' => 'fair-provider-dirty-workspace',
            '--json' => true,
        ]);
        $baselineWorkspace = $this->createWorkspace();
        File::put($this->workspace.'/src/uncommitted.txt', "dirty\n");
        $before = AtlasEngineeringBenchmarkRun::query()->count();

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'run',
            '--suite' => 'fair-provider-dirty-workspace',
            '--workspace' => $this->workspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--confirm-runbook-reviewed' => true,
            '--confirm-provider-cost' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload, Artisan::output());
        $this->assertSame('fair_claude_provider_execution_preflight_blocked', data_get($payload, 'error'), json_encode($payload));
        $this->assertContains('atlas_workspace_dirty', data_get($payload, 'blocking_reasons', []));
        $this->assertSame('dirty', data_get($payload, 'runbook.preflight.workspace_git.status'));
        $this->assertContains('src/uncommitted.txt', data_get($payload, 'runbook.preflight.workspace_git.dirty_files', []));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertTrue((bool) data_get($payload, 'safety.operator_confirmations_present_but_insufficient'));
        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());

        $this->deleteDirectoryQuietly($baselineWorkspace);
    }

    public function test_fair_claude_provider_run_blocks_non_git_workspace_even_with_cost_confirmed(): void
    {
        Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'prepare',
            '--suite' => 'fair-provider-non-git-workspace',
            '--json' => true,
        ]);
        $nonGitWorkspace = sys_get_temp_dir().'/atlas-fair-non-git-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($nonGitWorkspace.'/src');
        File::put($nonGitWorkspace.'/src/example.txt', "not versioned\n");
        $baselineWorkspace = $this->createWorkspace();
        $before = AtlasEngineeringBenchmarkRun::query()->count();

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'run',
            '--suite' => 'fair-provider-non-git-workspace',
            '--workspace' => $nonGitWorkspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--confirm-runbook-reviewed' => true,
            '--confirm-provider-cost' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload, Artisan::output());
        $this->assertSame('fair_claude_provider_execution_preflight_blocked', data_get($payload, 'error'));
        $this->assertContains('atlas_workspace_not_git_worktree', data_get($payload, 'blocking_reasons', []));
        $this->assertSame('not_git_workspace', data_get($payload, 'runbook.preflight.workspace_git.status'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());

        $this->deleteDirectoryQuietly($nonGitWorkspace);
        $this->deleteDirectoryQuietly($baselineWorkspace);
    }

    public function test_fair_claude_provider_run_blocks_historical_invalid_battery_even_with_clean_workspaces_and_cost_confirmed(): void
    {
        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-provider-invalid-battery-rerun',
            'name' => 'Fair provider invalid battery rerun',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [
                'corpus_manifest' => [
                    'active_cases' => 6,
                    'official_subsets' => ['release' => 6],
                ],
            ],
        ]);
        $case = app(EngineeringBenchmarkService::class)->registerCase($suite, [
            'case_code' => 'invalid_battery_rerun_guard',
            'title' => 'Invalid battery rerun guard',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 90,
            'corpus_tier' => 'release',
        ]);
        $run = AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'fair:invalid-battery-rerun',
            'provider' => 'claude_cli',
            'model' => 'opus',
            'mode' => 'single_shot',
            'status' => 'failed',
            'total_cases' => 1,
            'passed_cases' => 0,
            'failed_cases' => 1,
            'runner_options_json' => ['claude_only' => true],
            'summary_json' => [
                'paired_scorecard' => [
                    'enabled' => true,
                    'case_count' => 1,
                    'fair_mode_count' => 1,
                    'comparable_count' => 1,
                    'winners' => ['claude_code_baseline' => 1],
                    'claude_code_baseline_win_count' => 1,
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
            'metadata' => [],
        ]);
        AtlasEngineeringBenchmarkResult::query()->create([
            'benchmark_run_id' => $run->id,
            'suite_id' => $suite->id,
            'case_id' => $case->id,
            'status' => 'failed',
            'decision' => 'unsafe',
            'score' => 37,
            'passed' => false,
            'duration_ms' => 1000,
            'failure_summary' => 'fair protocol invalid',
            'expectation_json' => [],
            'observed_json' => [
                'paired_scorecard' => [
                    'schema_version' => 1,
                    'fair_mode' => true,
                    'comparison_status' => 'comparable',
                    'comparable' => true,
                    'winner' => 'claude_code_baseline',
                    'atlas' => [
                        'verified' => false,
                        'protocol_valid' => false,
                        'provider_violation_count' => 0,
                        'fallback_violation_count' => 0,
                    ],
                    'claude_code_baseline' => [
                        'verified' => true,
                        'passed' => true,
                        'pass_without_human' => true,
                        'score' => 100,
                    ],
                    'blocking_reasons' => ['atlas_not_verified_pass'],
                ],
                'claude_code_baseline' => [
                    'enabled' => true,
                    'status' => 'completed',
                    'executed' => true,
                    'provider' => 'claude_code_cli',
                    'model' => 'opus',
                ],
            ],
            'metadata' => [],
        ]);
        $baselineWorkspace = $this->createWorkspace();
        $before = AtlasEngineeringBenchmarkRun::query()->count();

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'run',
            '--suite' => $suite->slug,
            '--workspace' => $this->workspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--confirm-runbook-reviewed' => true,
            '--confirm-provider-cost' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_claude_provider_execution_preflight_blocked', data_get($payload, 'error'));
        $this->assertContains('historical_invalid_battery_requires_triage', data_get($payload, 'blocking_reasons', []));
        $this->assertTrue((bool) data_get($payload, 'runbook.preflight.historical_invalid_battery_requires_triage'));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());

        $this->deleteDirectoryQuietly($baselineWorkspace);
    }

    public function test_atlas_rivals_wrapper_requires_explicit_runbook_and_cost_confirmation(): void
    {
        $before = AtlasEngineeringBenchmarkRun::query()->count();

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'run',
            '--suite' => 'fair-provider-confirmation',
            '--workspace' => $this->workspace,
            '--json' => true,
            '--no-dashboard' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_claude_provider_execution_confirmation_required', data_get($payload, 'error'));
        $this->assertContains('confirm_runbook_reviewed', data_get($payload, 'missing_confirmations', []));
        $this->assertContains('confirm_provider_cost', data_get($payload, 'missing_confirmations', []));
        $this->assertTrue((bool) data_get($payload, 'safety.no_provider_call'));
        $this->assertSame($before, AtlasEngineeringBenchmarkRun::query()->count());
    }

    public function test_fair_claude_command_accepts_opus_model_lock_and_rejects_model_drift(): void
    {
        Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'prepare',
            '--suite' => 'fair-runbook-model-lock',
            '--json' => true,
        ]);
        $baselineWorkspace = $this->createWorkspace();

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--suite' => 'fair-runbook-model-lock',
            '--workspace' => $this->workspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--model' => 'opus',
            '--model-policy' => 'fixed',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--model=opus', (string) data_get($payload, 'commands.run_full_paired_battery'));
        $this->assertStringContainsString('--model-policy=fixed', (string) data_get($payload, 'commands.run_full_paired_battery'));

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--suite' => 'fair-runbook-model-lock',
            '--workspace' => $this->workspace,
            '--claude-code-baseline-workspace' => $baselineWorkspace,
            '--claude-code-baseline-binary' => '/bin/echo',
            '--model' => '5.5',
            '--json' => true,
        ]);
        $violation = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_mode_violation', data_get($violation, 'error'));
        $this->assertSame('5.5', data_get($violation, 'details.model'));
    }

    public function test_engineering_benchmark_rejects_unverified_pass_escape_hatch_in_fair_mode(): void
    {
        $exitCode = Artisan::call('atlas:engineering:benchmark', [
            '--suite' => 'missing-suite-is-not-reached',
            '--claude-only' => true,
            '--allow-unverified-fair-pass' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertTrue((bool) data_get($payload, 'details.allow_unverified_fair_pass'));
        $this->assertTrue((bool) data_get($payload, 'details.claude_only'));
    }

    public function test_engineering_benchmark_rejects_explicit_provider_drift_in_fair_mode(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-provider-lock-drift',
            'name' => 'Fair provider lock drift',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:engineering:benchmark', [
            '--suite' => 'fair-provider-lock-drift',
            '--claude-only' => true,
            '--provider' => 'codex_cli',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertStringContainsString('codex_cli', (string) data_get($payload, 'message'));
        $this->assertTrue((bool) data_get($payload, 'details.claude_only'));
    }

    public function test_engineering_benchmark_rejects_explicit_model_drift_in_fair_mode(): void
    {
        AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'fair-model-lock-drift',
            'name' => 'Fair model lock drift',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);

        $exitCode = Artisan::call('atlas:engineering:benchmark', [
            '--suite' => 'fair-model-lock-drift',
            '--claude-only' => true,
            '--model' => 'sonnet',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertStringContainsString('Claude Opus', (string) data_get($payload, 'message'));
    }

    public function test_engineering_benchmark_preserves_explicit_provider_outside_fair_mode(): void
    {
        $service = app(EngineeringBenchmarkService::class);
        $reflection = new \ReflectionMethod($service, 'withReleaseQualityScanDefaults');
        $reflection->setAccessible(true);

        $options = $reflection->invoke($service, [
            'provider' => 'codex_cli',
            'model' => 'codex-spark',
            'model_policy' => 'auto',
        ]);

        $this->assertSame('codex_cli', $options['provider']);
        $this->assertSame('codex-spark', $options['model']);
        $this->assertSame('auto', $options['model_policy']);
        $this->assertArrayNotHasKey('fair_mode', $options);
    }

    public function test_engineering_benchmark_seed_promotes_real_runs_to_default_suite(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'max_attempts' => 1,
        ]);
        $runId = (string) data_get($payload, 'run.id');
        $caseCode = 'run_'.substr(str_replace('-', '', $runId), 0, 12);

        $defaultSuite = $this->postJson('/engineering/benchmarks/suites/default', [], $this->headers)
            ->assertSuccessful()
            ->assertJsonPath('suite.slug', 'atlas-core-smoke');
        $suiteSlug = (string) data_get($defaultSuite->json(), 'suite.slug');

        $this->postJson("/engineering/benchmarks/suites/{$suiteSlug}/cases/from-run", [
            'run_id' => $runId,
            'workspace' => $this->workspace,
            'tags' => ['regression'],
        ], $this->headers)
            ->assertSuccessful()
            ->assertJsonPath('case.case_code', $caseCode)
            ->assertJsonPath('case.expected_decision', 'resolved')
            ->assertJsonPath('case.tags.0', 'promoted')
            ->assertJsonPath('case.metadata.source_engineering_run_id', $runId);

        $exitCode = Artisan::call('atlas:engineering:benchmark:seed', [
            '--suite' => 'atlas-core-smoke',
            '--workspace' => $this->workspace,
            '--from-recent-runs' => 5,
            '--tag' => ['cli'],
            '--json' => true,
        ]);
        $seedPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas-core-smoke', data_get($seedPayload, 'suite.slug'));
        $this->assertSame(1, data_get($seedPayload, 'promoted_count'));
        $this->assertDatabaseHas('atlas_engineering_benchmark_cases', [
            'case_code' => $caseCode,
            'task_id' => $task->id,
            'expected_decision' => 'resolved',
        ]);
    }

    public function test_provider_iterations_are_persisted_as_runner_attempts(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['mode' => 'complete'],
            'status' => 'running',
            'max_attempts' => 3,
            'started_at' => now(),
            'metadata' => [],
        ]);
        $seed = AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'claude_cli',
            'phase' => 'edit',
            'prompt_hash' => hash('sha256', 'prompt'),
            'input_summary_json' => ['task_id' => $task->id],
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [],
        ]);
        $firstTrace = (string) Str::uuid();
        $secondTrace = (string) Str::uuid();

        $latest = app(EngineeringHarnessRunnerService::class)->syncProviderAttempts($run, $seed, [
            'exit_code' => 1,
            'trace_id' => $secondTrace,
            'stdout' => '{}',
            'stderr' => '',
            'decoded' => [
                'workflow' => ['selected_provider' => 'codex_cli'],
                'dev_execution_plan' => ['plan_id' => 'plan-1'],
                'completion' => ['status' => 'failed'],
                'provider_runs' => [
                    ['iteration' => 1, 'trace_id' => $firstTrace, 'exit_code' => 0, 'stdout' => 'edit ok', 'stderr' => ''],
                    ['iteration' => 2, 'trace_id' => $secondTrace, 'exit_code' => 1, 'stdout' => '', 'stderr' => 'type error'],
                ],
            ],
        ], null);

        $this->assertSame(2, $latest->attempt_number);
        $this->assertSame('repair', $latest->phase);
        $this->assertSame('failed', $latest->status);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'trace_id' => $firstTrace,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => $run->id,
            'attempt_number' => 2,
            'trace_id' => $secondTrace,
            'phase' => 'repair',
            'status' => 'failed',
        ]);
    }

    public function test_ai_chat_dev_trace_jobs_are_persisted_as_runner_attempts(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['mode' => 'complete'],
            'status' => 'running',
            'max_attempts' => 3,
            'started_at' => now(),
            'metadata' => [],
        ]);
        $seed = AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'claude_cli',
            'phase' => 'edit',
            'prompt_hash' => hash('sha256', 'prompt'),
            'input_summary_json' => ['task_id' => $task->id],
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [],
        ]);
        $trace = AiTrace::query()->create([
            'id' => (string) Str::uuid(),
            'status' => 'failed',
            'operator_input' => 'corrigir fluxo',
            'agent_slug' => 'dev',
            'provider' => 'codex_cli',
            'model' => 'gpt-test',
            'response_text' => 'repair still failed',
            'metadata' => [
                'programming_repair' => [
                    'status' => 'exhausted',
                    'last_quality_status' => 'failed',
                ],
            ],
        ]);
        AiJob::query()->create([
            'id' => (string) Str::uuid(),
            'trace_id' => $trace->id,
            'status' => 'succeeded',
            'provider' => 'codex_cli',
            'model' => 'gpt-test',
            'input_text' => 'corrigir fluxo',
            'prompt' => 'corrigir fluxo',
            'result_text' => 'edit ok',
            'metadata' => [],
        ]);
        AiJob::query()->create([
            'id' => (string) Str::uuid(),
            'trace_id' => $trace->id,
            'status' => 'failed',
            'provider' => 'codex_cli',
            'model' => 'gpt-test',
            'input_text' => 'repair',
            'prompt' => 'repair',
            'error_message' => 'quality gate failed',
            'metadata' => [
                'programming_repair_job' => true,
                'programming_repair_iteration' => 2,
            ],
        ]);

        $latest = app(EngineeringHarnessRunnerService::class)->syncProviderAttempts($run, $seed, [
            'exit_code' => 1,
            'stdout' => json_encode(['trace_id' => $trace->id]),
            'stderr' => '',
            'decoded' => [
                'trace_id' => $trace->id,
                'status' => 'failed',
                'provider' => 'codex_cli',
                'model' => 'gpt-test',
                'programming_repair' => [
                    'status' => 'exhausted',
                ],
            ],
        ], null);

        $this->assertSame(2, $latest->attempt_number);
        $this->assertSame('repair', $latest->phase);
        $this->assertSame('failed', $latest->status);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'trace_id' => $trace->id,
            'provider' => 'codex_cli',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => $run->id,
            'attempt_number' => 2,
            'trace_id' => $trace->id,
            'phase' => 'repair',
            'status' => 'failed',
        ]);
    }

    public function test_model_policy_selects_best_historical_model_and_records_control(): void
    {
        config()->set('atlas.ai.providers.claude_cli.model', 'claude-sonnet-test');
        config()->set('atlas.ai.providers.claude_cli.model_label', 'Claude Sonnet Test');
        config()->set('atlas.ai.providers.claude_cli.model_identity', 'claude-sonnet-test');
        config()->set('atlas.ai.providers.claude_cli.premium_model', 'claude-opus-test');
        config()->set('atlas.ai.providers.claude_cli.premium_model_label', 'Claude Opus Test');
        config()->set('atlas.ai.providers.claude_cli.allow_auto', true);
        config()->set('atlas.ai.providers.codex_cli.model', 'codex-spark-test');
        config()->set('atlas.ai.providers.codex_cli.model_label', 'Codex Spark Test');
        config()->set('atlas.ai.providers.codex_cli.model_identity', 'codex-spark-test');
        config()->set('atlas.ai.providers.codex_cli.allow_auto', true);

        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => 'model-policy-history',
            'name' => 'Model policy history',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [],
        ]);
        AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'history:opus',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-test',
            'mode' => 'single_shot',
            'case_set_hash' => hash('sha256', 'opus'),
            'status' => 'passed',
            'total_cases' => 3,
            'passed_cases' => 3,
            'failed_cases' => 0,
            'pass_rate' => 100,
            'average_score' => 94,
            'duration_ms' => 90_000,
            'failed_control_count' => 0,
            'blocked_control_count' => 0,
            'skipped_required_control_count' => 0,
            'failed_test_count' => 0,
            'blocking_review_finding_count' => 0,
            'cost_microusd' => 600_000,
            'quality_metrics_json' => [],
            'runner_options_json' => [],
            'summary_json' => [
                'paired_scorecard' => [
                    'enabled' => true,
                    'case_count' => 1,
                    'fair_mode_count' => 1,
                    'comparable_count' => 1,
                    'winners' => ['claude_code_baseline' => 1],
                    'claude_code_baseline_win_count' => 1,
                ],
            ],
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'metadata' => [],
        ]);
        AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'history:spark',
            'provider' => 'codex_cli',
            'model' => 'codex-spark-test',
            'mode' => 'single_shot',
            'case_set_hash' => hash('sha256', 'spark'),
            'status' => 'failed',
            'total_cases' => 3,
            'passed_cases' => 2,
            'failed_cases' => 1,
            'pass_rate' => 66.67,
            'average_score' => 70,
            'duration_ms' => 20_000,
            'failed_control_count' => 1,
            'blocked_control_count' => 0,
            'skipped_required_control_count' => 0,
            'failed_test_count' => 1,
            'blocking_review_finding_count' => 1,
            'cost_microusd' => 50_000,
            'quality_metrics_json' => [],
            'runner_options_json' => [],
            'summary_json' => [],
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
            'metadata' => [],
        ]);

        $task = $this->task([
            'goal' => 'Fix critical database migration safely.',
            'acceptance_criteria' => ['No data loss.'],
            'likely_files' => ['database/migrations/example.php'],
        ]);
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'model_policy' => 'best-quality',
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
        ]);

        $this->assertSame('claude_cli', data_get($payload, 'run.model_selection.selected_provider'));
        $this->assertSame('claude-opus-test', data_get($payload, 'run.model_selection.selected_model'));
        $this->assertSame('benchmark_history', data_get($payload, 'run.model_selection.source'));
        $this->assertSame('critical', data_get($payload, 'run.model_selection.risk_profile'));
        $this->assertSame('claude-opus-test', data_get($payload, 'run.attempts.0.model'));
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'model_selection_policy',
            'status' => 'passed',
        ]);

        $run = AtlasEngineeringRun::query()->findOrFail((string) data_get($payload, 'run.id'));
        $this->assertSame('claude-opus-test', data_get($run->provider_strategy_json, 'model'));
        $this->assertSame('best_quality', data_get($run->provider_strategy_json, 'model_policy.effective_policy'));
    }

    public function test_attempt_comparison_ranks_best_repair_base(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['mode' => 'complete'],
            'status' => 'passed',
            'decision' => 'partial',
            'score' => 72,
            'max_attempts' => 2,
            'attempt_count' => 2,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now(),
            'metadata' => [],
        ]);
        $failedAttempt = AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'claude_cli',
            'model' => 'model-a',
            'phase' => 'edit',
            'prompt_hash' => hash('sha256', 'attempt-1'),
            'input_summary_json' => [],
            'status' => 'failed',
            'failure_summary' => 'Type error after patch.',
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(2),
            'metadata' => [],
        ]);
        $bestAttempt = AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 2,
            'provider' => 'codex_cli',
            'model' => 'model-b',
            'phase' => 'repair',
            'prompt_hash' => hash('sha256', 'attempt-2'),
            'input_summary_json' => [],
            'patch_hash' => hash('sha256', 'patch-2'),
            'changed_files_json' => ['src/example.txt'],
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        AtlasEngineeringPatchArtifact::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $bestAttempt->id,
            'base_ref' => 'HEAD',
            'head_ref' => 'atlas-attempt-2',
            'diff_hash' => hash('sha256', 'diff'),
            'diff_excerpt' => 'diff --git a/src/example.txt b/src/example.txt',
            'changed_files_json' => ['src/example.txt'],
            'created_files_json' => [],
            'deleted_files_json' => [],
            'risk_flags_json' => [],
            'metadata' => [],
        ]);
        AtlasEngineeringTestRun::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $failedAttempt->id,
            'command' => 'npm test',
            'status' => 'failed',
            'exit_code' => 1,
            'duration_ms' => 120,
            'stderr_excerpt' => 'Type error',
            'metadata' => [],
        ]);
        AtlasEngineeringTestRun::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $bestAttempt->id,
            'command' => 'npm test',
            'status' => 'passed',
            'exit_code' => 0,
            'duration_ms' => 90,
            'metadata' => [],
        ]);
        AtlasEngineeringControlResult::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $failedAttempt->id,
            'control_slug' => 'primary_test_command',
            'status' => 'failed',
            'signal_summary' => 'Tests failed.',
            'duration_ms' => 10,
            'metadata' => [],
        ]);
        AtlasEngineeringReviewFinding::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $failedAttempt->id,
            'task_id' => $task->id,
            'source' => 'quality_gate',
            'severity' => 'p1',
            'status' => 'open',
            'title' => 'Regression still failing',
            'body' => 'The first attempt did not satisfy the test gate.',
            'evidence_json' => [],
            'resolution_json' => [],
            'detected_at' => now(),
            'metadata' => [],
        ]);

        $summary = app(EngineeringHarnessRunnerService::class)->runSummary($run->refresh());

        $this->assertSame('ranked', data_get($summary, 'attempt_comparison.status'));
        $this->assertSame($bestAttempt->id, data_get($summary, 'attempt_comparison.best_attempt_id'));
        $this->assertSame(2, data_get($summary, 'attempt_comparison.best_attempt_number'));
        $this->assertSame('best_repair_base', data_get($summary, 'attempt_comparison.best_recommendation'));
        $this->assertSame($bestAttempt->id, data_get($summary, 'attempt_comparison.attempts.0.attempt_id'));
        $this->assertGreaterThan(
            data_get($summary, 'attempt_comparison.attempts.1.score'),
            data_get($summary, 'attempt_comparison.attempts.0.score'),
        );
        $this->assertContains('blocking_findings_open', data_get($summary, 'attempt_comparison.attempts.1.signals'));

        $replayPayload = app(EngineeringHarnessRunnerService::class)->replay($run->refresh(), [
            'workspace' => $this->workspace,
            'auto_test' => false,
        ]);

        $this->assertSame($bestAttempt->id, data_get($replayPayload, 'run.replay.source_recommended_attempt_id'));
        $this->assertSame(2, data_get($replayPayload, 'run.replay.source_recommended_attempt_number'));
        $this->assertSame('codex_cli', data_get($replayPayload, 'run.replay.replay_provider'));
        $this->assertSame('model-b', data_get($replayPayload, 'run.replay.source_recommended_attempt_model'));
        $this->assertSame('attempt_comparison', data_get($replayPayload, 'run.replay.replay_provider_source'));

        $replayRun = AtlasEngineeringRun::query()->findOrFail(data_get($replayPayload, 'run.id'));
        $this->assertSame('model-b', data_get($replayRun->provider_strategy_json, 'model'));
    }

    public function test_open_blocking_review_findings_prevent_resolved_decision(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'max_attempts' => 1,
        ]);
        $run = AtlasEngineeringRun::query()->findOrFail(data_get($payload, 'run.id'));

        app(EngineeringReviewFindingService::class)->record($run, [
            'severity' => 'p1',
            'title' => 'Missing regression assertion',
            'body' => 'The implementation is not acceptable until the regression path is covered.',
            'file_path' => 'src/example.txt',
            'start_line' => 1,
        ]);

        $score = app(EngineeringRunScoringService::class)->score($run->refresh(), ['goal' => 'x'], [
            'acceptance_matrix' => [['verification_method' => 'automated_test']],
        ]);

        $this->assertSame('unresolved', $score['decision']);
        $this->assertContains('Review finding aberto P1: Missing regression assertion', $score['blocking_reasons']);
        $this->assertSame(0, data_get($score, 'components.review'));
    }

    public function test_failed_latest_attempt_prevents_resolved_decision(): void
    {
        $task = $this->task();
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'max_attempts' => 1,
        ]);
        $run = AtlasEngineeringRun::query()->with('attempts')->findOrFail(data_get($payload, 'run.id'));
        $run->attempts->first()->forceFill([
            'status' => 'failed',
            'failure_summary' => 'Provider exited with an error after changing files.',
        ])->save();

        $score = app(EngineeringRunScoringService::class)->score($run->refresh(), ['goal' => 'x'], [
            'acceptance_matrix' => [['verification_method' => 'automated_test']],
        ]);

        $this->assertSame('unresolved', $score['decision']);
        $this->assertContains('Attempt final nao completou: #1 status failed', $score['blocking_reasons']);
    }

    public function test_worktree_workspace_can_be_prepared_and_released(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['sandbox' => 'worktree'],
            'status' => 'preparing',
            'max_attempts' => 1,
            'started_at' => now(),
            'metadata' => [],
        ]);

        $plan = app(EngineeringWorkspaceService::class)->prepare($this->workspace, $run, ['sandbox' => 'worktree']);

        $this->assertSame('worktree', $plan['mode']);
        $this->assertTrue((bool) $plan['isolated']);
        $this->assertDirectoryExists((string) $plan['execution_workspace']);
        $this->assertNotSame($this->workspace, $plan['execution_workspace']);

        $release = app(EngineeringWorkspaceService::class)->release($plan);

        $this->assertSame('released', $release['status']);
        $this->assertDirectoryDoesNotExist((string) $plan['execution_workspace']);
    }

    public function test_paired_worktree_bootstrap_symlinks_vendor_env_and_creates_writable_skeleton(): void
    {
        File::ensureDirectoryExists($this->workspace.'/vendor');
        File::put($this->workspace.'/vendor/autoload.php', "<?php // marker\n");
        File::ensureDirectoryExists($this->workspace.'/node_modules');
        File::put($this->workspace.'/node_modules/.marker', "x\n");
        File::put($this->workspace.'/.env', "APP_ENV=testing\n");

        $plan = app(EngineeringWorkspaceService::class)->preparePairedWorktree($this->workspace, 'baseline-bootstrap');

        try {
            $this->assertSame('ready', $plan['status']);
            $this->assertTrue((bool) $plan['isolated']);
            $worktree = (string) $plan['execution_workspace'];
            $this->assertDirectoryExists($worktree);

            $this->assertTrue(is_link($worktree.'/vendor'), 'vendor symlink missing in baseline worktree');
            $this->assertTrue(is_link($worktree.'/node_modules'), 'node_modules symlink missing');
            $this->assertTrue(is_link($worktree.'/.env'), '.env symlink missing');
            $this->assertFileExists($worktree.'/vendor/autoload.php');
            $this->assertSame("APP_ENV=testing\n", file_get_contents($worktree.'/.env'));

            foreach (['bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
                $this->assertDirectoryExists($worktree.'/'.$dir, "writable dir missing: {$dir}");
            }

            $bootstrap = (array) ($plan['bootstrapped_artifacts'] ?? []);
            $this->assertContains('vendor', (array) ($bootstrap['symlinks'] ?? []));
            $this->assertContains('node_modules', (array) ($bootstrap['symlinks'] ?? []));
            $this->assertContains('.env', (array) ($bootstrap['symlinks'] ?? []));
            $this->assertContains('storage/logs', (array) ($bootstrap['directories'] ?? []));
        } finally {
            app(EngineeringWorkspaceService::class)->release($plan);
        }
    }

    public function test_docker_workspace_request_falls_back_to_isolated_worktree_without_docker_profile(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['sandbox' => 'docker'],
            'status' => 'preparing',
            'max_attempts' => 1,
            'started_at' => now(),
            'metadata' => [],
        ]);

        $plan = app(EngineeringWorkspaceService::class)->prepare($this->workspace, $run, ['sandbox' => 'docker']);

        $this->assertSame('docker', $plan['requested_mode']);
        $this->assertSame('worktree', $plan['mode']);
        $this->assertSame('fallback', $plan['status']);
        $this->assertSame('docker_profile_missing', $plan['fallback_reason']);
        $this->assertFalse((bool) data_get($plan, 'docker.profile_found'));
        $this->assertFalse((bool) ($plan['containerized_execution'] ?? false));
        $this->assertTrue((bool) $plan['isolated']);
        $this->assertDirectoryExists((string) $plan['execution_workspace']);
        $this->assertNotSame($this->workspace, $plan['execution_workspace']);

        $release = app(EngineeringWorkspaceService::class)->release($plan);

        $this->assertSame('released', $release['status']);
        $this->assertDirectoryDoesNotExist((string) $plan['execution_workspace']);
    }

    public function test_required_provider_docker_runtime_fails_before_provider_when_compose_is_missing(): void
    {
        $task = $this->task();

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'permission' => 'danger',
            'sandbox' => 'workspace',
            'provider_runtime' => 'docker',
            'provider_docker_compose_file' => $this->workspace.'/missing-compose.yml',
            'provider_docker_service' => 'backend',
            'max_attempts' => 3,
        ]);

        $this->assertSame('unresolved', data_get($payload, 'run.decision'));
        $this->assertSame('adjusted', data_get($payload, 'run.autonomy_policy.status'));
        $this->assertSame('worktree', data_get($payload, 'run.autonomy_policy.effective_sandbox'));
        $this->assertSame('write', data_get($payload, 'run.autonomy_policy.effective_permission'));
        $this->assertSame(1, data_get($payload, 'run.autonomy_policy.effective_max_attempts'));
        $this->assertContains('sandbox:workspace->worktree', data_get($payload, 'run.autonomy_policy.actions'));
        $this->assertContains('permission:danger->write', data_get($payload, 'run.autonomy_policy.actions'));
        $this->assertContains('max_attempts:3->1', data_get($payload, 'run.autonomy_policy.actions'));
        $this->assertSame('worktree', data_get($payload, 'run.workspace.mode'));
        $this->assertSame('unavailable', data_get($payload, 'run.provider_runtime.status'));
        $this->assertSame('provider_docker_compose_file_missing', data_get($payload, 'run.provider_runtime.fallback_reason'));
        $this->assertDatabaseHas('atlas_engineering_runs', [
            'id' => data_get($payload, 'run.id'),
            'max_attempts' => 1,
        ]);
        $this->assertDatabaseHas('atlas_engineering_run_attempts', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'attempt_number' => 1,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'harnessability_autonomy_policy',
            'status' => 'warning',
        ]);
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'provider_runtime_isolation',
            'status' => 'failed',
        ]);
    }

    public function test_docker_artifact_export_copies_configured_artifacts_from_workspace(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['sandbox' => 'docker'],
            'status' => 'testing',
            'max_attempts' => 1,
            'started_at' => now(),
            'metadata' => [],
        ]);
        $testRun = AtlasEngineeringTestRun::query()->create([
            'engineering_run_id' => $run->id,
            'command' => 'npm test',
            'exit_code' => 0,
            'status' => 'passed',
            'duration_ms' => 10,
            'metadata' => [],
        ]);
        File::ensureDirectoryExists($this->workspace.'/test-results');
        File::put($this->workspace.'/test-results/junit.xml', '<testsuite />');

        $result = app(EngineeringDockerHarnessService::class)->captureArtifacts($run, $testRun, $this->workspace, [
            'mode' => 'docker',
            'containerized_execution' => true,
            'docker' => [
                'artifacts' => [
                    'paths' => ['test-results'],
                    'max_files' => 10,
                    'max_bytes' => 10000,
                ],
            ],
        ]);

        $testRun->refresh();
        $this->assertSame('captured', $result['status']);
        $this->assertNotNull($testRun->artifact_path);
        $this->assertFileExists($testRun->artifact_path.'/test-results/junit.xml');
        $this->assertSame('captured', data_get($testRun->metadata, 'artifact_export.status'));

        $this->getJson("/engineering/runs/{$run->id}/test-runs/{$testRun->id}/artifacts", $this->headers)
            ->assertOk()
            ->assertJsonPath('test_run_id', $testRun->id)
            ->assertJsonPath('files.0.path', 'test-results/junit.xml')
            ->assertJsonPath('files.0.kind', 'xml')
            ->assertJsonPath('files.0.readable_inline', true);

        $artifactContent = $this->getJson("/engineering/runs/{$run->id}/test-runs/{$testRun->id}/artifacts/content?path=".urlencode('test-results/junit.xml'), $this->headers)
            ->assertOk()
            ->assertJsonPath('artifact.path', 'test-results/junit.xml')
            ->assertJsonPath('artifact.kind', 'xml')
            ->assertJsonPath('artifact.truncated', false);
        $this->assertSame('<testsuite />', data_get($artifactContent->json(), 'content'));

        $this->getJson("/engineering/runs/{$run->id}/test-runs/{$testRun->id}/artifacts/content?path=".urlencode('../junit.xml'), $this->headers)
            ->assertNotFound();
    }

    public function test_worktree_patch_can_be_applied_to_original_workspace(): void
    {
        $task = $this->task();
        $run = AtlasEngineeringRun::query()->create([
            'task_id' => $task->id,
            'workspace_path_hash' => hash('sha256', $this->workspace),
            'workspace_label' => basename($this->workspace),
            'provider_strategy_json' => ['sandbox' => 'worktree'],
            'status' => 'preparing',
            'max_attempts' => 1,
            'started_at' => now(),
            'metadata' => [],
        ]);
        $attempt = AtlasEngineeringRunAttempt::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_number' => 1,
            'phase' => 'edit',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
        ]);
        $plan = app(EngineeringWorkspaceService::class)->prepare($this->workspace, $run, ['sandbox' => 'worktree']);
        File::put($plan['execution_workspace'].'/src/example.txt', "changed in isolation\n");

        $patch = app(EngineeringPatchArtifactService::class)->capture($run, $attempt, (string) $plan['execution_workspace']);
        $result = app(EngineeringWorkspaceService::class)->applyPatchToOriginal($plan, $patch);

        $this->assertSame('applied', $result['status']);
        $this->assertSame("changed in isolation\n", File::get($this->workspace.'/src/example.txt'));

        app(EngineeringWorkspaceService::class)->release($plan);
    }

    public function test_visual_behaviour_gate_keeps_run_partial_without_manual_evidence(): void
    {
        $task = $this->task([
            'goal' => 'Corrigir layout visual no frontend mobile.',
            'acceptance_criteria' => ['Screenshot mobile sem sobreposicao.'],
            'likely_files' => ['src/example.txt'],
            'test_coverage' => ['Comando de validacao passa.'],
        ]);
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'max_attempts' => 1,
        ]);

        $this->assertSame('partial', data_get($payload, 'run.decision'));
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'manual_behaviour_evidence',
            'status' => 'skipped',
        ]);
    }

    public function test_visual_e2e_script_satisfies_visual_gate_and_exports_artifacts(): void
    {
        $task = $this->task([
            'goal' => 'Corrigir layout visual no frontend mobile.',
            'acceptance_criteria' => ['Screenshot mobile sem sobreposicao.'],
            'likely_files' => ['src/example.txt'],
            'test_coverage' => ['Playwright cobre o fluxo visual.'],
        ]);
        File::put($this->workspace.'/src/example.txt', "after\n");
        File::put($this->workspace.'/package.json', json_encode([
            'scripts' => [
                'e2e' => escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('mkdir("playwright-report", 0777, true); file_put_contents("playwright-report/index.html", "ok"); exit(0);'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'visual_e2e' => 'required',
            'max_attempts' => 1,
        ]);

        $this->assertSame('resolved', data_get($payload, 'run.decision'));
        $this->assertSame(2, data_get($payload, 'test_run_count'));
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'manual_behaviour_evidence',
            'status' => 'passed',
        ]);

        $visualRun = AtlasEngineeringTestRun::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('command', 'npm run e2e')
            ->firstOrFail();

        $this->assertSame('captured', data_get($visualRun->metadata, 'visual_artifact_export.status'));
        $this->assertTrue(File::exists($visualRun->artifact_path.'/playwright-report/index.html'));
    }

    public function test_managed_visual_smoke_satisfies_visual_gate_without_e2e_scripts(): void
    {
        $task = $this->task([
            'goal' => 'Corrigir layout visual no frontend mobile.',
            'acceptance_criteria' => ['Tela inicial renderiza sem erro fatal.'],
            'likely_files' => ['src/example.txt'],
            'test_coverage' => ['Atlas visual smoke cobre a rota inicial.'],
        ]);
        File::put($this->workspace.'/src/example.txt', "after\n");
        File::ensureDirectoryExists($this->workspace.'/public');
        File::put($this->workspace.'/public/index.php', '<?php echo "<html><body>Atlas managed smoke</body></html>";');

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'visual_e2e' => 'required',
            'max_attempts' => 1,
        ]);

        $this->assertSame('resolved', data_get($payload, 'run.decision'));
        $this->assertSame(2, data_get($payload, 'test_run_count'));
        $this->assertDatabaseHas('atlas_engineering_control_results', [
            'engineering_run_id' => data_get($payload, 'run.id'),
            'control_slug' => 'manual_behaviour_evidence',
            'status' => 'passed',
        ]);

        $visualRun = AtlasEngineeringTestRun::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('command', 'like', '%atlas:engineering:visual-smoke%')
            ->firstOrFail();

        $this->assertSame('atlas_managed_visual_smoke', data_get($visualRun->metadata, 'visual_e2e.detected_by'));
        $this->assertSame('captured', data_get($visualRun->metadata, 'visual_artifact_export.status'));
        $this->assertSame('passed', data_get($visualRun->metadata, 'visual_smoke.status'));
        $this->assertSame(1, data_get($visualRun->metadata, 'visual_smoke.route_count'));
        $this->assertSame('first_baseline', data_get($visualRun->metadata, 'visual_smoke.routes.0.baseline_status'));
        $this->assertNotEmpty(data_get($visualRun->metadata, 'visual_smoke.routes.0.baseline_file_hash'));
        $this->assertTrue(File::exists($visualRun->artifact_path.'/atlas-visual-report/manifest.json'));
        $this->assertTrue(File::exists($visualRun->artifact_path.'/atlas-visual-report/routes/root.html'));

        $toolRun = AtlasToolRun::query()
            ->where('tool_slug', 'atlas_visual_smoke')
            ->where('run_context_type', 'engineering_run')
            ->where('run_context_id', data_get($payload, 'run.id'))
            ->where('surface', 'engineering_visual_smoke')
            ->firstOrFail();
        $this->assertSame('passed', $toolRun->status);

        $gateControl = AtlasEngineeringControlResult::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('control_slug', 'atlas_tool_runtime_visual_gate')
            ->firstOrFail();
        $this->assertSame('passed', $gateControl->status);
        $this->assertTrue((bool) data_get($gateControl->metadata, 'gate.allowed'));
        $this->assertSame('engineering_run', data_get($gateControl->metadata, 'gate.filters.run_context_type'));
        $this->assertSame(data_get($payload, 'run.id'), data_get($gateControl->metadata, 'gate.filters.run_context_id'));
    }

    public function test_quality_scan_can_run_as_atlas_managed_sensor(): void
    {
        $task = $this->task([
            'goal' => 'Validar qualidade local antes de aceitar a mudanca.',
            'acceptance_criteria' => ['Quality scan do Atlas executa sem servico pago.'],
            'likely_files' => ['src/example.txt'],
            'test_coverage' => ['Atlas quality scan cobre ferramentas locais detectadas.'],
        ]);
        File::put($this->workspace.'/src/example.txt', "after\n");

        $payload = app(EngineeringHarnessRunnerService::class)->run($task, [
            'workspace' => $this->workspace,
            'no_provider' => true,
            'auto_test' => true,
            'test_command' => $this->passingPhpCommand(),
            'quality_scan' => 'required',
            'quality_profile' => 'fast',
            'quality_changed_only' => true,
            'max_attempts' => 1,
        ]);

        $this->assertSame('resolved', data_get($payload, 'run.decision'));
        $this->assertSame(2, data_get($payload, 'test_run_count'));

        $qualityRun = AtlasEngineeringTestRun::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('command', 'like', '%atlas:engineering:quality-scan%')
            ->firstOrFail();

        $this->assertSame('passed', $qualityRun->status);
        $this->assertTrue((bool) data_get($qualityRun->metadata, 'atlas_managed'));
        $this->assertSame('atlas_managed_quality_scan', data_get($qualityRun->metadata, 'quality_scan.detected_by'));
        $this->assertSame('passed', data_get($qualityRun->metadata, 'quality_scan_result.status'));
        $this->assertStringContainsString('--run-context-type', (string) data_get($qualityRun->metadata, 'runtime_command'));
        $this->assertFalse((bool) data_get($qualityRun->metadata, 'quality_scan_result.paid_tool_required'));
        $this->assertGreaterThan(0, data_get($qualityRun->metadata, 'quality_scan_result.summary.tool_count'));
        $this->assertSame('captured', data_get($qualityRun->metadata, 'quality_artifact_export.status'));
        $this->assertNotNull($qualityRun->artifact_path);
        $this->assertFileExists($qualityRun->artifact_path.'/scan.json');

        $toolRun = AtlasToolRun::query()
            ->where('run_context_type', 'engineering_run')
            ->where('run_context_id', data_get($payload, 'run.id'))
            ->where('surface', 'engineering_quality_scan')
            ->firstOrFail();
        $this->assertSame($this->workspace, $toolRun->workspace);

        $gateControl = AtlasEngineeringControlResult::query()
            ->where('engineering_run_id', data_get($payload, 'run.id'))
            ->where('control_slug', 'atlas_tool_runtime_gate')
            ->firstOrFail();
        $this->assertSame('passed', $gateControl->status);
        $this->assertTrue((bool) data_get($gateControl->metadata, 'gate.allowed'));
        $this->assertContains(data_get($gateControl->metadata, 'gate.status'), ['passed', 'warning']);
        $this->assertSame('engineering_run', data_get($gateControl->metadata, 'gate.filters.run_context_type'));
        $this->assertSame(data_get($payload, 'run.id'), data_get($gateControl->metadata, 'gate.filters.run_context_id'));

        $this->getJson('/engineering/runs/'.data_get($payload, 'run.id')."/test-runs/{$qualityRun->id}/artifacts", $this->headers)
            ->assertOk()
            ->assertJsonPath('test_run_id', $qualityRun->id)
            ->assertJsonFragment([
                'path' => 'scan.json',
                'kind' => 'json',
                'readable_inline' => true,
            ]);

        $artifactContent = $this->getJson('/engineering/runs/'.data_get($payload, 'run.id')."/test-runs/{$qualityRun->id}/artifacts/content?path=".urlencode('scan.json'), $this->headers)
            ->assertOk()
            ->assertJsonPath('artifact.path', 'scan.json')
            ->assertJsonPath('artifact.kind', 'json');
        $this->assertStringContainsString('"paid_tool_required": false', (string) data_get($artifactContent->json(), 'content'));
    }

    public function test_engineering_benchmark_final_packet_rejects_self_assessment_as_passing_gate(): void
    {
        $packet = $this->invokeBenchmarkFinalPacket(
            status: 'passed',
            results: [
                $this->benchmarkResultWithPairedScorecard($this->fairPairedScorecardSelfReportedPassed()),
            ],
            quality: $this->benchmarkQualityClean(),
            releaseGate: $this->releaseGatePassed(),
            manifest: $this->replayManifestEnabled(packetCount: 1, deterministicGatePacketCount: 0),
        );

        $this->assertSame('engineering_benchmark_final_packet', $packet['kind']);
        $this->assertSame('atlas_deterministic', $packet['evaluator']);
        $this->assertFalse($packet['evaluator_verified']);
        $this->assertSame('unverified', $packet['status']);
        $this->assertSame('passed', $packet['benchmark_status']);
        $this->assertContains('deterministic_gate_packet_missing', $packet['decision_reasons']);
        $this->assertFalse($packet['self_assessment']['authoritative']);
        $this->assertSame(1, $packet['gates']['fair_scorecard_count']);
        $this->assertSame(0, $packet['gates']['deterministic_gate_packet_count']);
        $this->assertSame(1, $packet['gates']['deterministic_gate_packet_total']);
        $this->assertStringStartsWith('atlas benchmark claude-fair replay ', (string) $packet['replay_command']);
        $this->assertSame('claude_cli', $packet['provider_lock']);
        $this->assertSame('opus', $packet['model_lock']);
    }

    public function test_engineering_benchmark_final_packet_marks_invalid_on_provider_lock_violation(): void
    {
        $scorecard = $this->fairPairedScorecardSelfReportedPassed();
        $scorecard['atlas']['provider_violation_count'] = 1;
        $scorecard['atlas']['protocol_valid'] = false;
        $scorecard['atlas']['verified'] = false;
        $scorecard['atlas']['pass_without_human'] = false;

        $packet = $this->invokeBenchmarkFinalPacket(
            status: 'failed',
            results: [
                $this->benchmarkResultWithPairedScorecard($scorecard),
            ],
            quality: $this->benchmarkQualityClean(),
            releaseGate: $this->releaseGatePassed(),
            manifest: $this->replayManifestEnabled(packetCount: 1, deterministicGatePacketCount: 1),
        );

        $this->assertSame('invalid', $packet['status']);
        $this->assertFalse($packet['evaluator_verified']);
        $this->assertContains('provider_lock_violation', $packet['decision_reasons']);
        $this->assertContains('fair_protocol_invalid', $packet['decision_reasons']);
        $this->assertSame(1, $packet['gates']['provider_violation_count']);
        $this->assertFalse($packet['protocol_valid']);
    }

    public function test_engineering_benchmark_final_packet_marks_failed_when_tests_fail(): void
    {
        $packet = $this->invokeBenchmarkFinalPacket(
            status: 'passed',
            results: [
                $this->benchmarkResultWithPairedScorecard($this->fairPairedScorecardSelfReportedPassed()),
            ],
            quality: $this->benchmarkQualityClean(failedTestCount: 2),
            releaseGate: $this->releaseGatePassed(),
            manifest: $this->replayManifestEnabled(packetCount: 1, deterministicGatePacketCount: 1),
        );

        $this->assertSame('failed', $packet['status']);
        $this->assertFalse($packet['evaluator_verified']);
        $this->assertContains('failed_tests_present', $packet['decision_reasons']);
        $this->assertSame(2, $packet['tests']['failed_test_count']);
    }

    public function test_engineering_benchmark_final_packet_passes_when_atlas_deterministic_gates_all_pass(): void
    {
        $packet = $this->invokeBenchmarkFinalPacket(
            status: 'passed',
            results: [
                $this->benchmarkResultWithPairedScorecard($this->fairPairedScorecardSelfReportedPassed()),
            ],
            quality: $this->benchmarkQualityClean(),
            releaseGate: $this->releaseGatePassed(),
            manifest: $this->replayManifestEnabled(packetCount: 1, deterministicGatePacketCount: 1),
        );

        $this->assertSame('passed', $packet['status']);
        $this->assertTrue($packet['evaluator_verified']);
        $this->assertSame([], $packet['decision_reasons']);
        $this->assertSame(1, $packet['gates']['deterministic_gate_packet_count']);
        $this->assertSame(1, $packet['gates']['deterministic_gate_packet_total']);
        $this->assertSame(1, $packet['gates']['fair_scorecard_atlas_verified_count']);
        $this->assertFalse($packet['self_assessment']['authoritative']);
        $this->assertSame('claude_cli', $packet['provider_lock']);
        $this->assertSame('opus', $packet['model_lock']);
    }

    /**
     * @param  array<int,AtlasEngineeringBenchmarkResult>  $results
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $releaseGate
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function invokeBenchmarkFinalPacket(
        string $status,
        array $results,
        array $quality,
        array $releaseGate,
        array $manifest,
    ): array {
        $service = app(EngineeringBenchmarkService::class);
        $reflection = new \ReflectionMethod(EngineeringBenchmarkService::class, 'benchmarkFinalPacket');
        $reflection->setAccessible(true);

        $run = new AtlasEngineeringBenchmarkRun;
        $run->id = 'run-'.bin2hex(random_bytes(4));

        return $reflection->invoke(
            $service,
            $run,
            collect($results),
            $status,
            $quality,
            $releaseGate,
            $manifest,
        );
    }

    /**
     * @param  array<string,mixed>  $scorecard
     */
    private function benchmarkResultWithPairedScorecard(array $scorecard): AtlasEngineeringBenchmarkResult
    {
        $result = new AtlasEngineeringBenchmarkResult;
        $result->id = 'result-'.bin2hex(random_bytes(4));
        $result->status = 'passed';
        $result->passed = true;
        $result->observed_json = ['paired_scorecard' => $scorecard];

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function fairPairedScorecardSelfReportedPassed(): array
    {
        return [
            'schema_version' => 1,
            'fair_mode' => true,
            'comparison_status' => 'comparable',
            'comparable' => true,
            'winner' => 'tie',
            'case' => [
                'id' => 'case-1',
                'case_code' => 'fair_test_case',
                'risk_profile' => 'medium',
            ],
            'atlas' => [
                'provider' => 'atlas',
                'decision' => 'resolved',
                'score' => 95,
                'passed' => true,
                'verified' => true,
                'protocol_valid' => true,
                'fair_scorecard_passed' => true,
                'final_gate_passed' => true,
                'pass_without_human' => true,
                'pass_without_human_reported' => true,
                'human_intervention_count' => 0,
                'provider_violation_count' => 0,
                'fallback_violation_count' => 0,
                'attempt_count' => 1,
                'repair_attempt_count' => 0,
                'repair_used' => false,
                'converted_to_green' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function benchmarkQualityClean(int $failedTestCount = 0, int $failedControlCount = 0): array
    {
        return [
            'failed_test_count' => $failedTestCount,
            'failed_tests' => [],
            'failed_control_count' => $failedControlCount,
            'blocked_control_count' => 0,
            'risk_flag_count' => 0,
            'risk_flags' => [],
            'changed_files_count' => 1,
            'total_attempts' => 1,
            'cost_microusd' => 0,
            'telemetry_trace_ids' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseGatePassed(): array
    {
        return [
            'status' => 'passed',
            'profile' => 'fair_claude',
            'failures' => [],
            'warnings' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replayManifestEnabled(int $packetCount, int $deterministicGatePacketCount): array
    {
        return [
            'enabled' => true,
            'packet_count' => $packetCount,
            'deterministic_gate_packet_count' => $deterministicGatePacketCount,
            'manifest_hash' => hash('sha256', 'manifest-'.$packetCount.'-'.$deterministicGatePacketCount),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $contract
     */
    private function task(?array $contract = null): AtlasTask
    {
        return AtlasTask::query()->create([
            'title' => 'Implementar Runner profissional',
            'description' => 'Harness precisa registrar run, controls, patch e score.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 60,
            'metadata' => [
                'engineering_contract' => $contract ?: [
                    'goal' => 'Executar harness runner.',
                    'acceptance_criteria' => ['Run registra patch, teste e score.'],
                    'likely_files' => ['src/example.txt'],
                    'test_coverage' => ['Comando de validacao passa.'],
                ],
            ],
        ]);
    }

    private function passingPhpCommand(): string
    {
        return escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('exit(0);');
    }

    private function canonicalJsonForHash(mixed $value): string
    {
        $encoded = json_encode(
            $this->canonicalSortForHash($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) ? $encoded : '';
    }

    private function canonicalSortForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalSortForHash($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $sub) {
            $value[$key] = $this->canonicalSortForHash($sub);
        }

        return $value;
    }

    private function deleteDirectoryQuietly(string $directory): void
    {
        if (! File::isDirectory($directory)) {
            return;
        }

        rescue(fn () => File::deleteDirectory($directory), report: false);
    }

    private function resetMutableAtlasConfig(): void
    {
        config()->set('atlas.ai.default_provider', 'claude_cli');
        config()->set('atlas.ai.providers.claude_cli.model', null);
        config()->set('atlas.ai.providers.claude_cli.model_label', 'Claude CLI default');
        config()->set('atlas.ai.providers.claude_cli.model_identity', 'claude_cli_default');
        config()->set('atlas.ai.providers.claude_cli.premium_model', 'claude-opus-4-7');
        config()->set('atlas.ai.providers.claude_cli.premium_model_label', 'Claude Opus 4.7');
        config()->set('atlas.ai.providers.claude_cli.allow_auto', true);
        config()->set('atlas.ai.providers.claude_cli.allow_manual', true);
        config()->set('atlas.ai.providers.codex_cli.model', null);
        config()->set('atlas.ai.providers.codex_cli.model_label', 'Codex CLI default');
        config()->set('atlas.ai.providers.codex_cli.model_identity', 'codex_cli_default');
        config()->set('atlas.ai.providers.codex_cli.premium_model', 'gpt-5.5');
        config()->set('atlas.ai.providers.codex_cli.premium_model_label', 'GPT-5.5');
        config()->set('atlas.ai.providers.codex_cli.allow_auto', false);
        config()->set('atlas.ai.providers.codex_cli.allow_manual', true);
        config()->set('atlas.ai.providers.gemini_cli.allow_auto', false);
        config()->set('atlas.ai.providers.gemini_cli.allow_manual', true);
        config()->set('atlas.engineering.visual_e2e.artifact_max_files', EngineeringTestMatrixInput::DEFAULT_VISUAL_ARTIFACT_MAX_FILES);
        config()->set('atlas.engineering.visual_e2e.artifact_max_bytes', EngineeringTestMatrixInput::DEFAULT_VISUAL_ARTIFACT_MAX_BYTES);
        config()->set('atlas.engineering.quality_scan.artifact_max_files', EngineeringTestMatrixInput::DEFAULT_QUALITY_ARTIFACT_MAX_FILES);
        config()->set('atlas.engineering.quality_scan.artifact_max_bytes', EngineeringTestMatrixInput::DEFAULT_QUALITY_ARTIFACT_MAX_BYTES);
    }

    private function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-engineering-runner-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src');
        File::put($workspace.'/src/example.txt', "before\n");
        File::put($workspace.'/README.md', "# Test repo\n");
        (new Process(['git', 'init'], $workspace))->run();
        (new Process(['git', 'config', 'user.email', 'atlas@example.test'], $workspace))->run();
        (new Process(['git', 'config', 'user.name', 'Atlas Test'], $workspace))->run();
        (new Process(['git', 'add', '.'], $workspace))->run();
        (new Process(['git', 'commit', '-m', 'Initial commit'], $workspace))->run();

        return realpath($workspace) ?: $workspace;
    }

    private function seedInvalidFairClaudeBattery(string $slug): AtlasEngineeringBenchmarkSuite
    {
        $suite = AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => $slug,
            'name' => 'Invalid Fair Claude battery',
            'status' => 'active',
            'default_runner_options_json' => [],
            'metadata' => [
                'corpus_manifest' => [
                    'active_cases' => 6,
                    'official_subsets' => ['release' => 6],
                ],
            ],
        ]);
        $case = app(EngineeringBenchmarkService::class)->registerCase($suite, [
            'case_code' => 'invalid_battery_rerun_guard',
            'title' => 'Invalid battery rerun guard',
            'workspace' => $this->workspace,
            'expected_decision' => 'resolved',
            'min_score' => 90,
            'corpus_tier' => 'release',
        ]);
        $run = AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => 'fair:invalid-battery-rerun',
            'provider' => 'claude_cli',
            'model' => 'opus',
            'mode' => 'single_shot',
            'status' => 'failed',
            'total_cases' => 1,
            'passed_cases' => 0,
            'failed_cases' => 1,
            'runner_options_json' => ['claude_only' => true],
            'summary_json' => [
                'paired_scorecard' => [
                    'enabled' => true,
                    'case_count' => 1,
                    'fair_mode_count' => 1,
                    'comparable_count' => 1,
                    'winners' => ['claude_code_baseline' => 1],
                    'claude_code_baseline_win_count' => 1,
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
            'metadata' => [],
        ]);
        AtlasEngineeringBenchmarkResult::query()->create([
            'benchmark_run_id' => $run->id,
            'suite_id' => $suite->id,
            'case_id' => $case->id,
            'status' => 'failed',
            'decision' => 'unsafe',
            'score' => 37,
            'passed' => false,
            'duration_ms' => 1000,
            'failure_summary' => 'fair protocol invalid',
            'expectation_json' => [],
            'observed_json' => [
                'paired_scorecard' => [
                    'schema_version' => 1,
                    'fair_mode' => true,
                    'comparison_status' => 'comparable',
                    'comparable' => true,
                    'winner' => 'claude_code_baseline',
                    'atlas' => [
                        'verified' => false,
                        'protocol_valid' => false,
                        'provider_violation_count' => 0,
                        'fallback_violation_count' => 0,
                    ],
                    'claude_code_baseline' => [
                        'verified' => true,
                        'passed' => true,
                        'pass_without_human' => true,
                        'score' => 100,
                    ],
                    'blocking_reasons' => ['atlas_not_verified_pass'],
                ],
                'claude_code_baseline' => [
                    'enabled' => true,
                    'status' => 'completed',
                    'executed' => true,
                    'provider' => 'claude_code_cli',
                    'model' => 'opus',
                ],
            ],
            'metadata' => [],
        ]);

        return $suite;
    }

    private function createTables(): void
    {
        $this->dropTables();

        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain')->default('atlas');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable()->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->string('intent')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text')->nullable();
            $table->text('prompt')->nullable();
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->string('worker_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('type', 40);
            $table->string('category', 40)->nullable();
            $table->string('severity', 16)->default('info');
            $table->string('status', 24)->default('unread');
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('initiator', 32)->default('system');
            $table->uuid('context_bundle_id')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->json('available_actions')->default('[]');
            $table->json('response')->nullable();
            $table->json('payload')->default('{}');
            $table->text('deep_link')->nullable();
            $table->json('push_policy')->default('{}');
            $table->smallInteger('priority_score')->default(50);
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->text('expo_push_token')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('summary');
            $table->string('command', 500)->nullable();
            $table->text('artifact_url')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->string('source', 160)->default('tasks.engineering.evidence');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->string('type', 40)->default('validator');
            $table->string('category', 80);
            $table->text('description')->nullable();
            $table->string('homepage', 240)->nullable();
            $table->string('license_posture', 80)->default('open_source');
            $table->string('cost_posture', 80)->default('free_local');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedSmallInteger('default_timeout_seconds')->default(120);
            $table->string('default_failure_policy', 40)->default('advisory');
            $table->string('risk_level', 24)->default('low');
            $table->string('status', 32)->default('active');
            $table->string('execution_tier', 24)->default('T1');
            $table->string('expected_cost', 40)->default('local_fast');
            $table->string('default_trigger', 80)->default('manual_or_policy');
            $table->string('authority_role', 40)->default('primary');
            $table->string('authority_group', 80)->nullable();
            $table->string('detected_version', 120)->nullable();
            $table->json('capabilities_json')->default('[]');
            $table->json('runtime_json')->default('{}');
            $table->json('detect_json')->default('{}');
            $table->json('outputs_json')->default('[]');
            $table->json('risks_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id');
            $table->string('workspace_hash', 64);
            $table->string('execution_layer', 40);
            $table->string('status', 32);
            $table->string('version', 120)->nullable();
            $table->string('binary_path_hash', 64)->nullable();
            $table->string('node_modules_path_hash', 64)->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 40)->default('global');
            $table->string('scope_id', 120)->nullable();
            $table->string('tool_slug', 120);
            $table->boolean('enabled')->default(true);
            $table->json('required_when_json')->default('[]');
            $table->string('failure_policy', 40)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->json('thresholds_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->nullable();
            $table->string('tool_slug', 120);
            $table->string('surface', 80)->default('cli');
            $table->string('workspace_hash', 64)->nullable();
            $table->text('workspace')->nullable();
            $table->string('run_context_type', 80)->nullable();
            $table->string('run_context_id', 120)->nullable();
            $table->string('status', 32);
            $table->boolean('required')->default(false);
            $table->string('failure_policy', 40)->default('advisory');
            $table->string('policy_decision', 40)->default('allowed');
            $table->string('command_hash', 64)->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->uuid('stdout_artifact_id')->nullable();
            $table->uuid('stderr_artifact_id')->nullable();
            $table->json('summary_json')->default('{}');
            $table->json('normalized_result_json')->default('{}');
            $table->json('policy_decision_json')->default('{}');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('type', 60);
            $table->text('path');
            $table->string('filename', 180);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64);
            $table->boolean('is_redacted')->default(true);
            $table->json('preview_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('rule_id', 180)->nullable();
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('severity', 24)->default('medium');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->boolean('blocks_resolved')->default(false);
            $table->string('waiver_id', 120)->nullable();
            $table->string('status', 32)->default('open');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->string('status', 32)->default('frozen');
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 120)->default('atlas_engineering_contract');
            $table->json('contract_json')->default('{}');
            $table->json('blueprint_json')->default('{}');
            $table->string('content_hash', 64);
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'content_hash']);
        });

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('blueprint_snapshot_id')->nullable();
            $table->string('blueprint_id', 120)->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('context_pack_id')->nullable();
            $table->string('workspace_path_hash', 64);
            $table->string('workspace_label', 180);
            $table->json('provider_strategy_json')->default('{}');
            $table->string('context_pack_hash', 64)->nullable();
            $table->unsignedSmallInteger('harnessability_score')->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('decision', 32)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_harnessability_calibrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('sample_limit')->default(300);
            $table->unsignedInteger('sample_count')->default(0);
            $table->string('confidence', 24)->default('none');
            $table->json('sample_window_json')->default('{}');
            $table->json('current_thresholds_json')->default('{}');
            $table->json('recommended_thresholds_json')->default('{}');
            $table->json('bucket_metrics_json')->default('{}');
            $table->json('outcome_metrics_json')->default('{}');
            $table->json('recommendations_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('calibrated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_run_operator_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('action', 40);
            $table->string('actor', 120)->default('operator');
            $table->string('status_before', 32)->nullable();
            $table->string('decision_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->string('decision_after', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_run_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->uuid('trace_id')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('phase', 32)->default('edit');
            $table->string('prompt_hash', 64)->nullable();
            $table->json('input_summary_json')->default('{}');
            $table->string('patch_hash', 64)->nullable();
            $table->json('diff_stat_json')->default('{}');
            $table->json('changed_files_json')->default('[]');
            $table->string('status', 32)->default('completed');
            $table->text('failure_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_patch_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->uuid('attempt_id')->nullable();
            $table->string('base_ref', 120)->nullable();
            $table->string('head_ref', 120)->nullable();
            $table->string('diff_hash', 64)->nullable();
            $table->text('diff_excerpt')->nullable();
            $table->text('diff_path')->nullable();
            $table->json('changed_files_json')->default('[]');
            $table->json('created_files_json')->default('[]');
            $table->json('deleted_files_json')->default('[]');
            $table->json('risk_flags_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->string('direction', 24);
            $table->string('execution_type', 24);
            $table->string('regulation_category', 64);
            $table->string('timing', 40);
            $table->boolean('required')->default(false);
            $table->string('risk_level', 24)->default('low');
            $table->json('applies_when_json')->default('{}');
            $table->string('failure_policy', 40)->default('advisory');
            $table->string('command', 500)->nullable();
            $table->string('skill_slug', 120)->nullable();
            $table->json('metadata')->default('{}');
            $table->string('definition_hash', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('versioned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_control_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('control_id')->nullable();
            $table->string('slug', 120);
            $table->unsignedInteger('version');
            $table->string('definition_hash', 64);
            $table->json('definition_json')->default('{}');
            $table->string('changed_by', 120)->default('atlas_engineering_control_registry');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['slug', 'definition_hash']);
        });

        Schema::create('atlas_engineering_control_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->uuid('attempt_id')->nullable();
            $table->uuid('control_id')->nullable();
            $table->string('control_slug', 120);
            $table->string('control_definition_hash', 64)->nullable();
            $table->unsignedInteger('control_version')->nullable();
            $table->string('status', 32);
            $table->text('signal_summary');
            $table->text('output_excerpt')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_test_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->string('blueprint_id', 120)->nullable();
            $table->uuid('control_id')->nullable();
            $table->string('case_code', 120);
            $table->string('source', 32)->default('detected');
            $table->string('type', 32);
            $table->string('priority', 8)->default('p1');
            $table->string('command', 500)->nullable();
            $table->text('expected_signal')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(900);
            $table->boolean('required')->default(true);
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['task_id', 'case_code']);
        });

        Schema::create('atlas_engineering_test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->uuid('attempt_id')->nullable();
            $table->uuid('test_case_id')->nullable();
            $table->string('command', 500)->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status', 32);
            $table->integer('duration_ms')->default(0);
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_context_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->nullable();
            $table->uuid('task_id');
            $table->string('hash', 64);
            $table->json('contract_json')->default('{}');
            $table->json('blueprint_json')->default('{}');
            $table->json('repo_profile_json')->default('{}');
            $table->json('selected_files_json')->default('[]');
            $table->json('prior_runs_json')->default('[]');
            $table->json('memory_refs_json')->default('[]');
            $table->json('prompt_sections_json')->default('[]');
            $table->json('token_budget_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_review_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->uuid('attempt_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->string('source', 80)->default('manual_review');
            $table->string('severity', 8)->default('p2');
            $table->string('status', 32)->default('open');
            $table->decimal('confidence', 5, 3)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->json('evidence_json')->default('{}');
            $table->text('recommendation')->nullable();
            $table->json('resolution_json')->default('{}');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_benchmark_suites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->json('default_runner_options_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_benchmark_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('suite_id');
            $table->uuid('task_id')->nullable();
            $table->string('case_code', 120);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('workspace_path_hash', 64)->nullable();
            $table->json('task_contract_json')->default('{}');
            $table->json('runner_options_json')->default('{}');
            $table->string('expected_decision', 32)->nullable();
            $table->unsignedSmallInteger('min_score')->default(85);
            $table->string('corpus_tier', 40)->nullable();
            $table->string('domain_slug', 80)->nullable();
            $table->string('risk_profile', 40)->nullable();
            $table->string('curation_status', 40)->default('candidate');
            $table->unsignedSmallInteger('curation_score')->nullable();
            $table->string('corpus_fingerprint', 64)->nullable();
            $table->timestamp('curated_at')->nullable();
            $table->json('tags_json')->default('[]');
            $table->string('status', 32)->default('active');
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['suite_id', 'case_code']);
        });

        Schema::create('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('suite_id');
            $table->string('benchmark_key', 180)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('mode', 40)->nullable();
            $table->string('case_set_hash', 64)->nullable();
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('passed_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);
            $table->uuid('baseline_run_id')->nullable();
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->decimal('pass_rate_delta', 6, 2)->nullable();
            $table->decimal('average_score', 6, 2)->nullable();
            $table->decimal('average_score_delta', 6, 2)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('trend_status', 32)->nullable();
            $table->string('harness_version', 80)->nullable();
            $table->unsignedInteger('total_attempts')->default(0);
            $table->unsignedInteger('failed_control_count')->default(0);
            $table->unsignedInteger('blocked_control_count')->default(0);
            $table->unsignedInteger('skipped_required_control_count')->default(0);
            $table->unsignedInteger('failed_test_count')->default(0);
            $table->unsignedInteger('open_review_finding_count')->default(0);
            $table->unsignedInteger('blocking_review_finding_count')->default(0);
            $table->unsignedInteger('changed_files_count')->default(0);
            $table->unsignedInteger('risk_flag_count')->default(0);
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->bigInteger('cost_microusd')->nullable();
            $table->unsignedInteger('telemetry_coverage_count')->default(0);
            $table->json('quality_metrics_json')->default('{}');
            $table->string('release_gate_status', 32)->nullable();
            $table->string('release_gate_profile', 64)->nullable();
            $table->json('release_gate_policy_json')->default('{}');
            $table->json('release_gate_failures_json')->default('[]');
            $table->json('release_gate_warnings_json')->default('[]');
            $table->string('rollout_status', 40)->nullable();
            $table->json('rollout_policy_json')->default('{}');
            $table->timestamp('rollout_decision_at')->nullable();
            $table->string('outcome_status', 40)->nullable();
            $table->unsignedSmallInteger('outcome_score')->nullable();
            $table->json('outcome_json')->default('{}');
            $table->timestamp('outcome_recorded_at')->nullable();
            $table->string('outcome_recorded_by', 120)->nullable();
            $table->json('runner_options_json')->default('{}');
            $table->json('summary_json')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_benchmark_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('benchmark_run_id');
            $table->uuid('suite_id');
            $table->uuid('case_id');
            $table->uuid('engineering_run_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->string('status', 32)->default('failed');
            $table->string('decision', 32)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->boolean('passed')->default(false);
            $table->integer('duration_ms')->default(0);
            $table->json('expectation_json')->default('{}');
            $table->json('observed_json')->default('{}');
            $table->text('failure_summary')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['benchmark_run_id', 'case_id']);
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'atlas_tool_findings',
            'atlas_tool_artifacts',
            'atlas_tool_runs',
            'atlas_tool_policies',
            'atlas_tool_installations',
            'atlas_tool_definitions',
            'atlas_engineering_benchmark_results',
            'atlas_engineering_benchmark_runs',
            'atlas_engineering_benchmark_cases',
            'atlas_engineering_benchmark_suites',
            'atlas_engineering_review_findings',
            'atlas_engineering_context_packs',
            'atlas_engineering_test_runs',
            'atlas_engineering_test_cases',
            'atlas_engineering_control_results',
            'atlas_engineering_control_revisions',
            'atlas_engineering_controls',
            'atlas_engineering_patch_artifacts',
            'atlas_engineering_run_attempts',
            'atlas_engineering_run_operator_actions',
            'atlas_engineering_harnessability_calibrations',
            'atlas_engineering_runs',
            'atlas_engineering_blueprints',
            'atlas_engineering_evidence',
            'atlas_mobile_devices',
            'ai_inbox_items',
            'ai_jobs',
            'ai_traces',
            'atlas_tasks',
            'atlas_ledger_events',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function canonicalJsonForTest(mixed $value): string
    {
        $encoded = json_encode(
            $this->canonicalSortForTest($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) ? $encoded : '';
    }

    private function canonicalSortForTest(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalSortForTest($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $sub) {
            $value[$key] = $this->canonicalSortForTest($sub);
        }

        return $value;
    }
}
