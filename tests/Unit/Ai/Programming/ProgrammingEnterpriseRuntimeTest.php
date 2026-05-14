<?php

namespace Tests\Unit\Ai\Programming;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasToolRun;
use App\Services\Ai\Programming\ProgrammingContextPackStore;
use App\Services\Ai\Programming\ProgrammingLearningCandidateProjector;
use App\Services\Ai\Programming\ProgrammingLearningCandidateStore;
use App\Services\Ai\Programming\ProgrammingLearningPromotionGate;
use App\Services\Ai\Programming\ProgrammingPatchVerifier;
use App\Services\Ai\Programming\ProgrammingPatchVerifierBenchmarkService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeContract;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeExecutor;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeGraphProjector;
use App\Services\Ai\Programming\ProgrammingRepairAttemptStore;
use App\Services\Ai\Programming\ProgrammingRepairExecutor;
use App\Services\Ai\Programming\ProgrammingRepairLoopBenchmarkService;
use App\Services\Ai\Programming\ProgrammingResumeService;
use App\Services\Ai\Programming\ProgrammingRetrievalBenchmarkService;
use App\Services\Ai\Programming\ProgrammingRetrievalPlanner;
use App\Services\Ai\Programming\ProgrammingRivalsReadinessService;
use App\Services\Ai\Programming\ProgrammingSandboxManager;
use App\Services\Ai\Programming\ProgrammingSemanticCodeGraphService;
use App\Services\Ai\Programming\ProgrammingStageReceiptStore;
use App\Services\Ai\Programming\ProgrammingStageReceiptValidator;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use App\Services\Ai\Programming\ProgrammingTestImpactBenchmarkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\TestCase;

class ProgrammingEnterpriseRuntimeTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;

    public function test_agentic_rag_plan_includes_required_sources_graph_and_sufficiency_gate(): void
    {
        $previousReceipt = app(ProgrammingStageReceiptStore::class)->make(
            planId: 'parent-plan-1',
            parentPlanId: null,
            stage: 'plan',
            attempt: 1,
            status: 'passed',
            input: ['objective' => 'repair'],
            output: ['decision' => 'continue'],
        );

        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-1',
            workspace: base_path(),
            objective: 'corrigir AtlasProgrammingOrchestrator repair tests',
            flow: 'programming.repair',
            options: [
                'quality_required' => true,
                'previous_stage_receipts' => [$previousReceipt],
            ],
        );

        $this->assertSame('atlas.programming.agentic_rag.plan.v1', $plan['schema_version']);
        $this->assertSame('programming.repair', $plan['flow']);
        $this->assertContains('code_symbols', $plan['required_sources']);
        $this->assertContains('related_tests', $plan['required_sources']);
        $this->assertSame('atlas.programming.context_sufficiency_gate.v1', data_get($plan, 'context_sufficiency_gate.schema_version'));
        $this->assertSame('atlas.programming.retrieval_receipt.v1', data_get($plan, 'retrieval_receipt.schema_version'));
        $this->assertSame('atlas.programming.context_pack.v1', data_get($plan, 'context_pack.schema_version'));
        $this->assertNotEmpty(data_get($plan, 'context_pack.ranked_refs'));
        $this->assertSame(true, data_get($plan, 'context_pack.provider_safe'));
        $this->assertSame('atlas.programming.agentic_rag.professional_plan.v1', data_get($plan, 'professional_plan.schema_version'));
        $this->assertSame('atlas.programming.context_pack.professional.v1', data_get($plan, 'professional_context_pack.schema_version'));
        $this->assertSame('promoted_programming_graph_rag_semantic', data_get($plan, 'professional_plan.retrieval_strategy'));
        $this->assertSame('atlas.programming.graph_rag_runtime.v1', data_get($plan, 'graph_rag_runtime.schema_version'));
        $this->assertSame('promoted', data_get($plan, 'graph_rag_runtime.status'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.promoted_runtime'));
        $this->assertSame('programming_only', data_get($plan, 'graph_rag_runtime.runtime_scope'));
        $this->assertGreaterThan(0, data_get($plan, 'graph_rag_runtime.evidence_ref_count'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.execution_policy.local_only'));
        $this->assertFalse(data_get($plan, 'graph_rag_runtime.execution_policy.provider_calls_allowed'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.ap_683_boundary.global_python_graph_rag_policy_unchanged'));
        $this->assertTrue(data_get($plan, 'graph_rag_runtime.ap_683_boundary.programming_runtime_is_bounded_by_context_pack_receipts'));
        $this->assertSame('atlas.programming.graph_rag_runtime.v1', data_get($plan, 'professional_plan.graph_rag_runtime.schema_version'));
        $this->assertNotEmpty(data_get($plan, 'professional_context_pack.ranked_refs'));
        $this->assertSame('atlas.programming.agentic_rag.gap_critic.v1', data_get($plan, 'gap_critic.schema_version'));
        $this->assertSame('atlas.programming.retrieval_eval.v1', data_get($plan, 'retrieval_eval.schema_version'));
        $this->assertContains(data_get($plan, 'gap_critic.status'), ['passed', 'blocked']);
        if (data_get($plan, 'gap_critic.status') === 'blocked') {
            $this->assertNotEmpty(data_get($plan, 'gap_critic.missing_sources'));
            $this->assertSame('blocked', data_get($plan, 'context_sufficiency_gate.status'));
        } else {
            $this->assertSame([], data_get($plan, 'gap_critic.missing_sources'));
            $this->assertContains(data_get($plan, 'context_sufficiency_gate.status'), ['passed', 'blocked']);
        }
        $this->assertSame('atlas.programming.python_runtime.invocation_contract.v1', data_get($plan, 'python_runtime_contract.schema_version'));
        $this->assertFalse(data_get($plan, 'python_runtime_contract.execution.auto_execute_from_planner'));
        $this->assertFalse(data_get($plan, 'python_runtime_contract.manifest.runtime_policy.provider_calls_allowed'));
        $this->assertFalse(data_get($plan, 'python_runtime_contract.manifest.runtime_policy.network_calls_allowed'));
        $this->assertNotNull(data_get($plan, 'retrieval_receipt.context_pack_hash'));
        $this->assertIsInt(data_get($plan, 'semantic_code_graph.node_count'));
    }

    public function test_professional_context_pack_persists_replays_and_exposes_quality_metrics(): void
    {
        $this->createProgrammingContextPackTable();

        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-professional-rag-1',
            workspace: base_path(),
            objective: 'fortalecer agentic rag professional context pack executor',
            flow: 'programming.review',
            options: [
                'quality_required' => false,
                'max_refs' => 18,
                'max_chars' => 12000,
            ],
        );

        $contextPackHash = (string) data_get($plan, 'professional_context_pack.context_pack_hash');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contextPackHash);
        $this->assertTrue(data_get($plan, 'professional_context_pack.storage.persisted'));
        $this->assertDatabaseHas('atlas_programming_context_packs', [
            'plan_id' => 'plan-professional-rag-1',
            'context_pack_hash' => $contextPackHash,
            'schema_version' => 'atlas.programming.context_pack.professional.v1',
            'retrieval_strategy' => 'promoted_programming_graph_rag_semantic',
        ]);
        $this->assertNotEmpty(data_get($plan, 'professional_context_pack.metrics.required_source_coverage'));
        $this->assertIsFloat(data_get($plan, 'retrieval_eval.recall_at_k_proxy'));
        $this->assertFalse(data_get($plan, 'retrieval_eval.professional_promotion_allowed'));

        $replayed = app(ProgrammingContextPackStore::class)->findByHash($contextPackHash);

        $this->assertSame($contextPackHash, data_get($replayed, 'context_pack_hash'));
        $this->assertTrue(data_get($replayed, 'storage.replayed'));
    }

    public function test_professional_gap_critic_blocks_strict_execution_when_required_source_is_absent(): void
    {
        $plan = app(ProgrammingRetrievalPlanner::class)->plan(
            planId: 'plan-professional-rag-blocked',
            workspace: base_path(),
            objective: 'corrigir repair sem receipts anteriores',
            flow: 'programming.repair',
            options: [
                'quality_required' => true,
                'previous_stage_receipts' => [],
                'max_refs' => 10,
            ],
        );

        $this->assertSame('blocked', data_get($plan, 'gap_critic.status'));
        $this->assertTrue(data_get($plan, 'gap_critic.blocks_execution'));
        $this->assertContains('stage_receipts', data_get($plan, 'gap_critic.missing_sources'));
        $this->assertSame('blocked', data_get($plan, 'context_sufficiency_gate.status'));
        $this->assertContains('stage_receipts_unavailable_in_professional_context_pack', data_get($plan, 'context_sufficiency_gate.reasons'));
    }

    public function test_programming_retrieval_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingRetrievalBenchmarkService::class)->run(base_path());

        $this->assertSame('atlas.programming.retrieval_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_retrieval_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertGreaterThanOrEqual(0.66, data_get($report, 'metrics.recall_at_k'));
        $this->assertTrue(data_get($report, 'promotion_gate.professional_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.graph_rag_runtime_promoted'));
        $this->assertSame('programming_only', data_get($report, 'promotion_gate.graph_rag_runtime_scope'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
        $this->assertSame('promoted', data_get($report, 'cases.0.graph_rag_runtime.status'));
        $this->assertSame('programming_only', data_get($report, 'cases.0.graph_rag_runtime.runtime_scope'));
        $this->assertSame('atlas.programming.retrieval_benchmark_runtime_cache.v1', data_get($report, 'runtime_cache.schema_version'));
        $this->assertFalse(data_get($report, 'runtime_cache.persistent_cache'));
        $this->assertFalse(data_get($report, 'runtime_cache.provider_state_cached'));

        $cachedReport = app(ProgrammingRetrievalBenchmarkService::class)->run(base_path());

        $this->assertTrue(data_get($cachedReport, 'runtime_cache.hit'));
    }

    public function test_programming_retrieval_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:retrieval-benchmark', [
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.retrieval_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(3, data_get($payload, 'golden_set.case_count'));
        $this->assertTrue(data_get($payload, 'promotion_gate.graph_rag_runtime_promoted'));
        $this->assertArrayHasKey('runtime_cache', $payload);

        $refreshExitCode = Artisan::call('atlas:programming:retrieval-benchmark', [
            '--workspace' => base_path(),
            '--refresh' => true,
            '--json' => true,
        ]);
        $refreshPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $refreshExitCode);
        $this->assertTrue(data_get($refreshPayload, 'runtime_cache.refresh_requested'));
        $this->assertFalse(data_get($refreshPayload, 'runtime_cache.hit'));
    }

    public function test_programming_test_impact_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingTestImpactBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.test_impact_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_test_impact_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertSame(1.0, data_get($report, 'metrics.recall'));
        $this->assertTrue(data_get($report, 'promotion_gate.test_selection_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
    }

    public function test_programming_test_impact_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:test-impact-benchmark', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.test_impact_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(3, data_get($payload, 'golden_set.case_count'));
    }

    public function test_programming_patch_verifier_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingPatchVerifierBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.patch_verifier_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_patch_verifier_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertSame(1.0, data_get($report, 'metrics.grounded_patch_rate'));
        $this->assertTrue(data_get($report, 'promotion_gate.grounded_patch_promotion_allowed'));
    }

    public function test_programming_patch_verifier_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:patch-verifier-benchmark', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.patch_verifier_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(3, data_get($payload, 'golden_set.case_count'));
    }

    public function test_programming_repair_loop_benchmark_runs_golden_set_and_exposes_promotion_gate(): void
    {
        $report = app(ProgrammingRepairLoopBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.repair_loop_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('programming_repair_loop_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(4, data_get($report, 'golden_set.case_count'));
        $this->assertSame(1.0, data_get($report, 'metrics.repair_planning_pass_rate'));
        $this->assertSame(1.0, data_get($report, 'metrics.guard_case_pass_rate'));
        $this->assertTrue(data_get($report, 'metrics.receipt_integrity_passed'));
        $this->assertTrue(data_get($report, 'promotion_gate.repair_loop_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
    }

    public function test_programming_repair_loop_benchmark_command_returns_json_report(): void
    {
        $exitCode = Artisan::call('atlas:programming:repair-loop-benchmark', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.repair_loop_benchmark.v1', data_get($payload, 'schema_version'));
        $this->assertSame('passed', data_get($payload, 'status'));
        $this->assertSame(4, data_get($payload, 'golden_set.case_count'));
    }

    public function test_programming_rivals_readiness_separates_local_benchmarks_from_real_provider_battery(): void
    {
        $report = app(ProgrammingRivalsReadinessService::class)->report(base_path());

        $this->assertSame('atlas.programming.rivals_readiness.v1', $report['schema_version']);
        $this->assertContains($report['status'], ['external_battery_required', 'external_battery_invalid']);
        $this->assertSame('atlas.programming.local_benchmark_runtime_cache.v1', data_get($report, 'local_benchmark_cache.schema_version'));
        $this->assertFalse(data_get($report, 'local_benchmark_cache.persistent_cache'));
        $this->assertFalse(data_get($report, 'local_benchmark_cache.provider_state_cached'));
        $this->assertTrue(data_get($report, 'summary.local_programming_foundation_ready'));
        $this->assertIsBool(data_get($report, 'summary.external_provider_battery_executed'));
        $this->assertIsBool(data_get($report, 'summary.real_provider_battery_attempted'));
        $this->assertIsBool(data_get($report, 'summary.valid_comparable_cases_generated'));
        $this->assertIsInt(data_get($report, 'summary.invalid_case_count'));
        $this->assertIsBool(data_get($report, 'summary.real_battery_invalid'));
        $this->assertFalse(data_get($report, 'summary.synthetic_scores_allowed'));
        $this->assertSame('atlas.programming.retrieval_benchmark_runtime_cache.v1', data_get($report, 'local_benchmarks.retrieval.runtime_cache.schema_version'));
        $this->assertFalse(data_get($report, 'local_benchmarks.retrieval.runtime_cache.persistent_cache'));
        $this->assertFalse(data_get($report, 'local_benchmarks.retrieval.runtime_cache.provider_state_cached'));
        $this->assertSame('passed', data_get($report, 'local_benchmarks.repair_loop.status'));
        $this->assertSame(1.0, data_get($report, 'local_benchmarks.repair_loop.metrics.repair_planning_pass_rate'));
        $this->assertContains(data_get($report, 'fair_claude_rivals.status'), ['suite_not_prepared', 'unavailable', 'not_ready']);
        $this->assertSame('atlas.programming.rivals_contract.v1', data_get($report, 'rivals_programming_contract.schema_version'));
        $this->assertSame('atlas.programming.rivals_integrity_assurance.v1', data_get($report, 'integrity_assurance.schema_version'));
        $this->assertSame('claim_blocked', data_get($report, 'integrity_assurance.status'));
        $this->assertSame('atlas.programming.rivals_result_integrity_diagnostics.v1', data_get($report, 'result_integrity_diagnostics.schema_version'));
        $this->assertContains(data_get($report, 'result_integrity_diagnostics.status'), ['no_real_case_comparisons', 'no_valid_comparable_score']);
        $this->assertFalse(data_get($report, 'result_integrity_diagnostics.score_admission_policy.invalid_cases_count_as_losses'));
        $this->assertFalse(data_get($report, 'result_integrity_diagnostics.score_admission_policy.inconclusive_cases_count_as_losses'));
        $this->assertTrue(data_get($report, 'result_integrity_diagnostics.score_admission_policy.only_comparable_cases_enter_win_loss_math'));
        $this->assertSame('atlas.programming.fair_claude_result_integrity_projection.v1', data_get($report, 'fair_claude_result_integrity.schema_version'));
        $this->assertFalse(data_get($report, 'fair_claude_result_integrity.claim_winner_admitted'));
        $this->assertTrue(data_get($report, 'fair_claude_result_integrity.ui_contract.must_not_render_invalid_cases_as_losses'));
        $this->assertSame('atlas.programming.latest_real_battery_evidence.v1', data_get($report, 'latest_real_battery_evidence.schema_version'));
        $this->assertTrue(data_get($report, 'latest_real_battery_evidence.does_not_prove_atlas_loss'));
        $this->assertTrue(data_get($report, 'latest_real_battery_evidence.does_not_prove_rival_win'));
        $this->assertSame('atlas.programming.invalid_battery_triage_packet.v1', data_get($report, 'invalid_battery_triage_packet.schema_version'));
        $this->assertIsBool(data_get($report, 'invalid_battery_triage_packet.rerun_provider_battery_allowed_now'));
        $this->assertIsBool(data_get($report, 'invalid_battery_triage_packet.provider_budget_policy.spend_more_provider_tokens_now'));
        if (! data_get($report, 'current_workspace_preflight.ready_for_provider_battery')) {
            $this->assertFalse(data_get($report, 'invalid_battery_triage_packet.rerun_provider_battery_allowed_now'));
            $this->assertFalse(data_get($report, 'invalid_battery_triage_packet.provider_budget_policy.spend_more_provider_tokens_now'));
            $this->assertStringContainsString('workspace', data_get($report, 'invalid_battery_triage_packet.provider_budget_policy.reason'));
            $this->assertContains('current_workspace_not_provider_battery_ready', data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.why_provider_dispatch_is_blocked'));
        }
        $this->assertSame('atlas.programming.rivals_historical_failure_policy.v1', data_get($report, 'invalid_battery_triage_packet.historical_failure_policy.schema_version'));
        $this->assertTrue(data_get($report, 'invalid_battery_triage_packet.historical_failure_policy.historical_failed_gates_are_diagnostic'));
        $this->assertTrue(data_get($report, 'invalid_battery_triage_packet.historical_failure_policy.current_preconditions_must_be_green_before_rerun'));
        $this->assertSame('atlas.programming.current_rivals_rerun_preconditions.v1', data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.schema_version'));
        $this->assertTrue(data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.local_programming_benchmarks_passed'));
        $this->assertSame('atlas.programming.current_local_recheck_evidence.v1', data_get($report, 'current_local_recheck_evidence.schema_version'));
        $this->assertFalse(data_get($report, 'current_local_recheck_evidence.provider_dispatches'));
        $this->assertContains(data_get($report, 'current_local_recheck_evidence.status'), ['passed', 'incomplete', 'unknown']);
        $this->assertIsBool(data_get($report, 'current_local_recheck_evidence.all_required_rechecks_known'));
        $this->assertIsBool(data_get($report, 'current_local_recheck_evidence.all_known_rechecks_passed'));
        if (data_get($report, 'current_local_recheck_evidence.all_required_rechecks_known') === false) {
            $this->assertFalse(data_get($report, 'current_local_recheck_evidence.all_known_rechecks_passed'));
        }
        $this->assertSame('atlas.programming.current_local_recheck_evidence.v1', data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.current_local_rechecks.schema_version'));
        $this->assertTrue(data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.current_local_rechecks.historical_rivals_failures_still_authoritative_for_external_claim'));
        $this->assertFalse(data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.provider_dispatch_allowed_now'));
        $this->assertContains('programming_readiness', array_keys(data_get($report, 'invalid_battery_triage_packet.current_rerun_preconditions.diagnostic_commands_without_provider_spend')));
        $this->assertSame('atlas.programming.current_workspace_provider_preflight.v1', data_get($report, 'current_workspace_preflight.schema_version'));
        $this->assertIsBool(data_get($report, 'current_workspace_preflight.ready_for_provider_battery'));
        $this->assertIsInt(data_get($report, 'current_workspace_preflight.dirty_count'));
        $this->assertIsInt(data_get($report, 'current_workspace_preflight.git.dirty_count'));
        $this->assertSame(
            data_get($report, 'current_workspace_preflight.git.dirty_count'),
            data_get($report, 'current_workspace_preflight.dirty_count'),
        );
        $this->assertFalse(data_get($report, 'current_workspace_preflight.operator_guidance.use_current_dirty_workspace_for_provider_battery'));
        $this->assertStringContainsString('git worktree add', data_get($report, 'current_workspace_preflight.operator_guidance.create_clean_atlas_worktree'));
        if (data_get($report, 'summary.real_battery_invalid')) {
            $this->assertFalse(data_get($report, 'invalid_battery_triage_packet.provider_budget_policy.spend_more_provider_tokens_now'));
        }
        $this->assertIsArray(data_get($report, 'invalid_battery_triage_packet.triage_checklist'));
        $this->assertTrue(data_get($report, 'integrity_assurance.ab_test_validity_model.same_case_snapshot_required'));
        $this->assertTrue(data_get($report, 'integrity_assurance.ab_test_validity_model.equivalent_initial_state_required'));
        $this->assertTrue(data_get($report, 'integrity_assurance.ab_test_validity_model.same_acceptance_gates_required'));
        $this->assertTrue(data_get($report, 'integrity_assurance.ab_test_validity_model.no_provider_specific_case_filtering'));
        $this->assertFalse(data_get($report, 'integrity_assurance.ab_test_validity_model.synthetic_scores_allowed'));
        $this->assertTrue(data_get($report, 'integrity_assurance.external_variable_controls.non_evaluated_variables_cannot_decide_winner'));
        $this->assertSame('atlas.programming.rivals_quality_scope_policy.v1', data_get($report, 'integrity_assurance.quality_scope_policy.schema_version'));
        $this->assertTrue(data_get($report, 'integrity_assurance.quality_scope_policy.same_quality_scope_required_for_both_arms'));
        $this->assertTrue(data_get($report, 'integrity_assurance.quality_scope_policy.changed_only_quality_scan_required_for_patch_cases'));
        $this->assertTrue(data_get($report, 'integrity_assurance.quality_scope_policy.repo_wide_debt_outside_case_scope_cannot_decide_winner'));
        $this->assertStringContainsString('--changed-only', data_get($report, 'integrity_assurance.quality_scope_policy.current_valid_local_recheck_commands.quality_changed_only'));
        $this->assertTrue(data_get($report, 'integrity_assurance.score_admission_gate.real_provider_dispatch_required_for_claim'));
        $this->assertFalse(data_get($report, 'integrity_assurance.score_admission_gate.claim_ready'));
        $this->assertContains('claim_blocked_until_protocol_valid_real_comparable_cases', data_get($report, 'integrity_assurance.blocking_reasons'));
        $this->assertSame('atlas.programming.rivals_operator_execution_packet.v1', data_get($report, 'operator_execution_packet.schema_version'));
        $this->assertFalse(data_get($report, 'operator_execution_packet.provider_dispatches_now'));
        $this->assertTrue(data_get($report, 'operator_execution_packet.operator_approval_required'));
        $this->assertIsBool(data_get($report, 'operator_execution_packet.rerun_provider_battery_allowed_now'));
        if (! data_get($report, 'current_workspace_preflight.ready_for_provider_battery')) {
            $this->assertSame('blocked_until_clean_worktree', data_get($report, 'operator_execution_packet.status'));
            $this->assertFalse(data_get($report, 'operator_execution_packet.rerun_provider_battery_allowed_now'));
            $this->assertContains('current_workspace_not_provider_battery_ready', data_get($report, 'operator_execution_packet.blocking_reasons'));
        }
        $this->assertStringContainsString('--confirm-runbook-reviewed', data_get($report, 'operator_execution_packet.recommended_first_run.command'));
        if (data_get($report, 'operator_execution_packet.recommended_first_run.workspace_placeholder_used')) {
            $this->assertStringContainsString('--workspace=<clean-atlas-workspace>', data_get($report, 'operator_execution_packet.recommended_first_run.command'));
        } else {
            $this->assertStringContainsString('--workspace='.base_path(), data_get($report, 'operator_execution_packet.recommended_first_run.command'));
        }
        $this->assertStringContainsString('--claude-code-baseline-workspace=<separate-clean-baseline-workspace>', data_get($report, 'operator_execution_packet.recommended_first_run.command'));
        $this->assertIsBool(data_get($report, 'operator_execution_packet.recommended_first_run.workspace_placeholder_used'));
        $this->assertTrue(data_get($report, 'operator_execution_packet.required_preflight.provider_execution_blocks_on_dirty_workspace_even_after_cost_confirmation'));
        $this->assertFalse(data_get($report, 'safety.readiness_command_dispatches_provider'));
        $this->assertTrue(data_get($report, 'safety.real_provider_execution_requires_explicit_cost_confirmation'));
        $this->assertStringContainsString('--confirm-provider-cost', data_get($report, 'commands.real_rivals_quick'));
        $this->assertStringContainsString('--claude-code-baseline-workspace=<separate-clean-baseline-workspace>', data_get($report, 'commands.real_rivals_quick'));

        $exitCode = Artisan::call('atlas:programming:rivals-readiness', [
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('atlas.programming.rivals_readiness.v1', data_get($payload, 'schema_version'));
        $this->assertContains(data_get($payload, 'status'), ['external_battery_required', 'external_battery_invalid']);

        $humanExitCode = Artisan::call('atlas:programming:rivals-readiness', [
            '--workspace' => base_path(),
        ]);
        $humanOutput = Artisan::output();

        $this->assertSame(0, $humanExitCode);
        $this->assertStringContainsString('Programming Rivals readiness', $humanOutput);
        $this->assertStringContainsString('Integrity status', $humanOutput);
        $this->assertStringContainsString('Score diagnosis', $humanOutput);
        $this->assertStringContainsString('Fair Claude result', $humanOutput);
        $this->assertStringContainsString('Claim winner admitted', $humanOutput);
        $this->assertStringContainsString('Render winner in UI', $humanOutput);
        $this->assertStringContainsString('Real battery attempted', $humanOutput);
        $this->assertStringContainsString('Current workspace preflight', $humanOutput);
        $this->assertStringContainsString('Current rerun preconditions', $humanOutput);
        $this->assertStringContainsString('Rerun precondition dispatch', $humanOutput);
        $this->assertStringContainsString('Current local rechecks', $humanOutput);
        $this->assertStringContainsString('Quality changed-only', $humanOutput);
        $this->assertStringContainsString('Visual smoke', $humanOutput);
        $this->assertStringContainsString('Rerun allowed now', $humanOutput);
        $this->assertStringContainsString('Provider dispatch now', $humanOutput);
        $this->assertStringContainsString('Spend provider tokens now', $humanOutput);
        $this->assertStringContainsString('Provider budget reason', $humanOutput);
        $this->assertStringContainsString('Benchmark retrieval', $humanOutput);
        $this->assertStringContainsString('Provider rerun blocked', $humanOutput);
        $this->assertStringContainsString('Blocked run template', $humanOutput);
        $this->assertStringContainsString('--workspace=<clean-atlas-workspace>', $humanOutput);

        $triageExitCode = Artisan::call('atlas:programming:rivals-readiness', [
            '--workspace' => base_path(),
            '--triage' => true,
            '--json' => true,
        ]);
        $triagePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $triageExitCode);
        $this->assertSame('atlas.programming.rivals_invalid_battery_operator_triage.v1', data_get($triagePayload, 'schema_version'));
        $this->assertFalse(data_get($triagePayload, 'safety.provider_dispatches_now'));
        $this->assertFalse(data_get($triagePayload, 'safety.spend_provider_tokens_now'));
        $this->assertFalse(data_get($triagePayload, 'safety.synthetic_scores_allowed'));
        $this->assertFalse(data_get($triagePayload, 'result_integrity.score_admitted'));
        $this->assertFalse(data_get($triagePayload, 'result_integrity.claim_winner_admitted'));
        $this->assertTrue(data_get($triagePayload, 'result_integrity.must_not_render_winner'));
        $this->assertFalse(data_get($triagePayload, 'provider_budget_policy.spend_more_provider_tokens_now'));
        $this->assertIsString(data_get($triagePayload, 'provider_budget_policy.reason'));
        $this->assertIsArray(data_get($triagePayload, 'triage_checklist'));
        if (data_get($triagePayload, 'status') === 'triaged_quarantined_pending_fresh_battery') {
            $this->assertContains('quarantined_diagnostic', collect(data_get($triagePayload, 'triage_checklist'))->pluck('status')->all());
            $this->assertContains('historical_quarantined_diagnostic', collect(data_get($triagePayload, 'triage_checklist'))->pluck('scope')->all());
            $this->assertNotContains('current_rerun_blocker', collect(data_get($triagePayload, 'triage_checklist'))->pluck('scope')->all());
        }
        $this->assertIsArray(data_get($triagePayload, 'diagnostic_commands'));
        $this->assertStringContainsString('--confirm-provider-cost', (string) data_get($triagePayload, 'blocked_run_template'));

        $triageHumanExitCode = Artisan::call('atlas:programming:rivals-readiness', [
            '--workspace' => base_path(),
            '--triage' => true,
        ]);
        $triageHumanOutput = Artisan::output();

        $this->assertSame(0, $triageHumanExitCode);
        $this->assertStringContainsString('Programming Rivals triage', $triageHumanOutput);
        $this->assertStringContainsString('Provider dispatch', $triageHumanOutput);
        $this->assertStringContainsString('Spend provider tokens', $triageHumanOutput);
        $this->assertStringContainsString('Provider budget reason', $triageHumanOutput);
        $this->assertStringContainsString('Score admitted', $triageHumanOutput);
        $this->assertStringContainsString('Current local rechecks', $triageHumanOutput);
        $this->assertStringContainsString('Quality changed-only', $triageHumanOutput);
        $this->assertStringContainsString('Visual smoke', $triageHumanOutput);
        $this->assertStringContainsString('Next:', $triageHumanOutput);
    }

    public function test_programming_rivals_readiness_uses_tool_runtime_quality_evidence_when_scan_manifest_is_gone(): void
    {
        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tool_definition_id')->nullable();
                $table->string('tool_slug');
                $table->string('surface');
                $table->string('workspace_hash')->nullable();
                $table->text('workspace')->nullable();
                $table->string('run_context_type')->nullable();
                $table->string('run_context_id')->nullable();
                $table->string('status');
                $table->boolean('required')->default(false);
                $table->string('failure_policy')->default('advisory');
                $table->string('policy_decision')->default('allowed');
                $table->string('command_hash')->nullable();
                $table->integer('exit_code')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->uuid('stdout_artifact_id')->nullable();
                $table->uuid('stderr_artifact_id')->nullable();
                $table->json('summary_json')->nullable();
                $table->json('normalized_result_json')->nullable();
                $table->json('policy_decision_json')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        $workspace = storage_path('framework/testing/programming-readiness-'.Str::uuid());
        File::ensureDirectoryExists($workspace.'/atlas-visual-report');
        File::put($workspace.'/atlas-visual-report/manifest.json', json_encode([
            'status' => 'passed',
            'workspace_hash' => hash('sha256', $workspace),
            'artifact_dir' => 'atlas-visual-report',
            'strict_failure_summary' => [
                'route_failed_count' => 0,
                'screenshot_required_failed_count' => 0,
            ],
            'screenshot' => ['status' => 'skipped'],
            'screenshot_driver' => ['status' => 'missing'],
        ], JSON_THROW_ON_ERROR));

        $scanHash = hash('sha256', $workspace.'|quality-scan-tool-runtime-fallback');
        foreach (['composer_validate', 'laravel_pint'] as $tool) {
            AtlasToolRun::query()->create([
                'tool_slug' => $tool,
                'surface' => 'engineering_quality_scan',
                'workspace_hash' => hash('sha256', $workspace),
                'workspace' => $workspace,
                'status' => 'passed',
                'required' => false,
                'failure_policy' => 'advisory',
                'policy_decision' => 'allowed',
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 1,
                'summary_json' => [],
                'normalized_result_json' => [],
                'policy_decision_json' => [],
                'metadata_json' => [
                    'source' => 'engineering_quality_scan_service',
                    'scan_artifact_root_hash' => $scanHash,
                    'changed_only' => true,
                ],
            ]);
        }

        try {
            $report = app(ProgrammingRivalsReadinessService::class)->report($workspace);

            $this->assertSame('passed', data_get($report, 'current_local_recheck_evidence.status'));
            $this->assertSame('tool_runtime_evidence', data_get($report, 'current_local_recheck_evidence.quality_changed_only.source'));
            $this->assertSame($scanHash, data_get($report, 'current_local_recheck_evidence.quality_changed_only.artifact_root_hash'));
            $this->assertSame(2, data_get($report, 'current_local_recheck_evidence.quality_changed_only.tool_run_count'));
            $this->assertFalse(data_get($report, 'current_local_recheck_evidence.quality_changed_only.artifact_manifest_available'));
            $this->assertTrue(data_get($report, 'current_local_recheck_evidence.all_required_rechecks_known'));
            $this->assertTrue(data_get($report, 'current_local_recheck_evidence.all_known_rechecks_passed'));
            $this->assertFalse(data_get($report, 'current_local_recheck_evidence.provider_dispatches'));
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_programming_completion_audit_blocks_complete_until_real_rivals_claim_is_ready(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());

        $this->assertSame('atlas.programming.professional_completion_audit.v1', $report['schema_version']);
        $this->assertSame('blocked', $report['status']);
        $this->assertFalse($report['completion_allowed']);
        $this->assertSame('atlas.programming.local_benchmark_runtime_cache.v1', data_get($report, 'rivals_readiness.local_benchmark_cache.schema_version'));
        $this->assertFalse(data_get($report, 'rivals_readiness.local_benchmark_cache.persistent_cache'));
        $this->assertFalse(data_get($report, 'rivals_readiness.local_benchmark_cache.provider_state_cached'));
        $this->assertSame('atlas.programming.professional_completion_audit_protocol.v1', data_get($report, 'audit_protocol.schema_version'));
        $this->assertContains('professional_operating_standard_exists_and_blocks_weak_rag_mvp', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('local_retrieval_test_impact_patch_verifier_and_repair_loop_benchmarks_pass', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('rivals_integrity_blocks_unfair_or_synthetic_ab_test_scores', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('programming_cli_commands_are_registered_for_operator_execution', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('structure_mother_blocks_paid_rivals_commands_from_dirty_workspace', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('rivals_rerun_preconditions_prevent_token_spend_on_invalid_battery', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('mobile_api_battery_plan_blocks_dirty_non_git_and_invalid_historical_runs', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertContains('operator_triage_command_explains_invalid_battery_without_provider_dispatch', data_get($report, 'audit_protocol.success_criteria'));
        $this->assertTrue(data_get($report, 'audit_protocol.proxy_signal_policy.tests_alone_are_insufficient'));
        $this->assertTrue(data_get($report, 'audit_protocol.proxy_signal_policy.local_benchmarks_do_not_replace_provider_battery'));
        $docsRequirement = collect(data_get($report, 'audit_protocol.prompt_to_artifact_map'))
            ->firstWhere('prompt_requirement', 'documentacao profissional de programacao');
        $this->assertContains('docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md', $docsRequirement['primary_artifacts'] ?? []);
        $this->assertContains(
            'integridade profissional do Rivals',
            collect(data_get($report, 'audit_protocol.prompt_to_artifact_map'))->pluck('prompt_requirement')->all(),
        );
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.rejects_weak_mvp'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_replayable_context_pack'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_agentic_gap_critic'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_semantic_code_graph'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_stage_receipts_and_action_manifests'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_patch_verifier_and_test_impact'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_sandbox_repair_learning'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.blocks_synthetic_rivals_scores'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.requires_clean_rivals_workspaces_and_cost_approval'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_operating_standard.checks.declares_runtime_boundaries'));
        $this->assertTrue(data_get($report, 'artifact_coverage.professional_spec.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.agentic_rag_context_pack.covered'));
        $this->assertContains('database/migrations/2026_05_13_050000_create_atlas_programming_context_packs_table.php', data_get($report, 'artifact_coverage.agentic_rag_context_pack.required_files'));
        $this->assertTrue(data_get($report, 'artifact_coverage.hybrid_retrieval_and_gap_critic.covered'));
        $this->assertContains('database/migrations/2026_05_13_020000_create_atlas_programming_stage_receipts_table.php', data_get($report, 'artifact_coverage.stage_receipts_resume.required_files'));
        $this->assertTrue(data_get($report, 'artifact_coverage.python_runtime.covered'));
        $this->assertContains('runtimes/python/programming_intelligence/main.py', data_get($report, 'artifact_coverage.python_runtime.required_files'));
        $this->assertContains('runtimes/python/programming_intelligence/tests/test_contract.py', data_get($report, 'artifact_coverage.python_runtime.required_files'));
        $this->assertTrue(data_get($report, 'artifact_coverage.tool_runtime_manifests.covered'));
        $this->assertContains('App\\Services\\Ai\\Programming\\ProgrammingActionManifestFactory', data_get($report, 'artifact_coverage.tool_runtime_manifests.required_classes'));
        $this->assertContains('App\\Models\\AtlasProgrammingActionManifest', data_get($report, 'artifact_coverage.tool_runtime_manifests.required_classes'));
        $this->assertContains('database/migrations/2026_05_13_030000_create_atlas_programming_action_manifests_table.php', data_get($report, 'artifact_coverage.tool_runtime_manifests.required_files'));
        $this->assertContains('database/migrations/2026_05_13_040000_create_atlas_programming_learning_candidates_table.php', data_get($report, 'artifact_coverage.sandbox_repair_learning.required_files'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_cli_commands.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_cli_commands.checks.AtlasProgrammingCompletionAuditCommand.registered_in_bootstrap'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_cli_commands.checks.AtlasProgrammingRepairLoopBenchmarkCommand.registered_in_bootstrap'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_cli_commands.checks.AtlasProgrammingRivalsReadinessCommand.class_exists'));
        $this->assertTrue(data_get($report, 'artifact_coverage.structure_mother_safe_rivals_commands.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.structure_mother_safe_rivals_commands.checks.uses_clean_atlas_workspace_placeholder'));
        $this->assertTrue(data_get($report, 'artifact_coverage.structure_mother_safe_rivals_commands.checks.uses_separate_baseline_workspace_placeholder'));
        $this->assertTrue(data_get($report, 'artifact_coverage.structure_mother_safe_rivals_commands.checks.requires_operator_cost_confirmation'));
        $this->assertTrue(data_get($report, 'artifact_coverage.structure_mother_safe_rivals_commands.checks.does_not_publish_dirty_current_workspace_quick_run'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.run_endpoint_checks_plan_ready'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_historical_invalid_battery'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_dirty_atlas_workspace'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.blocks_non_git_baseline_workspace'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.exposes_operator_report'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.exposes_primary_blocker'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.exposes_workspace_dirty_count'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.exposes_invalid_battery_triage_status'));
        $this->assertTrue(data_get($report, 'artifact_coverage.api_rivals_battery_guard.checks.exposes_no_provider_call_safety'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_operator_triage_command.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_operator_triage_command.checks.triage_option_registered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_operator_triage_command.checks.triage_blocks_provider_dispatch'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_operator_triage_command.checks.triage_blocks_token_spend'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_operator_triage_command.checks.triage_exposes_diagnostic_commands'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.canonical_action_registered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.wrapper_action_registered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.declares_no_provider_call'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.declares_no_score_admitted'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.stores_multiple_triage_fingerprints'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_invalid_battery_quarantine.checks.feature_test_covers_wrapper_command'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.history_timeline_schema_declared'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.timeline_exposes_result_integrity_status'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.timeline_exposes_score_admission'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.feature_test_covers_timeline_schema'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.export_bundle_writes_history_timeline_file'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.export_verifier_requires_history_timeline_file'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_history_timeline.checks.feature_test_covers_history_timeline_export'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_experiment_validity_contract.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_experiment_validity_contract.checks.experiment_validity_schema_declared'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_experiment_validity_contract.checks.external_variables_cannot_decide_winner'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_experiment_validity_contract.checks.external_variables_only_block_comparability'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_experiment_validity_contract.checks.export_verifier_requires_experiment_validity'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_provider_runtime_preflight.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_provider_runtime_preflight.checks.base_command_accepts_provider_timeout'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_provider_runtime_preflight.checks.blocks_missing_vendor_autoload'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_provider_runtime_preflight.checks.pint_uses_high_memory'));
        $this->assertTrue(data_get($report, 'artifact_coverage.rivals_provider_runtime_preflight.checks.fair_replay_case_has_strict_scope'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_graph_rag_runtime.covered'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_graph_rag_runtime.checks.runtime_is_programming_only'));
        $this->assertTrue(data_get($report, 'artifact_coverage.programming_graph_rag_runtime.checks.runtime_preserves_ap_683_global_boundary'));
        $this->assertSame([], data_get($report, 'artifact_coverage.local_benchmarks.missing_classes'));
        $this->assertSame('atlas.programming.professional_completion_verification_evidence.v1', data_get($report, 'verification_evidence.schema_version'));
        $this->assertSame('passed', data_get($report, 'verification_evidence.local_benchmarks.retrieval.status'));
        $this->assertSame(1.0, (float) data_get($report, 'verification_evidence.local_benchmarks.retrieval.metrics.recall_at_k'));
        $this->assertTrue(data_get($report, 'verification_evidence.local_benchmarks.retrieval.promotion_gate.graph_rag_runtime_promoted'));
        $this->assertSame('atlas.programming.retrieval_benchmark_runtime_cache.v1', data_get($report, 'verification_evidence.local_benchmarks.retrieval.runtime_cache.schema_version'));
        $this->assertFalse(data_get($report, 'verification_evidence.local_benchmarks.retrieval.runtime_cache.persistent_cache'));
        $this->assertFalse(data_get($report, 'verification_evidence.local_benchmarks.retrieval.runtime_cache.provider_state_cached'));
        $this->assertSame('passed', data_get($report, 'verification_evidence.local_benchmarks.repair_loop.status'));
        $this->assertSame(1.0, data_get($report, 'verification_evidence.local_benchmarks.repair_loop.metrics.repair_planning_pass_rate'));
        $this->assertContains(data_get($report, 'verification_evidence.rivals_external_claim.status'), ['external_battery_required', 'external_battery_invalid']);
        $this->assertSame('atlas.programming.power_scorecard.v1', data_get($report, 'power_scorecard.schema_version'));
        $this->assertSame('target_not_met', data_get($report, 'power_scorecard.status'));
        $this->assertGreaterThanOrEqual(8.0, data_get($report, 'power_scorecard.score_out_of_10'));
        $this->assertLessThan(9.0, data_get($report, 'power_scorecard.score_out_of_10'));
        $this->assertNotContains('graph_rag_runtime', collect(data_get($report, 'power_scorecard.blocking_items'))->pluck('id')->all());
        $this->assertContains('real_rivals_execution_proof', collect(data_get($report, 'power_scorecard.blocking_items'))->pluck('id')->all());
        $this->assertFalse(data_get($report, 'verification_evidence.rivals_external_claim.synthetic_scores_allowed'));
        $this->assertSame('atlas.programming.rivals_result_integrity_diagnostics.v1', data_get($report, 'verification_evidence.result_integrity_diagnostics.schema_version'));
        $this->assertFalse(data_get($report, 'verification_evidence.result_integrity_diagnostics.score_admission_policy.invalid_cases_count_as_losses'));
        $this->assertSame('atlas.programming.fair_claude_result_integrity_projection.v1', data_get($report, 'verification_evidence.fair_claude_result_integrity.schema_version'));
        $this->assertFalse(data_get($report, 'verification_evidence.fair_claude_result_integrity.claim_winner_admitted'));
        $this->assertSame('atlas.programming.invalid_battery_triage_packet.v1', data_get($report, 'verification_evidence.invalid_battery_triage_packet.schema_version'));
        $this->assertSame('atlas.programming.rivals_historical_failure_policy.v1', data_get($report, 'verification_evidence.invalid_battery_triage_packet.historical_failure_policy.schema_version'));
        $this->assertSame('atlas.programming.current_rivals_rerun_preconditions.v1', data_get($report, 'verification_evidence.invalid_battery_triage_packet.current_rerun_preconditions.schema_version'));
        $this->assertSame('atlas.programming.current_local_recheck_evidence.v1', data_get($report, 'verification_evidence.invalid_battery_triage_packet.current_rerun_preconditions.current_local_rechecks.schema_version'));
        $this->assertFalse(data_get($report, 'verification_evidence.invalid_battery_triage_packet.current_rerun_preconditions.current_local_rechecks.provider_dispatches'));
        $this->assertFalse(data_get($report, 'verification_evidence.invalid_battery_triage_packet.current_rerun_preconditions.provider_dispatch_allowed_now'));
        $this->assertFalse(data_get($report, 'verification_evidence.operator_safety.provider_dispatches_now'));
        $this->assertSame('atlas.programming.professional_completion_executive_report.v1', data_get($report, 'executive_report.schema_version'));
        $this->assertSame('Blocked', data_get($report, 'executive_report.status_label'));
        $this->assertSame('ready', data_get($report, 'executive_report.primary_state.local_foundation'));
        $this->assertSame('blocked', data_get($report, 'executive_report.primary_state.external_rivals_claim'));
        $this->assertSame('rivals_programming_real', data_get($report, 'executive_report.current_blocker.id'));
        $this->assertFalse(data_get($report, 'executive_report.safety_summary.provider_dispatches_now'));
        $this->assertFalse(data_get($report, 'executive_report.safety_summary.spend_provider_tokens_now'));
        $this->assertIsString(data_get($report, 'executive_report.safety_summary.provider_budget_reason'));
        $this->assertFalse(data_get($report, 'executive_report.safety_summary.synthetic_scores_allowed'));
        $this->assertContains('Retrieval recall', collect(data_get($report, 'executive_report.key_metrics'))->pluck('label')->all());
        $this->assertTrue(data_get($report, 'summary.local_programming_foundation_ready'));
        $this->assertFalse(data_get($report, 'summary.rivals_programming_claim_ready'));
        $this->assertSame(['rivals_programming_real'], collect($report['blocking_items'])->pluck('id')->all());
        $this->assertContains('operator_execution_packet', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_integrity_assurance', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('structure_mother_safe_rivals_commands', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('programming_cli_commands', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_rerun_preconditions', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('api_rivals_battery_guard', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_operator_triage_command', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_invalid_battery_quarantine', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_history_timeline', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_experiment_validity_contract', collect($report['checklist'])->pluck('id')->all());
        $this->assertContains('rivals_provider_runtime_preflight', collect($report['checklist'])->pluck('id')->all());
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'professional_operating_standard')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'operator_execution_packet')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_integrity_assurance')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'structure_mother_safe_rivals_commands')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'programming_cli_commands')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_rerun_preconditions')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'api_rivals_battery_guard')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_operator_triage_command')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_invalid_battery_quarantine')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_history_timeline')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_experiment_validity_contract')['status'] ?? null);
        $this->assertSame('passed', collect($report['checklist'])->firstWhere('id', 'rivals_provider_runtime_preflight')['status'] ?? null);
        $this->assertContains(data_get($report, 'blocking_items.0.blocker'), ['external_battery_required', 'external_battery_invalid']);
        $this->assertTrue(data_get($report, 'rules.power_score_is_not_completion_without_external_rivals_claim'));
        $this->assertFalse(data_get($report, 'rules.synthetic_scores_allowed'));
        $this->assertSame('atlas.programming.rivals_operator_execution_packet.v1', data_get($report, 'rivals_readiness.operator_execution_packet.schema_version'));
        $this->assertSame('atlas.programming.rivals_integrity_assurance.v1', data_get($report, 'rivals_readiness.integrity_assurance.schema_version'));
        $this->assertSame('atlas.programming.rivals_quality_scope_policy.v1', data_get($report, 'rivals_readiness.integrity_assurance.quality_scope_policy.schema_version'));
        $this->assertTrue(data_get($report, 'rivals_readiness.integrity_assurance.quality_scope_policy.repo_wide_scan_allowed_as_diagnostic_only'));
        $this->assertSame('atlas.programming.rivals_result_integrity_diagnostics.v1', data_get($report, 'rivals_readiness.result_integrity_diagnostics.schema_version'));
        $this->assertSame('atlas.programming.fair_claude_result_integrity_projection.v1', data_get($report, 'rivals_readiness.fair_claude_result_integrity.schema_version'));
        $this->assertSame('atlas.programming.latest_real_battery_evidence.v1', data_get($report, 'rivals_readiness.latest_real_battery_evidence.schema_version'));
        $this->assertSame('atlas.programming.current_local_recheck_evidence.v1', data_get($report, 'rivals_readiness.current_local_recheck_evidence.schema_version'));
        $this->assertSame('atlas.programming.invalid_battery_triage_packet.v1', data_get($report, 'rivals_readiness.invalid_battery_triage_packet.schema_version'));
        $this->assertSame('claim_blocked', data_get($report, 'rivals_readiness.integrity_assurance.status'));
        $this->assertFalse(data_get($report, 'rivals_readiness.operator_execution_packet.provider_dispatches_now'));
        $this->assertSame('atlas.programming.rivals_readiness.v1', data_get($report, 'external_rivals_certification.schema_version'));
        $this->assertIsString(data_get($report, 'external_rivals_certification.operational_state'));
        $this->assertSame('atlas.code.enterprise_certification.v1', data_get($report, 'atlas_code_enterprise_certification.schema_version'));
        $this->assertSame('available', data_get($report, 'atlas_code_enterprise_certification.status'));
        $this->assertTrue(data_get($report, 'atlas_code_enterprise_certification.artifacts.controller.present'));
        $this->assertContains(
            'test_atlas_code_enterprise_certification_api_exposes_product_proof_packet',
            data_get($report, 'atlas_code_enterprise_certification.test_coverage.expected_methods'),
        );
        $this->assertSame('GET /atlas-code/certification', data_get($report, 'atlas_code_enterprise_certification.api_surface.read_model_endpoint'));
        $this->assertTrue(data_get($report, 'atlas_code_enterprise_certification.contract_invariants.api_read_model_does_not_create_obra'));
        $this->assertSame(
            data_get($report, 'verification_evidence.invalid_battery_triage_packet.status'),
            data_get($report, 'external_rivals_certification.invalid_battery_triage.status'),
        );
        $this->assertSame(
            (bool) data_get($report, 'verification_evidence.current_workspace_preflight.ready_for_provider_battery'),
            data_get($report, 'external_rivals_certification.current_workspace_preflight.ready_for_provider_battery'),
        );
        $this->assertIsBool(data_get($report, 'external_rivals_certification.provider_budget_policy.spend_more_provider_tokens_now'));
        $this->assertIsArray(data_get($report, 'external_rivals_certification.fresh_provider_rerun_preconditions.blocking_reasons'));

        $exitCode = Artisan::call('atlas:programming:completion-audit', [
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('atlas.programming.professional_completion_audit.v1', data_get($payload, 'schema_version'));
        $this->assertSame('blocked', data_get($payload, 'status'));
        $this->assertFalse(data_get($payload, 'completion_allowed'));

        $refreshExitCode = Artisan::call('atlas:programming:completion-audit', [
            '--workspace' => base_path(),
            '--refresh-local-benchmarks' => true,
            '--json' => true,
        ]);
        $refreshPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $refreshExitCode);
        $this->assertTrue(data_get($refreshPayload, 'rivals_readiness.local_benchmark_cache.refresh_requested'));
        $this->assertFalse(data_get($refreshPayload, 'rivals_readiness.local_benchmark_cache.hit'));

        $humanExitCode = Artisan::call('atlas:programming:completion-audit', [
            '--workspace' => base_path(),
        ]);
        $humanOutput = Artisan::output();

        $this->assertSame(1, $humanExitCode);
        $this->assertStringContainsString('Programming completion audit', $humanOutput);
        $this->assertStringContainsString('Programming foundation ready locally', $humanOutput);
        $this->assertStringContainsString('Power score', $humanOutput);
        $this->assertStringContainsString('Power target', $humanOutput);
        $this->assertStringContainsString('Professional RAG standard', $humanOutput);
        $this->assertStringContainsString('Anti-MVP gate', $humanOutput);
        $this->assertStringContainsString('Replayable context pack', $humanOutput);
        $this->assertStringContainsString('Semantic code graph', $humanOutput);
        $this->assertStringContainsString('Verifier/Test Impact', $humanOutput);
        $this->assertStringContainsString('Repair Loop benchmark', $humanOutput);
        $this->assertStringContainsString('Retrieval recall', $humanOutput);
        $this->assertStringContainsString('Comparable real Rivals cases', $humanOutput);
        $this->assertStringContainsString('Fair Claude result', $humanOutput);
        $this->assertStringContainsString('Claim winner admitted', $humanOutput);
        $this->assertStringContainsString('Render winner in UI', $humanOutput);
        $this->assertStringContainsString('Spend provider tokens now', $humanOutput);
        $this->assertStringContainsString('provider budget', $humanOutput);
        $this->assertStringContainsString('API battery guard', $humanOutput);
        $this->assertStringContainsString('API blocks dirty/non-Git', $humanOutput);
        $this->assertStringContainsString('API blocks invalid rerun', $humanOutput);
        $this->assertStringContainsString('Invalid battery quarantine', $humanOutput);
        $this->assertStringContainsString('Quarantine admits score', $humanOutput);
        $this->assertStringContainsString('Quarantine deletes history', $humanOutput);
        $this->assertStringContainsString('Current workspace', $humanOutput);
        $this->assertStringContainsString('Workspace ready for Rivals', $humanOutput);
        $this->assertStringContainsString('Workspace dirty files', $humanOutput);
        if (data_get($report, 'verification_evidence.invalid_battery_triage_packet.status') === 'triage_required_before_rerun') {
            $this->assertStringContainsString('Invalid battery triage', $humanOutput);
            $this->assertStringContainsString('Spend provider tokens now', $humanOutput);
            $this->assertStringContainsString('Current rerun preconditions', $humanOutput);
            $this->assertStringContainsString('Rerun precondition dispatch', $humanOutput);
            $this->assertStringContainsString('Current local rechecks', $humanOutput);
            $this->assertStringContainsString('Quality changed-only', $humanOutput);
            $this->assertStringContainsString('Visual smoke', $humanOutput);
            $this->assertStringContainsString('triage fix_failed_tests', $humanOutput);
        }
        $this->assertStringContainsString('rivals_programming_real:', $humanOutput);
        $this->assertStringContainsString('Next:', $humanOutput);
    }

    public function test_python_runtime_executor_blocks_without_receipt_and_runs_when_approved(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-programming-python-runtime-'.Str::random(8);
        File::ensureDirectoryExists($workspace.'/app');
        File::put($workspace.'/app/Foo.php', "<?php\nclass Foo { function run() {} }\n");

        try {
            $contract = app(ProgrammingPythonRuntimeContract::class)->manifest(
                workspace: $workspace,
                files: ['app/Foo.php'],
            );

            $blocked = app(ProgrammingPythonRuntimeExecutor::class)->execute(
                contract: $contract,
                decisionReceiptHash: 'bad',
                runtimeBoundaryGreen: true,
                approved: true,
            );

            $this->assertSame('blocked', $blocked['status']);
            $this->assertContains('decision_receipt_hash_required', data_get($blocked, 'gate.reasons'));

            $receipt = app(ProgrammingPythonRuntimeExecutor::class)->execute(
                contract: $contract,
                decisionReceiptHash: str_repeat('a', 64),
                runtimeBoundaryGreen: true,
                approved: true,
            );

            $this->assertSame('atlas.programming.python_runtime.execution_receipt.v1', $receipt['schema_version']);
            $this->assertSame('passed', $receipt['status']);
            $this->assertSame('atlas.programming.python_runtime.analysis.v1', data_get($receipt, 'result.schema_version'));
            $this->assertFalse(data_get($receipt, 'result.runtime.provider_calls'));
            $this->assertSame('Foo', data_get($receipt, 'result.files.0.symbols.0.name'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);

            $fragment = app(ProgrammingPythonRuntimeGraphProjector::class)->project($receipt);

            $this->assertSame('atlas.programming.python_runtime.graph_fragment.v1', $fragment['schema_version']);
            $this->assertSame('ready', $fragment['status']);
            $this->assertContains('declares_symbol', collect($fragment['edges'])->pluck('type')->all());
            $this->assertContains('Foo', collect($fragment['nodes'])->pluck('symbol')->filter()->all());
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_semantic_code_graph_prefers_indexed_code_intelligence_when_available(): void
    {
        $this->createAtlasEngineeringCodeTables();

        $module = AtlasEngineeringCodeModule::query()->create([
            'slug' => 'atlas-programming-orchestrator',
            'name' => 'Atlas Programming Orchestrator',
            'layer' => 'domain',
            'root_path' => 'app/Services/Ai/Programming',
            'primary_language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => str_repeat('a', 64),
            'related_docs_json' => ['docs/engineering-knowledge-base/domains/programming.md'],
            'related_tests_json' => ['tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php'],
            'metadata' => [],
        ]);

        $symbol = AtlasEngineeringCodeSymbol::query()->create([
            'module_id' => $module->id,
            'symbol_type' => 'class',
            'symbol_name' => 'AtlasProgrammingOrchestrator',
            'file_path' => 'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php',
            'line_start' => 12,
            'line_end' => 560,
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => str_repeat('b', 64),
            'related_doc_ids_json' => [],
            'metadata' => [
                'imports' => ['App\Services\Ai\Programming\ProgrammingResumeService'],
            ],
        ]);

        AtlasEngineeringDocLink::query()->create([
            'module_id' => $module->id,
            'symbol_id' => $symbol->id,
            'link_type' => 'canonical_doc',
            'status' => 'current',
            'canonical_path' => 'docs/engineering-knowledge-base/domains/programming.md',
            'link_hash' => hash('sha256', 'programming-doc-link'),
            'metadata' => [],
        ]);

        $graph = app(ProgrammingSemanticCodeGraphService::class)->query(
            workspace: base_path(),
            objective: 'programming orchestrator resume repair',
            flow: 'programming.repair',
        );

        $this->assertSame('engineering_code_intelligence', $graph['source']);
        $this->assertGreaterThanOrEqual(3, $graph['node_count']);
        $this->assertContains('tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php', $graph['related_tests']);
        $this->assertContains('docs/engineering-knowledge-base/domains/programming.md', $graph['related_docs']);
        $this->assertContains('depends_on', collect($graph['edges'])->pluck('type')->all());
    }

    public function test_stage_receipts_validate_hashes_and_resume_blocks_invalid_timeline(): void
    {
        $store = app(ProgrammingStageReceiptStore::class);
        $receipt = $store->make('plan-1', null, 'plan', 1, 'passed', ['a' => 1], ['b' => 2]);

        $this->assertSame('atlas.programming.stage_receipt.v1', $receipt['schema_version']);
        $this->assertTrue(data_get($receipt, 'validation.valid'));

        $broken = $receipt;
        $broken['receipt_id'] = 'bad';
        $validation = app(ProgrammingStageReceiptValidator::class)->validate($broken);

        $this->assertFalse($validation['valid']);
        $this->assertContains('receipt_id_hash_mismatch', $validation['errors']);

        $patchReceipt = $store->make('plan-1', null, 'patch', 1, 'passed', ['a' => 1], ['b' => 2]);
        $reviewReceipt = $store->make('plan-1', null, 'review', 1, 'passed', ['a' => 1], ['b' => 2]);
        $timeline = app(ProgrammingStageReceiptValidator::class)->validateTimeline([$patchReceipt, $reviewReceipt]);

        $this->assertFalse($timeline['valid']);
        $this->assertContains('stage_order_regression', $timeline['errors']);
    }

    public function test_stage_receipts_can_persist_and_reload_timeline_for_resume(): void
    {
        $this->createProgrammingStageReceiptTable();

        $store = app(ProgrammingStageReceiptStore::class);
        $receipt = $store->make(
            planId: 'plan-resume-1',
            parentPlanId: 'parent-1',
            stage: 'patch',
            attempt: 2,
            status: 'passed',
            input: ['changed_files' => ['app/Services/Foo.php']],
            output: ['tests' => ['tests/Unit/FooTest.php']],
            evidenceRefs: ['tool:patch-verifier'],
            persist: true,
        );

        $this->assertTrue(data_get($receipt, 'storage.persisted'));
        $this->assertDatabaseHas('atlas_programming_stage_receipts', [
            'receipt_id' => $receipt['receipt_id'],
            'plan_id' => 'plan-resume-1',
            'stage' => 'patch',
            'attempt' => 2,
            'status' => 'passed',
        ]);

        $timeline = $store->timeline('plan-resume-1');

        $this->assertCount(1, $timeline);
        $this->assertSame('patch', data_get($timeline, '0.stage'));
        $this->assertSame('tool:patch-verifier', data_get($timeline, '0.evidence_refs.0'));
        $this->assertTrue(data_get($timeline, '0.validation.valid'));

        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-child-1',
            parentPlanId: 'plan-resume-1',
        );

        $this->assertSame('stage_receipt_store', $resume['previous_stage_receipt_source']);
        $this->assertSame(1, $resume['previous_stage_receipt_count']);
        $this->assertSame('patch', $resume['latest_stage']);
        $this->assertTrue($resume['resume_allowed']);
        $this->assertSame('atlas.programming.continuation_packet.v1', data_get($resume, 'continuation_packet.schema_version'));
        $this->assertSame('ready', data_get($resume, 'continuation_packet.status'));
        $this->assertSame('plan', data_get($resume, 'continuation_packet.next_stage'));
        $this->assertContains('load_stage_receipts', data_get($resume, 'continuation_packet.required_before_next_provider_call'));
        $this->assertTrue(data_get($resume, 'continuation_packet.stop_rules.missing_prior_decision_blocks_write'));
    }

    public function test_patch_verifier_test_impact_sandbox_repair_and_learning_contracts(): void
    {
        $impact = app(ProgrammingTestImpactAnalyzer::class)->analyze(
            changedFiles: ['app/Services/Foo.php'],
            codeGraph: ['related_tests' => ['tests/Unit/FooTest.php']],
            risk: 'high',
        );
        $this->assertSame('atlas.programming.test_impact.receipt.v1', $impact['schema_version']);
        $this->assertContains('tests/Unit/FooTest.php', $impact['selected_tests']);
        $this->assertSame([], $impact['selected_existing_tests']);
        $this->assertContains('/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php', $impact['recommended_commands']);
        $this->assertContains('npm run test:engineering', $impact['recommended_commands']);

        $blockedPatch = app(ProgrammingPatchVerifier::class)->verify([
            'changed_files' => ['app/Services/Foo.php'],
            'tests' => [],
            'action_manifests' => [],
        ]);
        $this->assertSame('blocked', $blockedPatch['status']);
        $this->assertContains('missing_tests_or_reason', $blockedPatch['blocking_reasons']);

        $passedPatch = app(ProgrammingPatchVerifier::class)->verify([
            'changed_files' => ['app/Services/Foo.php'],
            'tests' => ['tests/Unit/FooTest.php'],
            'action_manifests' => [[
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'stage' => 'patch',
                'dry_run' => false,
                'changed_files' => ['app/Services/Foo.php'],
                'rollback' => ['available' => true],
                'gate_effect' => 'passed',
            ]],
        ]);
        $this->assertSame('passed', $passedPatch['status']);
        $this->assertSame(['app/Services/Foo.php'], $passedPatch['manifest_covered_files']);

        $sandbox = app(ProgrammingSandboxManager::class)->plan(base_path(), 'high', true);
        $this->assertSame('atlas.programming.execution_sandbox.plan.v1', $sandbox['schema_version']);
        $this->assertSame('isolated_worktree_required', $sandbox['mode']);
        $this->assertTrue($sandbox['promotion_requires_patch_verifier']);
        $this->assertSame('discard_isolated_worktree', data_get($sandbox, 'rollback_plan.strategy'));
        $this->assertTrue(data_get($sandbox, 'integrity_gate.must_attach_action_manifests'));
        $rollbackReceipt = app(ProgrammingSandboxManager::class)->rollbackReceipt($sandbox);
        $this->assertSame('atlas.programming.sandbox_rollback_receipt.v1', $rollbackReceipt['schema_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rollbackReceipt['receipt_hash']);

        $repair = app(ProgrammingRepairExecutor::class)->attemptPlan(
            failurePacket: ['failure_hash' => 'a'],
            retrievalPlan: ['retrieval_receipt' => ['receipt_id' => 'rag-1']],
            attempt: 1,
            maxAttempts: 3,
        );
        $this->assertSame('atlas.programming.repair_attempt.plan.v1', $repair['schema_version']);
        $this->assertSame('planned', $repair['status']);
        $this->assertSame('atlas.programming.repair_capsule.v1', data_get($repair, 'repair_capsule.schema_version'));
        $this->assertSame('unknown_failure', data_get($repair, 'repair_capsule.failure_taxonomy.category'));
        $this->assertFalse(data_get($repair, 'repair_capsule.provider_policy.fallback_allowed'));
        $this->assertTrue(data_get($repair, 'repair_capsule.verification_plan.patch_verifier_required'));
        $this->assertFalse(data_get($repair, 'repair_capsule.scope.allow_scope_expansion'));

        $repairReceipt = app(ProgrammingRepairAttemptStore::class)->receipt(
            planId: 'plan-1',
            parentPlanId: null,
            attempt: 1,
            status: 'failed',
            failurePacket: ['failure_hash' => 'failure-1'],
            patchManifest: [
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'action_id' => 'patch-1',
            ],
            testManifest: [
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'action_id' => 'test-1',
            ],
        );
        $this->assertSame('atlas.programming.stage_receipt.v1', $repairReceipt['schema_version']);
        $this->assertSame('atlas.programming.repair_attempt.receipt.v1', data_get($repairReceipt, 'repair_attempt.repair_attempt_schema'));
        $this->assertContains('manifest:patch-1', $repairReceipt['evidence_refs']);

        $candidate = app(ProgrammingLearningCandidateProjector::class)->project([
            'status' => 'passed',
            'evidence_refs' => ['stage:1'],
        ]);
        $this->assertSame('atlas.programming.learning_candidate.v1', $candidate['schema_version']);
        $this->assertFalse($candidate['promotion_allowed']);
        $this->assertSame('reject_candidate_without_memory_promotion', data_get($candidate, 'rollback.strategy'));

        $gate = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: true);
        $this->assertSame('atlas.programming.learning_promotion_gate.v1', $gate['schema_version']);
        $this->assertTrue($gate['promotion_allowed']);
    }

    public function test_learning_candidates_are_queued_deduped_and_promoted_only_after_review_gate(): void
    {
        $this->createProgrammingLearningCandidateTable();

        $candidate = app(ProgrammingLearningCandidateProjector::class)->project([
            'status' => 'passed',
            'evidence_refs' => ['stage:plan', 'manifest:test'],
        ]);
        $store = app(ProgrammingLearningCandidateStore::class);
        $queued = $store->enqueue($candidate);
        $queuedAgain = $store->enqueue($candidate);

        $this->assertTrue(data_get($queued, 'review_queue.queued'));
        $this->assertSame(data_get($queued, 'review_queue.candidate_id'), data_get($queuedAgain, 'review_queue.candidate_id'));
        $this->assertDatabaseCount('atlas_programming_learning_candidates', 1);
        $this->assertSame(1, count($store->pending()));

        $blocked = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: false);
        $blockedRecord = $store->recordPromotionGate($candidate['candidate_hash'], $blocked);
        $this->assertTrue($blockedRecord['recorded']);
        $this->assertFalse($blockedRecord['promotion_allowed']);

        $approved = app(ProgrammingLearningPromotionGate::class)->evaluate($candidate, humanReviewed: true);
        $approvedRecord = $store->recordPromotionGate($candidate['candidate_hash'], $approved);
        $this->assertTrue($approvedRecord['recorded']);
        $this->assertTrue($approvedRecord['promotion_allowed']);
        $this->assertDatabaseHas('atlas_programming_learning_candidates', [
            'candidate_hash' => $candidate['candidate_hash'],
            'status' => 'promotion_approved',
            'promotion_allowed' => true,
        ]);
    }

    public function test_high_risk_write_sandbox_provisions_isolated_git_worktree(): void
    {
        $repo = sys_get_temp_dir().'/atlas-programming-sandbox-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($repo);

        try {
            $this->runGit(['git', 'init'], $repo);
            File::put($repo.'/a.txt', 'original');
            $this->runGit(['git', 'add', 'a.txt'], $repo);
            $this->runGit(['git', '-c', 'user.email=atlas@example.test', '-c', 'user.name=Atlas Test', 'commit', '-m', 'initial'], $repo);

            $sandbox = app(ProgrammingSandboxManager::class)->provision($repo, 'high', true);

            $this->assertTrue($sandbox['provisioned']);
            $this->assertSame('git_worktree', $sandbox['provisioning_mode']);
            $this->assertIsString($sandbox['execution_workspace']);
            $this->assertTrue(File::exists($sandbox['execution_workspace'].'/a.txt'));

            File::put($sandbox['execution_workspace'].'/a.txt', 'changed in sandbox');
            $this->assertSame('original', File::get($repo.'/a.txt'));

            $this->runGit(['git', 'worktree', 'remove', '--force', $sandbox['execution_workspace']], $repo);
        } finally {
            File::deleteDirectory($repo);
        }
    }

    public function test_resume_command_replays_parent_plan_from_persisted_receipts(): void
    {
        $this->createProgrammingStageReceiptTable();

        app(ProgrammingStageReceiptStore::class)->make(
            planId: 'parent-plan-command',
            parentPlanId: null,
            stage: 'test',
            attempt: 1,
            status: 'passed',
            input: ['selected_tests' => ['tests/Unit/FooTest.php']],
            output: ['status' => 'passed'],
            evidenceRefs: ['manifest:test'],
            persist: true,
        );

        $exitCode = Artisan::call('atlas:programming:resume', [
            'parent_plan_id' => 'parent-plan-command',
            '--plan-id' => 'child-plan-command',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('atlas.programming.resume_state.v1', data_get($payload, 'resume_state.schema_version'));
        $this->assertSame('stage_receipt_store', data_get($payload, 'resume_state.previous_stage_receipt_source'));
        $this->assertSame('test', data_get($payload, 'resume_state.latest_stage'));
        $this->assertSame('atlas.programming.continuation_packet.v1', data_get($payload, 'resume_state.continuation_packet.schema_version'));
        $this->assertSame('plan', data_get($payload, 'resume_state.continuation_packet.next_stage'));
        $this->assertStringContainsString('parent-plan-command', data_get($payload, 'resume_state.continuation_packet.resume_command'));
        $this->assertSame('continue_from_latest_stage', $payload['next_action']);
    }

    private function createProgrammingStageReceiptTable(): void
    {
        Schema::dropIfExists('atlas_programming_stage_receipts');

        Schema::create('atlas_programming_stage_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('stage', 40)->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 32)->index();
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->timestamps();
        });
    }

    private function createProgrammingLearningCandidateTable(): void
    {
        Schema::dropIfExists('atlas_programming_learning_candidates');

        Schema::create('atlas_programming_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('candidate_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('source_status', 40)->nullable()->index();
            $table->boolean('promotion_allowed')->default(false)->index();
            $table->boolean('review_required')->default(true)->index();
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('promotion_gate_json')->default('{}');
            $table->json('rollback_json')->default('{}');
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createProgrammingContextPackTable(): void
    {
        Schema::dropIfExists('atlas_programming_context_packs');

        Schema::create('atlas_programming_context_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('context_pack_hash', 64)->unique();
            $table->string('schema_version', 120)->index();
            $table->string('status', 32)->index();
            $table->boolean('provider_safe')->default(true)->index();
            $table->string('retrieval_strategy', 80)->index();
            $table->json('ranked_refs_json')->default('[]');
            $table->json('excluded_refs_json')->default('[]');
            $table->json('source_counts_json')->default('{}');
            $table->json('metrics_json')->default('{}');
            $table->json('budget_json')->default('{}');
            $table->json('payload_json')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * @param  array<int,string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
