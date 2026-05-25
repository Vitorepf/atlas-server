<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use Tests\TestCase;

class AtlasFrontendDesignRuntimeServiceTest extends TestCase
{
    public function test_market_leading_contract_selects_frontend_capabilities_gates_and_evidence(): void
    {
        $contract = app(AtlasFrontendDesignRuntimeService::class)->contract([
            'task' => 'Criar frontend SaaS multiempresa com live variants, design system, assets de marca e performance',
            'workspace' => '/tmp/acme',
            'benchmark_run' => true,
        ]);

        $this->assertSame('atlas.frontend.design_runtime_contract.v1', $contract['schema_version']);
        $this->assertSame('ready', $contract['status']);
        $this->assertSame('programming.frontend', $contract['specialist_profile']);
        $this->assertContains('production_ui_patch', $contract['output_types']);
        $this->assertContains('clickable_prototype', $contract['output_types']);
        $this->assertContains('live_visual_iteration', $contract['output_types']);
        $this->assertContains('design_direction_advisor', $contract['required_capabilities']);
        $this->assertContains('company_owned_local_repo_design_dossier', $contract['required_capabilities']);
        $this->assertContains('product_ux_visual_success_blueprint', $contract['required_capabilities']);
        $this->assertContains('multi_company_design_system_adaptation', $contract['required_capabilities']);
        $this->assertContains('design_system_drift_gate', $contract['required_capabilities']);
        $this->assertContains('frontend_asset_pack_verifier', $contract['required_capabilities']);
        $this->assertContains('anti_ai_slop_detector', $contract['required_capabilities']);
        $this->assertContains('frontend_visual_quality_gate', $contract['required_capabilities']);
        $this->assertContains('live_css_preview_relay', $contract['required_capabilities']);
        $this->assertContains('frontend_visual_quality_gate', $contract['required_gates']);
        $this->assertContains('visual_smoke_multi_viewport', $contract['required_gates']);
        $this->assertContains('design_system_inventory_or_profile', $contract['required_gates']);
        $this->assertContains('company_design_dossier_ready_or_created', $contract['required_gates']);
        $this->assertContains('source_patch_boundary_check', $contract['required_gates']);
        $this->assertContains('selected_design_direction_or_reason', $contract['required_evidence']);
        $this->assertContains('company_design_dossier', $contract['required_evidence']);
        $this->assertContains('product_blueprint', $contract['required_evidence']);
        $this->assertContains('design_system_inventory', $contract['required_evidence']);
        $this->assertContains('frontend_asset_pack', $contract['required_evidence']);
        $this->assertContains('visual_quality_report', $contract['required_evidence']);
        $this->assertContains('live_iteration_event_journal', $contract['required_evidence']);
        $this->assertContains('preview_variant_event', $contract['required_evidence']);
        $this->assertSame('required', data_get($contract, 'visual_quality_gate_contract.status'));
        $this->assertSame('required_for_premium_or_new_product_frontend', data_get($contract, 'product_blueprint_contract.status'));
        $this->assertContains('ux_success_model', data_get($contract, 'product_blueprint_contract.covers'));
        $this->assertTrue((bool) data_get($contract, 'product_blueprint_contract.claim_policy.premium_frontend_work_requires_blueprint'));
        $this->assertSame('recommended_entrypoint_for_local_company_repos', data_get($contract, 'gauntlet_contract.status'));
        $this->assertContains('company_design_dossier', data_get($contract, 'gauntlet_contract.composes'));
        $this->assertTrue((bool) data_get($contract, 'gauntlet_contract.claim_policy.premium_frontend_claim_requires_ready_gauntlet'));
        $this->assertSame('required_before_provider_dispatch_for_company_repo_work', data_get($contract, 'work_order_contract.status'));
        $this->assertContains('visual_quality_verification', data_get($contract, 'work_order_contract.packets'));
        $this->assertTrue((bool) data_get($contract, 'work_order_contract.claim_policy.provider_dispatch_requires_ready_work_order'));
        $this->assertSame('required_before_visual_quality_verification', data_get($contract, 'scenario_matrix_contract.status'));
        $this->assertContains('states', data_get($contract, 'scenario_matrix_contract.covers'));
        $this->assertTrue((bool) data_get($contract, 'scenario_matrix_contract.claim_policy.visual_done_requires_scenario_matrix_evidence'));
        $this->assertSame('required_before_execution_gate', data_get($contract, 'task_spec_contract.status'));
        $this->assertContains('user_journeys', data_get($contract, 'task_spec_contract.canonical_sections'));
        $this->assertTrue((bool) data_get($contract, 'task_spec_contract.claim_policy.frontend_execution_requires_task_spec_hash'));
        $this->assertSame('required', data_get($contract, 'design_review_contract.status'));
        $this->assertContains('visual_hierarchy', data_get($contract, 'design_review_contract.required_dimensions'));
        $this->assertTrue((bool) data_get($contract, 'design_review_contract.claim_policy.visual_completion_requires_passed_5d_review'));
        $this->assertSame('required', data_get($contract, 'execution_gate_contract.status'));
        $this->assertTrue((bool) data_get($contract, 'execution_gate_contract.claim_policy.provider_dispatch_requires_gate_not_blocked'));
        $this->assertTrue((bool) data_get($contract, 'execution_gate_contract.claim_policy.provider_dispatch_requires_matching_task_spec_hash'));
        $this->assertSame('available_after_failed_gate', data_get($contract, 'repair_planner_contract.status'));
        $this->assertContains('design_review_score_below_threshold', data_get($contract, 'repair_planner_contract.known_signals'));
        $this->assertSame('required_before_completion_claim', data_get($contract, 'run_certification_contract.status'));
        $this->assertContains('outcome_memory_record', data_get($contract, 'run_certification_contract.required_evidence'));
        $this->assertTrue((bool) data_get($contract, 'run_certification_contract.claim_policy.frontend_completion_claim_requires_run_certification'));
        $this->assertTrue((bool) data_get($contract, 'run_certification_contract.claim_policy.frontend_completion_claim_requires_outcome_memory'));
        $this->assertSame('required_for_customer_or_enterprise_handoff', data_get($contract, 'delivery_handoff_contract.status'));
        $this->assertContains('known_limitations', data_get($contract, 'delivery_handoff_contract.required_evidence'));
        $this->assertTrue((bool) data_get($contract, 'delivery_handoff_contract.claim_policy.customer_handoff_requires_matching_task_spec_hash'));
        $this->assertSame('required_after_execution', data_get($contract, 'outcome_memory_contract.status'));
        $this->assertTrue((bool) data_get($contract, 'outcome_memory_contract.claim_policy.frontend_learning_requires_outcome_record'));
        $this->assertContains('mobile', data_get($contract, 'visual_quality_gate_contract.required_viewports'));
        $this->assertContains('text_overlap', data_get($contract, 'visual_quality_gate_contract.required_checks'));
        $this->assertContains('frontend_quality_budget_gate', $contract['required_gates']);
        $this->assertContains('quality_budget_report', $contract['required_evidence']);
        $this->assertSame('required', data_get($contract, 'quality_budget_gate_contract.status'));
        $this->assertSame(2500, data_get($contract, 'quality_budget_gate_contract.budgets.lcp_ms.max'));
        $this->assertTrue((bool) data_get($contract, 'quality_budget_gate_contract.claim_policy.frontend_quality_budget_requires_measured_values'));
        $this->assertSame('required', data_get($contract, 'design_system_drift_gate_contract.status'));
        $this->assertContains('palette_tokens', data_get($contract, 'design_system_drift_gate_contract.required_token_categories'));
        $this->assertSame('required', data_get($contract, 'asset_pack_contract.status'));
        $this->assertContains('logo', data_get($contract, 'asset_pack_contract.recommended_asset_kinds'));
        $this->assertSame('source_patch_only_with_boundary_and_recovery', data_get($contract, 'live_iteration_contract.mutation_policy'));
        $this->assertTrue((bool) data_get($contract, 'provider_policy.provider_neutral'));
        $this->assertSame('AtlasFrontendBrowserBridgeService', data_get($contract, 'live_iteration_contract.browser_bridge_runtime'));
        $this->assertSame('AtlasFrontendFrameworkAdapterRuntimeService', data_get($contract, 'live_iteration_contract.framework_adapter_runtime'));
        $this->assertSame('AtlasFrontendLivePreviewRelayService', data_get($contract, 'live_iteration_contract.live_preview_relay_runtime'));
        $this->assertSame('AtlasFrontendLiveSourcePatchRuntimeService', data_get($contract, 'live_iteration_contract.local_source_patch_runtime'));
        $this->assertSame('required', data_get($contract, 'company_design_profile_contract.status'));
        $this->assertContains('brand_system', data_get($contract, 'company_design_profile_contract.required_sections'));
        $this->assertSame('required', data_get($contract, 'design_dossier_contract.status'));
        $this->assertSame('company_owned_local_repo_on_operator_macbook', data_get($contract, 'design_dossier_contract.default_operating_mode'));
        $this->assertArrayHasKey('product_experience_brief', data_get($contract, 'design_dossier_contract.required_documents'));
        $this->assertTrue((bool) data_get($contract, 'design_dossier_contract.claim_policy.premium_design_claim_requires_ready_dossier'));
        $this->assertSame('required', data_get($contract, 'design_system_inventory_contract.status'));
        $this->assertTrue((bool) data_get($contract, 'design_system_inventory_contract.claim_policy.inventory_returns_hashes_and_refs_not_raw_source'));
        $this->assertSame('required', data_get($contract, 'design_direction_advisor_contract.status'));
        $this->assertContains('operational_clarity', data_get($contract, 'design_direction_advisor_contract.direction_ids'));
        $this->assertSame('real_rival_replay_and_hosted_public_product_proof_still_required_before world-best claim', data_get($contract, 'competitive_scorecard.benchmarks.pbakaus_impeccable.remaining_gap'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $contract['contract_hash']);
    }

    public function test_contract_blocks_asset_heavy_work_without_asset_provenance_when_required(): void
    {
        $contract = app(AtlasFrontendDesignRuntimeService::class)->contract([
            'task' => 'Criar hero com logo e imagens de marca',
            'asset_provenance' => false,
        ]);

        $this->assertSame('blocked', $contract['status']);
        $this->assertSame('asset_provenance_missing', data_get($contract, 'blockers.0.id'));
    }

    public function test_certification_is_honest_and_covers_commands_tests_and_docs(): void
    {
        $certification = app(AtlasFrontendDesignRuntimeService::class)->certify();

        $this->assertSame('atlas.frontend.design_runtime_certification.v1', $certification['schema_version']);
        $this->assertContains($certification['status'], ['ready', 'partial', 'blocked']);
        $this->assertFalse((bool) data_get($certification, 'market_claim_policy.may_claim_world_best_frontend_system'));
        $this->assertTrue((bool) data_get($certification, 'market_claim_policy.world_best_requires_real_benchmark_runs'));
        $this->assertContains('runtime_service_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('benchmark_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('gauntlet_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('gauntlet_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('gauntlet_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('gauntlet_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('work_order_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('work_order_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('work_order_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('work_order_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('scenario_matrix_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('scenario_matrix_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('scenario_matrix_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('scenario_matrix_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('product_blueprint_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('product_blueprint_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('product_blueprint_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('product_blueprint_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('task_spec_compiler_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('task_spec_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('task_spec_compiler_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('task_spec_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('selected_workspace_space_runtime_absent', collect($certification['checks'])->pluck('id')->all());
        $this->assertSame('pass', collect($certification['checks'])->firstWhere('id', 'selected_workspace_space_runtime_absent')['status'] ?? null);
        $this->assertContains('benchmark_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('browser_bridge_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('browser_bridge_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('framework_adapter_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('framework_adapter_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_inventory_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_inventory_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_inventory_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_inventory_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('execution_gate_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('execution_gate_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('execution_gate_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('execution_gate_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('repair_planner_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('repair_planner_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('repair_planner_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('repair_planner_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('run_certification_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('run_certification_task_spec_hash_consistency_enforced', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('run_certification_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('run_certification_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('run_certification_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('delivery_handoff_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('delivery_handoff_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('delivery_handoff_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('delivery_handoff_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('live_preview_relay_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('live_preview_relay_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('product_proof_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('product_proof_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('company_design_profile_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_dossier_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_dossier_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_dossier_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_dossier_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('company_design_profile_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('company_design_profile_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_direction_advisor_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_direction_advisor_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_direction_advisor_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_direction_advisor_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('asset_pack_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('asset_pack_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('asset_pack_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('asset_pack_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_review_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_review_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_review_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_review_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('visual_quality_gate_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('visual_quality_gate_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('visual_quality_gate_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('visual_quality_gate_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('quality_budget_gate_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('quality_budget_gate_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('quality_budget_gate_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('quality_budget_gate_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_drift_gate_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_drift_gate_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_drift_gate_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('design_system_drift_gate_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('publication_verifier_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('publication_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('publication_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('evidence_pack_verifier_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('evidence_pack_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('evidence_pack_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('outcome_memory_runtime_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('outcome_memory_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('outcome_memory_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('outcome_memory_command_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('competitive_rubric_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('competitive_rubric_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('competitive_rubric_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('rival_replay_harness_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('rival_replay_command_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('rival_replay_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('command_surface_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertContains('unit_tests_present', collect($certification['checks'])->pluck('id')->all());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $certification['certification_hash']);
    }
}
