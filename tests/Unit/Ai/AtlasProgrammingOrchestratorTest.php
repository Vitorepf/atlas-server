<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use Tests\TestCase;

class AtlasProgrammingOrchestratorTest extends TestCase
{
    public function test_session_plan_uses_policy_executor_for_normal_complete_and_forge_profiles(): void
    {
        $workspace = sys_get_temp_dir();
        $orchestrator = app(AtlasProgrammingOrchestrator::class);

        $normal = $orchestrator->sessionPlan($workspace, 'dev', [
            'task' => 'implemente um ajuste pequeno',
            'interactive' => false,
        ]);

        $this->assertSame('simple_provider_execution', data_get($normal, 'executor_decision.executor'));
        $this->assertSame('programming_risk_managed_by_policy', data_get($normal, 'executor_decision.reason'));
        $this->assertSame('simple_provider_execution', data_get($normal, 'executor_decision.policy_executor_preference'));
        $this->assertSame('programming.dev', data_get($normal, 'executor_decision.policy_profile_id'));
        $this->assertSame(1, data_get($normal, 'execution_profile.max_iterations'));
        $this->assertSame('single_executor', data_get($normal, 'policy_contracts.model_graph.graph'));
        $this->assertSame('workspace_write', data_get($normal, 'execution_profile.tool_contract.mode'));
        $this->assertSame('standard', data_get($normal, 'execution_profile.gate_contract.minimum_gate'));

        $complete = $orchestrator->sessionPlan($workspace, 'dev', [
            'task' => 'implemente fluxo completo',
            'interactive' => false,
            'complete' => true,
            'max_iterations' => 4,
        ]);

        $this->assertSame('dev_repair_executor', data_get($complete, 'executor_decision.executor'));
        $this->assertSame('policy_complete_mode_requires_repair_loop', data_get($complete, 'executor_decision.reason'));
        $this->assertSame('dev_repair_executor', data_get($complete, 'executor_decision.policy_executor_preference'));
        $this->assertSame(4, data_get($complete, 'execution_profile.max_iterations'));
        $this->assertTrue((bool) data_get($complete, 'execution_profile.quality_required'));

        $forge = $orchestrator->sessionPlan($workspace, 'forge', [
            'task' => 'implemente refatoracao grande',
            'interactive' => false,
        ]);

        $this->assertSame('engineering_harness', data_get($forge, 'executor_decision.executor'));
        $this->assertSame('forge_policy_requires_harness', data_get($forge, 'executor_decision.reason'));
        $this->assertSame('engineering_harness', data_get($forge, 'executor_decision.policy_executor_preference'));
        $this->assertSame('required', data_get($forge, 'execution_profile.engineering_harness'));
        $this->assertSame(5, data_get($forge, 'execution_profile.max_iterations'));
        $this->assertSame('scout_execute_review', data_get($forge, 'policy_contracts.model_graph.graph'));
        $this->assertSame('harness', data_get($forge, 'execution_profile.tool_contract.mode'));
        $this->assertSame('strict', data_get($forge, 'execution_profile.gate_contract.minimum_gate'));
    }

