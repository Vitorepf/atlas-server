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
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($normal, 'agent_behavior_contract.contract_id'));
        $this->assertContains('Surgical Diff Discipline', data_get($normal, 'agent_behavior_contract.principles'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($normal, 'agent_behavior_contract.content_hash'));
        $this->assertSame('atlas.programming.orchestration.v1', data_get($normal, 'programming_orchestration_contract.schema_version'));
        $this->assertSame('atlas.programming.agentic_rag.plan.v1', data_get($normal, 'agentic_rag_plan.schema_version'));
        $this->assertSame('atlas.programming.stage_receipt_plan.v1', data_get($normal, 'stage_receipt_plan.schema_version'));
        $this->assertSame('atlas.programming.resume_state.v1', data_get($normal, 'resume_state.schema_version'));
        $this->assertSame('atlas.programming.test_impact.receipt.v1', data_get($normal, 'test_impact_plan.schema_version'));
        $this->assertSame('atlas.programming.execution_sandbox.plan.v1', data_get($normal, 'sandbox_plan.schema_version'));
        $this->assertSame('atlas.programming.patch_verifier.report.v1', data_get($normal, 'patch_verifier_gate.schema_version'));
        $this->assertSame('atlas.programming.learning_candidate.v1', data_get($normal, 'learning_candidate_policy.schema_version'));
        $this->assertSame('atlas.persistent_context.runtime.v1', data_get($normal, 'persistent_context.schema_version'));
        $this->assertSame('atlas_dev', data_get($normal, 'persistent_context.scope.flow_id'));
        $this->assertIsString(data_get($normal, 'persistent_context.persistent_context_hash'));
        $this->assertSame('atlas.context_intelligence.operations_runtime.v1', data_get($normal, 'context_operations.schema_version'));
        $this->assertSame('atlas.context_intelligence.context_certification.v1', data_get($normal, 'context_intelligence.schema_version'));
        $this->assertSame('atlas.conversation_ops.health_report.v1', data_get($normal, 'conversation_ops.schema_version'));
        $this->assertSame('dev_runtime', data_get($normal, 'context_operations.handoff_packet.role'));
        $this->assertFalse((bool) data_get($normal, 'context_operations.claim_policy.provider_calls_made'));
        $this->assertSame(['plan', 'review', 'patch', 'test', 'repair'], data_get($normal, 'programming_orchestration_contract.stage_order'));
        $this->assertSame('required', data_get($normal, 'programming_orchestration_contract.stages.0.mode'));
        $this->assertSame('required', data_get($normal, 'programming_orchestration_contract.stages.2.mode'));
        $this->assertSame('operator_or_policy', data_get($normal, 'programming_orchestration_contract.stages.3.mode'));
        $this->assertSame('blocked', data_get($normal, 'programming_orchestration_contract.stages.4.mode'));
        $this->assertSame('atlas.programming.stage_receipt.v1', data_get($normal, 'programming_orchestration_contract.receipt_contract.schema_version'));
        $this->assertTrue((bool) data_get($normal, 'programming_orchestration_contract.resume_contract.must_load_open_brain'));

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
        $this->assertSame('conditional', data_get($complete, 'programming_orchestration_contract.stages.4.mode'));

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
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($forge, 'agent_behavior_contract.contract_id'));
        $this->assertSame('atlas.context_intelligence.operations_runtime.v1', data_get($forge, 'context_operations.schema_version'));
        $this->assertTrue((bool) data_get($forge, 'context_operations.integration_policy.verified_compaction_required'));
        $this->assertSame('forge_intake', data_get($forge, 'context_operations.handoff_packet.role'));
        $this->assertSame('atlas_forge', data_get($forge, 'persistent_context.scope.flow_id'));
    }

    public function test_session_plan_has_resume_orchestration_contract_for_broken_task_continuation(): void
    {
        $plan = app(AtlasProgrammingOrchestrator::class)->sessionPlan(sys_get_temp_dir(), 'dev', [
            'task' => 'retomar tarefa quebrada',
            'interactive' => false,
            'parent_plan_id' => 'plan-parent-1',
            'intent' => 'repair',
            'auto_test' => true,
        ]);

        $this->assertSame('plan-parent-1', data_get($plan, 'parent_plan_id'));
        $this->assertTrue((bool) data_get($plan, 'programming_orchestration_contract.resumed'));
        $this->assertSame('plan-parent-1', data_get($plan, 'programming_orchestration_contract.parent_plan_id'));
        $this->assertSame('programming.repair', data_get($plan, 'programming_orchestration_contract.programming_flow'));
        $this->assertSame('required', data_get($plan, 'programming_orchestration_contract.stages.3.mode'));
        $this->assertSame('conditional', data_get($plan, 'programming_orchestration_contract.stages.4.mode'));
        $this->assertTrue((bool) data_get($plan, 'programming_orchestration_contract.resume_contract.must_preserve_prior_decisions'));
        $this->assertTrue((bool) data_get($plan, 'programming_orchestration_contract.resume_contract.must_attach_previous_stage_receipts'));
    }

    public function test_session_plan_selects_specialized_programming_flows(): void
    {
        $workspace = sys_get_temp_dir();
        $orchestrator = app(AtlasProgrammingOrchestrator::class);

        $repair = $orchestrator->sessionPlan($workspace, 'dev', [
            'task' => 'melhore login',
            'intent' => 'repair',
            'interactive' => false,
        ]);
        $this->assertSame('repair', data_get($repair, 'programming_flow'));
        $this->assertSame('programming.repair', data_get($repair, 'policy_profile.profile_id'));
        $this->assertSame('dev_repair_executor', data_get($repair, 'executor_decision.executor'));

        $review = $orchestrator->sessionPlan($workspace, 'dev', [
            'task' => 'faça code review do auth',
            'interactive' => false,
        ]);
        $this->assertSame('review', data_get($review, 'programming_flow'));
        $this->assertSame('programming.review', data_get($review, 'policy_profile.profile_id'));
        $this->assertSame('read_only', data_get($review, 'execution_profile.tool_contract.mode'));
        $this->assertSame('blocked', data_get($review, 'programming_orchestration_contract.stages.2.mode'));
        $this->assertFalse((bool) data_get($review, 'programming_orchestration_contract.stages.2.write_allowed'));

        $database = $orchestrator->sessionPlan($workspace, 'dev', [
            'task' => 'crie migration postgres com rollback',
            'interactive' => false,
        ]);
        $this->assertSame('database', data_get($database, 'programming_flow'));
        $this->assertSame('programming.database', data_get($database, 'policy_profile.profile_id'));
        $this->assertSame('engineering_harness', data_get($database, 'executor_decision.executor'));

        $frontend = $orchestrator->sessionPlan($workspace, 'dev', [
            'task' => 'implemente layout frontend mobile com design system',
            'interactive' => false,
        ]);
        $this->assertSame('frontend', data_get($frontend, 'programming_flow'));
        $this->assertSame('programming.frontend', data_get($frontend, 'policy_profile.profile_id'));
        $this->assertSame('atlas.programming.frontend_design_harness.v1', data_get($frontend, 'frontend_design_harness_contract.schema_version'));
        $this->assertSame('programming.frontend', data_get($frontend, 'frontend_design_harness_contract.specialist_profile'));
        $this->assertTrue((bool) data_get($frontend, 'frontend_design_harness_contract.provider_policy.provider_neutral'));
        $this->assertContains('visual_smoke_multi_viewport', data_get($frontend, 'frontend_design_harness_contract.required_gates', []));
        $this->assertContains('asset_provenance_check', data_get($frontend, 'frontend_design_harness_contract.required_gates', []));
        $this->assertContains('design_5d_review', data_get($frontend, 'frontend_design_harness_contract.required_gates', []));
        $this->assertSame('programming.visual_smoke', data_get($frontend, 'frontend_design_harness_contract.evidence_contract.visual_smoke_tool'));
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

    public function test_dispatch_contract_carries_canonical_programming_orchestration_contract(): void
    {
        $plan = app(AtlasProgrammingOrchestrator::class)->sessionPlan(sys_get_temp_dir(), 'dev', [
            'task' => 'corrigir teste quebrado',
            'intent' => 'repair',
            'interactive' => false,
            'auto_test' => true,
        ]);
        $dispatch = app(AtlasProgrammingOrchestrator::class)->dispatchContract($plan);

        $this->assertSame(data_get($plan, 'programming_orchestration_contract.plan_id'), data_get($dispatch, 'programming_orchestration_contract.plan_id'));
        $this->assertSame('atlas.programming.orchestration.v1', data_get($dispatch, 'programming_orchestration_contract.schema_version'));
        $this->assertSame('atlas.persistent_context.runtime.v1', data_get($dispatch, 'persistent_context.schema_version'));
        $this->assertSame('all_cli_app_chat_programming_surfaces_must_follow_this_contract', data_get($dispatch, 'programming_orchestration_contract.surface_rule'));
        $this->assertSame('atlas.programming.stage_receipt.v1', data_get($dispatch, 'programming_orchestration_contract.receipt_contract.schema_version'));
        $this->assertSame('atlas.programming.agentic_rag.plan.v1', data_get($dispatch, 'agentic_rag_plan.schema_version'));
        $this->assertSame('atlas.programming.stage_receipt_plan.v1', data_get($dispatch, 'stage_receipt_plan.schema_version'));
        $this->assertSame('atlas.programming.resume_state.v1', data_get($dispatch, 'resume_state.schema_version'));
        $this->assertSame('atlas.context_intelligence.operations_runtime.v1', data_get($dispatch, 'context_operations.schema_version'));
        $this->assertSame('atlas.programming.execution_sandbox.plan.v1', data_get($dispatch, 'sandbox_plan.schema_version'));

        $frontendPlan = app(AtlasProgrammingOrchestrator::class)->sessionPlan(sys_get_temp_dir(), 'dev', [
            'task' => 'ajustar frontend react com screenshot',
        ]);
        $frontendDispatch = app(AtlasProgrammingOrchestrator::class)->dispatchContract($frontendPlan);
        $this->assertSame(
            data_get($frontendPlan, 'frontend_design_harness_contract.plan_id'),
            data_get($frontendDispatch, 'frontend_design_harness_contract.plan_id'),
        );
        $this->assertTrue((bool) data_get($frontendDispatch, 'frontend_design_harness_contract.completion_rules.screenshot_alone_is_insufficient'));
    }

    public function test_harness_completion_contract_projects_evidence_and_score(): void
    {
        $completion = app(AtlasProgrammingOrchestrator::class)->harnessCompletionContract([
            'status' => 'passed',
            'executor' => 'engineering_harness',
            'task_id' => 'task-1',
            'evidence_refs' => ['run:1', 'patch:1'],
            'blocking_failures' => [],
            'kernel_repair_decision' => [
                'status' => 'repair_allowed',
                'strategy' => 'rerun_harness',
            ],
            'repair_contract' => [
                'orchestrator' => 'AtlasRepairOrchestrator',
                'decision_required_before_enqueue' => true,
            ],
            'harness_payload' => [
                'run' => [
                    'id' => 'run-1',
                    'score' => 100,
                ],
            ],
        ], [
            'dispatch_path' => 'programming_orchestrator_harness',
            'executor' => 'engineering_harness',
            'persistent_context' => [
                'schema_version' => 'atlas.persistent_context.runtime.v1',
                'scope' => [
                    'scope_type' => 'programming_plan',
                    'scope_id' => 'plan-1',
                    'workspace' => sys_get_temp_dir(),
                ],
                'evidence_refs' => ['plan:1'],
            ],
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
        $this->assertSame('repair_allowed', data_get($completion, 'kernel_repair_decision.status'));
        $this->assertSame('AtlasRepairOrchestrator', data_get($completion, 'repair_contract.orchestrator'));
        $this->assertSame('harness', data_get($completion, 'policy_contracts.tools.mode'));
        $this->assertSame('strict', data_get($completion, 'policy_contracts.gates.minimum_gate'));
        $this->assertSame('atlas.programming.test_impact.receipt.v1', data_get($completion, 'test_impact_receipt.schema_version'));
        $this->assertSame('atlas.programming.patch_verifier.report.v1', data_get($completion, 'patch_verifier_report.schema_version'));
        $this->assertSame('atlas.programming.learning_candidate.v1', data_get($completion, 'learning_candidate.schema_version'));
        $this->assertSame('atlas.persistent_context.post_execution_update.v1', data_get($completion, 'persistent_context_update.schema_version'));
        $this->assertSame('recorded', data_get($completion, 'persistent_context_update.status'));
        $this->assertFalse((bool) data_get($completion, 'persistent_context_update.promotion_allowed'));

        $dispatch['programming_orchestration_contract'] = [
            'schema_version' => 'atlas.programming.orchestration.v1',
            'plan_id' => 'plan-1',
        ];
        $completionWithOrchestration = app(AtlasProgrammingOrchestrator::class)->harnessCompletionContract([
            'status' => 'partial',
        ], $dispatch);
        $this->assertSame('plan-1', data_get($completionWithOrchestration, 'programming_orchestration_contract.plan_id'));

        $dispatch['frontend_design_harness_contract'] = [
            'schema_version' => 'atlas.programming.frontend_design_harness.v1',
            'plan_id' => 'plan-1',
            'specialist_profile' => 'programming.frontend',
        ];
        $completionWithFrontend = app(AtlasProgrammingOrchestrator::class)->harnessCompletionContract([
            'status' => 'partial',
        ], $dispatch);
        $this->assertSame('programming.frontend', data_get($completionWithFrontend, 'frontend_design_harness_contract.specialist_profile'));
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
        $this->assertSame('AtlasRepairOrchestrator', data_get($contract, 'kernel_repair_contract.orchestrator'));
        $this->assertSame('RepairRequestFactory', data_get($contract, 'kernel_repair_contract.request_factory'));
        $this->assertTrue((bool) data_get($contract, 'kernel_repair_contract.decision_required_before_enqueue'));
        $this->assertTrue((bool) data_get($contract, 'kernel_repair_contract.blocks_when_kernel_blocks'));
        $this->assertSame('atlas.programming.repair_capsule.v1', data_get($contract, 'repair_capsule_contract.schema_version'));
        $this->assertTrue((bool) data_get($contract, 'repair_capsule_contract.required_before_patch_repair'));
        $this->assertFalse((bool) data_get($contract, 'repair_capsule_contract.fallback_allowed'));
        $this->assertContains('collect_evidence', data_get($contract, 'allowed_strategies'));
        $this->assertContains('rerun_harness', data_get($contract, 'heavy_strategies'));
        $this->assertTrue((bool) data_get($contract, 'requires_evidence_for_heavy_repair'));
        $this->assertSame('workspace_write', data_get($contract, 'tool_contract.mode'));
        $this->assertSame('strict', data_get($contract, 'gate_contract.minimum_gate'));
    }

    public function test_repair_prompt_contains_quality_gate_summary(): void
    {
        $repair = app(AtlasProgrammingOrchestrator::class)->repair([
            'failure_hash' => 'failure-a',
            'primary_error' => 'PHPUnit assertion failed',
            'command' => 'php artisan test',
        ], [
            'task' => 'implementar recurso',
            'attempt' => 2,
            'max_attempts' => 4,
            'agentic_rag_plan' => [
                'retrieval_receipt' => ['receipt_id' => 'rag-1'],
            ],
        ]);

        $this->assertSame('planned', data_get($repair, 'status'));
        $this->assertSame('atlas.programming.repair_attempt.plan.v1', data_get($repair, 'repair_plan.schema_version'));
        $this->assertSame('atlas.programming.repair_capsule.v1', data_get($repair, 'repair_capsule.schema_version'));
        $this->assertSame('test_failure', data_get($repair, 'repair_capsule.failure_taxonomy.category'));
        $this->assertFalse((bool) data_get($repair, 'repair_capsule.provider_policy.fallback_allowed'));
        $this->assertStringContainsString('repair_capsule', $repair['repair_prompt']);

        $prompt = app(AtlasProgrammingOrchestrator::class)->repairPrompt('implementar recurso', [
            'status' => 'failed',
            'changed_files' => ['app/Foo.php'],
            'repair_capsule' => [
                'schema_version' => 'atlas.programming.repair_capsule.v1',
                'failure_taxonomy' => ['category' => 'test_failure'],
            ],
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
        $this->assertStringContainsString('repair_capsule', $prompt);
    }
}