    public function test_dispatch_contract_records_selected_execution_path(): void
    {
        $dispatch = app(AtlasProgrammingOrchestrator::class)->dispatchContract([
            'plan_id' => 'plan-harness',
            'programming_profile' => 'forge',
            'executor_decision' => [
                'executor' => 'engineering_harness',
                'reason' => 'forge_profile_prefers_harness',
            ],
            'policy_profile' => [
                'profile_id' => 'programming.forge',
                'profile_context' => [
                    'programming' => true,
                    'forge' => true,
                ],
                'execution_policy' => [
                    'executor_preference' => 'engineering_harness',
                    'max_iterations' => 5,
                ],
                'policy_contracts' => [
                    'tools' => ['mode' => 'harness'],
                    'gates' => ['minimum_gate' => 'strict'],
                ],
            ],
            'operational_decision' => [
                'decision_id' => 'decision-1',
            ],
        ]);

        $this->assertSame(1, data_get($dispatch, 'schema_version'));
        $this->assertSame('selected', data_get($dispatch, 'status'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($dispatch, 'source'));
        $this->assertSame('programming_orchestrator_harness', data_get($dispatch, 'dispatch_path'));
        $this->assertSame('engineering_harness', data_get($dispatch, 'executor'));
        $this->assertSame('forge', data_get($dispatch, 'programming_profile'));
        $this->assertSame('programming.forge', data_get($dispatch, 'policy_profile_id'));
        $this->assertSame('engineering_harness', data_get($dispatch, 'execution_policy.executor_preference'));
        $this->assertSame('harness', data_get($dispatch, 'policy_contracts.tools.mode'));
        $this->assertSame('strict', data_get($dispatch, 'policy_contracts.gates.minimum_gate'));
        $this->assertTrue((bool) data_get($dispatch, 'profile_context.forge'));
        $this->assertSame('decision-1', data_get($dispatch, 'operational_decision_id'));
        $this->assertSame('plan-harness', data_get($dispatch, 'plan_id'));

        $providerDispatch = app(AtlasProgrammingOrchestrator::class)->dispatchContract([
            'plan_id' => 'plan-provider',
            'programming_profile' => 'dev',
            'executor_decision' => [
                'executor' => 'dev_repair_executor',
                'reason' => 'complete_mode_requires_repair_loop',
            ],
        ]);

        $this->assertSame('ai_gateway_provider', data_get($providerDispatch, 'dispatch_path'));
        $this->assertSame('dev_repair_executor', data_get($providerDispatch, 'executor'));
        $this->assertSame('complete_mode_requires_repair_loop', data_get($providerDispatch, 'reason'));
    }

    public function test_harness_completion_contract_projects_evidence_and_score(): void
    {
        $completion = app(AtlasProgrammingOrchestrator::class)->harnessCompletionContract([
            'status' => 'passed',
            'executor' => 'engineering_harness',
            'task_id' => 'task-1',
            'evidence_refs' => ['run:1', 'patch:1'],
            'blocking_failures' => [],
            'harness_payload' => [
                'run' => [
                    'id' => 'run-1',
                    'score' => 100,
                ],
            ],
        ], [
            'dispatch_path' => 'programming_orchestrator_harness',
            'executor' => 'engineering_harness',
            'policy_contracts' => [
                'tools' => ['mode' => 'harness'],
                'gates' => ['minimum_gate' => 'strict'],
            ],
        ], 'gpt-test');

        $this->assertSame('passed', data_get($completion, 'status'));
        $this->assertSame('engineering_harness', data_get($completion, 'executor'));
        $this->assertSame('programming_orchestrator_harness', data_get($completion, 'dispatch_path'));
        $this->assertSame('engineering_harness', data_get($completion, 'provider'));
        $this->assertSame('gpt-test', data_get($completion, 'model'));
        $this->assertSame('task-1', data_get($completion, 'task_id'));
        $this->assertSame('run-1', data_get($completion, 'engineering_run_id'));
        $this->assertSame(100, data_get($completion, 'score'));
        $this->assertSame(['run:1', 'patch:1'], data_get($completion, 'evidence_refs'));
        $this->assertSame('harness', data_get($completion, 'policy_contracts.tools.mode'));
        $this->assertSame('strict', data_get($completion, 'policy_contracts.gates.minimum_gate'));
    }

    public function test_repair_execution_contract_makes_dev_repair_executor_actionable(): void
    {
        $contract = app(AtlasProgrammingOrchestrator::class)->repairExecutionContract([
            'executor_decision' => [
                'executor' => 'dev_repair_executor',
            ],
            'execution_profile' => [
                'complete' => true,
                'max_iterations' => 4,
            ],
            'policy_profile' => [
                'execution_policy' => [
                    'max_iterations' => 5,
                    'auto_test' => true,
                    'quality_required' => true,
                ],
            ],
            'policy_contracts' => [
                'tools' => ['mode' => 'workspace_write'],
                'gates' => ['minimum_gate' => 'strict'],
            ],
        ]);

        $this->assertSame('active', data_get($contract, 'status'));
        $this->assertTrue(data_get($contract, 'enabled'));
        $this->assertTrue(data_get($contract, 'complete_mode'));
        $this->assertSame(5, data_get($contract, 'max_iterations'));
        $this->assertTrue((bool) data_get($contract, 'auto_test'));
        $this->assertTrue((bool) data_get($contract, 'quality_required'));
        $this->assertSame('passed', data_get($contract, 'required_final_status'));
        $this->assertSame(['failed', 'needs_review'], data_get($contract, 'repair_when_status'));
        $this->assertSame(['passed'], data_get($contract, 'stop_when_status'));
        $this->assertSame('workspace_write', data_get($contract, 'tool_contract.mode'));
        $this->assertSame('strict', data_get($contract, 'gate_contract.minimum_gate'));
    }

    public function test_repair_prompt_contains_quality_gate_summary(): void
    {
        $prompt = app(AtlasProgrammingOrchestrator::class)->repairPrompt('implementar recurso', [
            'status' => 'failed',
            'changed_files' => ['app/Foo.php'],
            'completion_packet' => [
                'tests' => [
                    ['command' => 'phpunit', 'ok' => false],
                ],
                'risks' => ['teste falhando'],
            ],
        ], 2, 4);

        $this->assertStringContainsString('Iteracao de reparo 2/4', $prompt);
        $this->assertStringContainsString('implementar recurso', $prompt);
        $this->assertStringContainsString('app/Foo.php', $prompt);
        $this->assertStringContainsString('phpunit', $prompt);
    }
}
