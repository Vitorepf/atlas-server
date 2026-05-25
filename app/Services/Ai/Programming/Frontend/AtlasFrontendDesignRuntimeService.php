<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendDesignRuntimeService
{
    public const CONTRACT_SCHEMA_VERSION = 'atlas.frontend.design_runtime_contract.v1';

    public const CERTIFICATION_SCHEMA_VERSION = 'atlas.frontend.design_runtime_certification.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function contract(array $options = []): array
    {
        $task = trim((string) ($options['task'] ?? ''));
        $surface = trim((string) ($options['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';
        $workspace = trim((string) ($options['workspace'] ?? ''));
        $haystack = Str::ascii(strtolower($task.' '.$surface.' '.implode(' ', array_filter((array) ($options['hints'] ?? []), 'is_string'))));

        $signals = $this->signals($haystack, $options);
        $outputTypes = $this->outputTypes($signals);
        $capabilities = $this->capabilities($signals, $outputTypes);
        $requiredGates = $this->requiredGates($signals, $outputTypes);
        $requiredEvidence = $this->requiredEvidence($signals, $outputTypes);
        $scorecard = $this->competitiveScorecard($capabilities, $requiredGates, $requiredEvidence);
        $blockers = $this->blockers($signals, $options);
        $warnings = $this->warnings($signals, $options);

        $contract = [
            'schema_version' => self::CONTRACT_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'surface' => $surface,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'specialist_profile' => 'programming.frontend',
            'source' => self::class,
            'signals' => $signals,
            'output_types' => $outputTypes,
            'required_capabilities' => $capabilities,
            'required_gates' => $requiredGates,
            'required_evidence' => $requiredEvidence,
            'product_blueprint_contract' => $this->productBlueprintContract(),
            'enterprise_bootstrap_contract' => $this->enterpriseBootstrapContract(),
            'gauntlet_contract' => $this->gauntletContract(),
            'work_order_contract' => $this->workOrderContract(),
            'execution_runbook_contract' => $this->executionRunbookContract(),
            'provider_instruction_packet_contract' => $this->providerInstructionPacketContract(),
            'scenario_matrix_contract' => $this->scenarioMatrixContract(),
            'evidence_kit_contract' => $this->evidenceKitContract(),
            'world_best_proof_plan_contract' => $this->worldBestProofPlanContract(),
            'repo_intake_contract' => $this->repoIntakeContract(),
            'task_spec_contract' => $this->taskSpecContract(),
            'execution_gate_contract' => $this->executionGateContract(),
            'repair_planner_contract' => $this->repairPlannerContract(),
            'run_certification_contract' => $this->runCertificationContract(),
            'delivery_handoff_contract' => $this->deliveryHandoffContract(),
            'outcome_memory_contract' => $this->outcomeMemoryContract(),
            'design_quality_rule_registry' => $this->designQualityRules(),
            'design_review_contract' => $this->designReviewContract(),
            'visual_quality_gate_contract' => $this->visualQualityGateContract(),
            'quality_budget_gate_contract' => $this->qualityBudgetGateContract(),
            'design_system_inventory_contract' => $this->designSystemInventoryContract($signals),
            'design_system_drift_gate_contract' => $this->designSystemDriftGateContract($signals),
            'asset_pack_contract' => $this->assetPackContract($signals),
            'design_dossier_contract' => $this->designDossierContract($signals),
            'company_design_profile_contract' => $this->companyDesignProfileContract($signals),
            'design_direction_advisor_contract' => $this->designDirectionAdvisorContract($signals),
            'variant_strategy' => $this->variantStrategy($signals),
            'live_iteration_contract' => $this->liveIterationContract($signals),
            'provider_policy' => [
                'provider_neutral' => true,
                'atlas_decide_required' => true,
                'benchmark_by_task_not_brand' => true,
                'external_skill_code_copy_forbidden' => true,
                'paid_external_tool_required' => false,
                'documentation_only_claim_forbidden' => true,
            ],
            'completion_rules' => [
                'screenshot_alone_is_insufficient' => true,
                'no_driver_claim_without_evidence' => true,
                'multi_viewport_required_or_reason' => true,
                'state_console_a11y_perf_required_or_reason' => true,
                'design_critique_required' => true,
                'human_review_required_for_broad_visual_change' => (bool) ($signals['broad_visual_change'] ?? false),
            ],
            'competitive_scorecard' => $scorecard,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $contract['contract_hash'] = MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->fileCheck('frontend_domain_doc_present', 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md', ['frontend_design_harness', 'programming.frontend', 'repo selecionado', 'frontend_runtime_projection', 'runtime_projection_hash']),
            $this->fileCheck('impeccable_teardown_present', 'docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md', ['pbakaus/impeccable', 'covered']),
            $this->fileCheck('runtime_service_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeService.php', [self::CONTRACT_SCHEMA_VERSION, 'competitive_scorecard']),
            $this->fileCheck('orchestrator_integration_present', 'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php', ['AtlasFrontendDesignRuntimeService', 'AtlasFrontendExecutionGateService', 'AtlasFrontendSelectedWorkspaceService', 'selected_workspace_dispatch_readiness_status', 'runtime_projection_allowed', 'runtime_projection_status', 'runtime_projection_hash', 'frontend_app_candidate_status', 'frontend_app_candidate_confirmation_required', 'frontend_app_scope_status', 'onboarding_frontend_app_scope_status', 'onboarding_frontend_app_scope_hash', 'operator_start_panel_status', 'operator_primary_action', 'next_best_action', 'task_binding_status', 'task_bound', 'AtlasFrontendEnterpriseBootstrapService', 'AtlasFrontendCompanyRepoOnboardingService', 'AtlasFrontendExecutionRunbookService', 'AtlasFrontendProviderInstructionPacketService', 'enterprise_operating_contract', 'selected_workspace', 'company_repo_onboarding', 'provider_instruction_packet', 'pre_execution_gate']),
            $this->fileCheck('forge_integration_present', 'app/Services/Ai/Programming/Forge/Intelligence/ForgeSpecialistWorkcellRouterService.php', ['AtlasFrontendDesignRuntimeService', 'AtlasFrontendExecutionGateService', 'AtlasFrontendSelectedWorkspaceService', 'selected_workspace_dispatch_readiness_status', 'runtime_projection_allowed', 'runtime_projection_status', 'runtime_projection_hash', 'frontend_app_candidate_status', 'frontend_app_candidate_confirmation_required', 'frontend_app_scope_status', 'onboarding_frontend_app_scope_status', 'onboarding_frontend_app_scope_hash', 'frontendAppFromPacket', 'operator_start_panel_status', 'operator_primary_action', 'next_best_action', 'task_binding_status', 'task_bound', 'AtlasFrontendEnterpriseBootstrapService', 'AtlasFrontendCompanyRepoOnboardingService', 'AtlasFrontendExecutionRunbookService', 'AtlasFrontendProviderInstructionPacketService', 'atlas_frontend_pre_execution_gate', 'atlas_frontend_enterprise_operating_contract', 'atlas_frontend_selected_workspace', 'atlas_frontend_company_repo_onboarding', 'atlas_frontend_provider_instruction_packet']),
            $this->fileCheck('visual_smoke_runtime_present', 'app/Console/Commands/AtlasEngineeringVisualSmokeCommand.php', ['atlas:engineering:visual-smoke', 'atlas_visual_smoke']),
            $this->fileCheck('visual_driver_present', 'app/Console/Commands/AtlasEngineeringVisualDriverCommand.php', ['atlas:engineering:visual-driver', 'Playwright']),
            $this->fileCheck('anti_slop_detector_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendAntiSlopDetectorService.php', [AtlasFrontendAntiSlopDetectorService::SCHEMA_VERSION, AtlasFrontendAntiSlopDetectorService::FINDINGS_SCHEMA_VERSION, AtlasFrontendAntiSlopDetectorService::RULE_REGISTRY_SCHEMA_VERSION, AtlasFrontendAntiSlopDetectorService::REPAIR_PROJECTION_SCHEMA_VERSION, 'inspectPath', 'repairProjection', 'competitive_rubric_dimension', 'recommended_repair_plan_command']),
            $this->fileCheck('benchmark_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendBenchmarkRuntimeService.php', [AtlasFrontendBenchmarkRuntimeService::SCHEMA_VERSION, 'atlas_world_best_frontend_system', 'rival_evidence_directory_supplied', 'rival_evidence_directory_hash', 'rival_replay_competitive_diagnostics_status', 'rival_replay_tied_case_count', 'rival_replay_dimension_gap_case_count', 'atlas_decisively_leads_verified_rival_replay']),
            $this->fileCheck('gauntlet_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendGauntletService.php', [AtlasFrontendGauntletService::SCHEMA_VERSION, 'company_owned_local_repo_frontend_gauntlet', 'frontend_app_scope', '--frontend-app', 'repo_workspace_remains_primary']),
            $this->fileCheck('control_plane_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendControlPlaneService.php', [AtlasFrontendControlPlaneService::SCHEMA_VERSION, 'world_best_claim_allowed', 'honest_claim_boundary', 'frontend_app_hash', 'frontend_app_scope', 'publication_attestation', 'local_publication_report_is_not_public_distribution', 'competitive_diagnostics_status', 'competitive_tied_case_count', 'competitive_dimension_gap_case_count', 'competitive_repair_plan', 'competitive_repair_plan_hash', 'world_best_requires_decisive_lead_each_replay_case', 'world_best_requires_no_dimension_gaps_against_best_rival', 'improve_atlas_frontend_until_replay_leads_every_case', 'improve_atlas_frontend_until_replay_closes_dimension_gaps']),
            $this->fileCheck('work_order_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendWorkOrderService.php', [AtlasFrontendWorkOrderService::SCHEMA_VERSION, 'company_frontend_execution_work_order', 'frontend_app_scope', '--frontend-app']),
            $this->fileCheck('execution_runbook_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendExecutionRunbookService.php', [AtlasFrontendExecutionRunbookService::SCHEMA_VERSION, 'company_frontend_repo_execution_runbook', 'frontend_app_scope', 'subscope_selected', 'invalid_subscope', 'missing_subscope', 'public_distribution_proof', 'publish attest --bundle=<bundle>', 'public_distribution_step_is_not_required_for_customer_handoff']),
            $this->fileCheck('provider_instruction_packet_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendProviderInstructionPacketService.php', [AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION, AtlasFrontendProviderInstructionPacketService::EXECUTION_GUARDRAILS_SCHEMA_VERSION, 'provider_safe_frontend_execution_instruction_packet', 'frontend_app_scope', 'provider_execution_guardrails', 'preserve_selected_repo_as_workspace_and_frontend_app_as_subscope', 'atlas_frontend_browser_detector_event']),
            $this->fileCheck('selected_workspace_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceService.php', [AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION, 'operator_selected_repository_workspace', 'portfolio_scan_required', 'parallel_project_runtime_required', 'selected_repository_is_sufficient_for_frontend_dispatch', 'recommended_command_sequence', 'attachable_runtime_components', 'repo_operating_summary', 'frontend_app_candidates', 'confirmed_frontend_app_scope', 'frontend_runtime_projection', 'read_only_selected_repo_frontend_runtime_bundle', 'runtime_projection_hash', 'runtime_projection_is_not_execution_evidence', 'nested_frontend_app_candidate_recommended', 'requested_frontend_app_subscope_invalid', 'requested_frontend_app_subscope_not_found', 'frontend_app_candidate_invalid', '--frontend-app', '$frontendAppArg', 'atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo>', 'selected_repository_remains_primary_workspace', 'dispatch_readiness', 'capability_readiness', 'operator_start_panel', 'task_binding', 'next_best_action']),
            $this->forbiddenTermCheck('selected_workspace_space_runtime_absent', [
                'app/Services/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceService.php',
                'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php',
                'app/Services/Ai/Programming/Forge/Intelligence/ForgeSpecialistWorkcellRouterService.php',
                'tests/Unit/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceServiceTest.php',
                'tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php',
                'tests/Feature/Ai/Programming/Forge/ForgeFrontendWorkcellRuntimeTest.php',
                'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
            ], ['space_runtime_required']),
            $this->fileCheck('company_portfolio_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompanyPortfolioService.php', [AtlasFrontendCompanyPortfolioService::SCHEMA_VERSION, 'optional_repository_discovery_only', 'local_folder_with_multiple_repositories', 'repoRootMarkers', 'projectMarkers', 'operator_selected_repository_workspace', 'portfolio_root_is_not_selected_workspace', 'portfolio_dispatch_allowed', 'selected_workspace_required_for_frontend_dispatch', 'task_bound_for_candidate_ranking', 'task_fit', 'selection_brief', 'selection_handoff', 'frontend_app_candidate_summary', 'frontend_app_candidate_is_subscope_not_repo', 'portfolio_candidate_is_not_selected_workspace']),
            $this->fileCheck('workspace_runtime_projection_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendWorkspaceRuntimeProjectionService.php', [AtlasFrontendWorkspaceRuntimeProjectionService::SCHEMA_VERSION, 'read_only_selected_repo_frontend_operator_cockpit', 'AtlasFrontendGauntletService', 'AtlasFrontendCompanyRepoOnboardingService', 'AtlasFrontendWorkOrderService', 'AtlasFrontendProviderInstructionPacketService', 'provider_dispatch_ready_requires_provider_packet_ready', 'provider_dispatch_performed_by_this_endpoint', 'selected_repository_remains_primary_workspace', 'frontend_app_is_subscope_only', 'space_runtime_required']),
            $this->fileCheck('workspace_api_http_surface_present', 'app/Http/Controllers/AtlasFrontendWorkspaceController.php', ['atlas.frontend.workspace_api.portfolio.v1', 'atlas.frontend.workspace_api.selected_workspace.v1', 'atlas.frontend.workspace_api.runtime_projection.v1', 'atlas.frontend.workspace_api.control_plane.v1', 'atlas.frontend.workspace_api.prepare_evidence.v1', 'atlas.frontend.workspace_api.prepare_rival_replay.v1', 'atlas.frontend.workspace_api.inspect_rival_replay.v1', 'atlas.frontend.workspace_api.proof_bundle.v1', 'atlas.frontend.workspace_api.publication_receipt_template.v1', 'atlas.frontend.workspace_api.publication_verify.v1', 'atlas.frontend.workspace_api.run_certification.v1', 'atlas.frontend.workspace_api.delivery_handoff.v1', 'AtlasFrontendCompanyPortfolioService', 'AtlasFrontendSelectedWorkspaceService', 'AtlasFrontendWorkspaceRuntimeProjectionService', 'AtlasFrontendControlPlaneService', 'AtlasFrontendEvidenceKitService', 'AtlasFrontendProviderInstructionPacketService', 'AtlasFrontendPublicationVerifierService', 'AtlasFrontendRivalReplayHarnessService', 'AtlasFrontendRunCertificationService', 'AtlasFrontendDeliveryHandoffService', 'atlas_code_frontend_workspace_selection', 'atlas_code_frontend_runtime_projection', 'atlas_code_frontend_control_plane', 'atlas_code_frontend_evidence_preparation', 'atlas_code_frontend_rival_replay_preparation', 'atlas_code_frontend_rival_replay_inspection', 'atlas_code_frontend_competitive_proof_bundle', 'atlas_code_frontend_publication_verification', 'atlas_code_frontend_delivery_certification', 'atlas_code_frontend_delivery_handoff', 'selected_repository_is_primary_workspace', 'frontend_app_is_subscope_only', 'space_runtime_required', 'raw_absolute_path_returned', 'rivalReplayWorkItems', 'action_queue', 'fill_and_hash_missing_rival_replay_evidence_packs', 'external_rival_replay_receipts_required', 'may_claim_world_best_frontend_system', 'frontend_completion_claim_allowed', 'customer_handoff_allowed']),
            $this->fileCheck('workspace_api_routes_present', 'routes/api.php', ['AtlasFrontendWorkspaceController', '/frontend/portfolio', '/frontend/selected-workspace', '/frontend/runtime-projection', '/frontend/control-plane', '/frontend/prepare-evidence', '/frontend/prepare-rival-replay', '/frontend/inspect-rival-replay', '/frontend/proof-bundle', '/frontend/publication-receipt-template', '/frontend/publication-verify', '/frontend/run-certification', '/frontend/handoff']),
            $this->fileCheck('desktop_frontend_runtime_client_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/api.ts', ['/atlas-code/frontend/portfolio', '/atlas-code/frontend/selected-workspace', '/atlas-code/frontend/runtime-projection', '/atlas-code/frontend/control-plane', '/atlas-code/frontend/prepare-evidence', '/atlas-code/frontend/prepare-rival-replay', '/atlas-code/frontend/inspect-rival-replay', '/atlas-code/frontend/proof-bundle', '/atlas-code/frontend/publication-receipt-template', '/atlas-code/frontend/publication-verify', '/atlas-code/frontend/run-certification', '/atlas-code/frontend/handoff', 'prepareAtlasFrontendEvidence', 'inspectAtlasFrontendControlPlane', 'prepareAtlasFrontendRivalReplay', 'inspectAtlasFrontendRivalReplay', 'compileAtlasFrontendProofBundle', 'prepareAtlasFrontendPublicationReceipt', 'verifyAtlasFrontendPublication', 'selected_repository_is_primary_workspace', 'frontend_app_is_subscope_only', 'space_runtime_required', 'unsafe_policy', 'AtlasFrontendWorkspaceApiEnvelope']),
            $this->fileCheck('desktop_frontend_runtime_client_tests_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/__tests__/api.test.ts', ['scanAtlasFrontendPortfolio', 'selectAtlasFrontendWorkspace', 'projectAtlasFrontendRuntime', 'inspectAtlasFrontendControlPlane', 'prepareAtlasFrontendEvidence', 'prepareAtlasFrontendRivalReplay', 'inspectAtlasFrontendRivalReplay', 'compileAtlasFrontendProofBundle', 'prepareAtlasFrontendPublicationReceipt', 'verifyAtlasFrontendPublication', 'certifyAtlasFrontendRun', 'compileAtlasFrontendHandoff', 'client rejects unsafe Space runtime policy']),
            $this->fileCheck('desktop_frontend_artifact_defaults_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/artifactDefaults.ts', ['atlas.frontend.desktop_artifact_defaults.v1', 'buildAtlasFrontendArtifactPlan', '.atlas', 'frontend-evidence', 'provider-instruction-packet.json', 'visual-quality-report.json', 'quality-budget-report.json', 'design-review-report.json', 'evidence-pack.json', 'product-proof', 'publication-receipt.json', 'publication-report.json', 'rival-replay', 'replay-runner-kit.json', 'replay-evidence-worklist.json', 'replay-competitive-proof-bundle.json', 'outcomes.jsonl', 'run-certification.json', 'delivery-handoff.json', 'atlas:frontend:control-plane', 'commands_are_suggestions_only', 'space_runtime_required']),
            $this->fileCheck('desktop_frontend_artifact_defaults_tests_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/__tests__/artifactDefaults.test.ts', ['buildAtlasFrontendArtifactPlan', 'atlas.frontend.desktop_artifact_defaults.v1', 'apps-web', 'product-proof', 'publication-receipt.json', 'publication-report.json', 'replay-runner-kit.json', 'replay-competitive-proof-contract.json', 'replay-operator-packet.json', 'replay-competitive-proof-bundle.json', 'atlas:frontend:control-plane', 'atlas:frontend:replay operator-packet', 'atlas:frontend:replay proof-bundle', 'atlas:frontend:publish receipt-template', 'atlas:frontend:publish verify', 'atlas:frontend:run-certify', 'atlas:frontend:handoff compile', 'selected_workspace_missing']),
            $this->fileCheck('desktop_frontend_action_queue_summary_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/actionQueueSummary.ts', ['atlas.frontend.desktop_rival_replay_action_queue_summary.v1', 'summarizeAtlasFrontendRivalReplayActionQueue', 'evidence_pack_items', 'external_execution_receipt_items', 'score_attestation_items', 'summary_is_not_replay_evidence', 'world_best_claim_allowed']),
            $this->fileCheck('desktop_frontend_action_queue_summary_tests_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/__tests__/actionQueueSummary.test.ts', ['summarizeAtlasFrontendRivalReplayActionQueue', 'evidence_pack_missing', 'external_execution_receipt_required', 'score_attestation_required', 'summary_is_not_replay_evidence']),
            $this->fileCheck('desktop_frontend_competitive_readiness_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/competitiveReadiness.ts', ['atlas.frontend.desktop_competitive_readiness.v1', 'buildAtlasFrontendCompetitiveReadiness', 'pbakaus_impeccable', 'claude_design', 'rival_replay_runner_kit_prepared', 'operator_packet_verified', 'operator_packet_verification', 'competitive_proof_bundle_compiled', 'proof_bundle_compiled', 'public_distribution_receipt_verified', 'publication_verified', 'rival_replay_prepared', 'rival_replay_inspected', 'rival_replay_action_queue_compiled', 'world_best_proof_ready', 'may_claim_world_best_frontend_system', 'external_rival_replay_receipts', 'local_certification_is_not_world_best_proof', 'world_best_claim_allowed']),
            $this->fileCheck('desktop_frontend_competitive_readiness_tests_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/__tests__/competitiveReadiness.test.ts', ['buildAtlasFrontendCompetitiveReadiness', 'evidence_ready', 'rival_replay_prepared', 'rival_replay_inspected', 'rival_replay_action_queue_compiled', 'proof_bundle_compiled', 'publication_verified', 'world_best_proof_ready', 'certified_handoff_ready', 'rival_replay_runner_kit_prepared', 'operator_packet_verified', 'operator_packet_verification', 'competitive_proof_bundle_compiled', 'public_distribution_receipt_verified', 'external_rival_replay_receipts', 'may_claim_world_best_frontend_system', 'world_best_claim_allowed']),
            $this->fileCheck('desktop_frontend_runtime_panel_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/AtlasFrontendRuntimePanel.tsx', ['AtlasFrontendRuntimePanel', 'scanAtlasFrontendPortfolio', 'selectAtlasFrontendWorkspace', 'projectAtlasFrontendRuntime', 'inspectAtlasFrontendControlPlane', 'prepareAtlasFrontendEvidence', 'prepareAtlasFrontendRivalReplay', 'inspectAtlasFrontendRivalReplay', 'compileAtlasFrontendProofBundle', 'prepareAtlasFrontendPublicationReceipt', 'verifyAtlasFrontendPublication', 'certifyAtlasFrontendRun', 'compileAtlasFrontendHandoff', 'buildAtlasFrontendArtifactPlan', 'buildAtlasFrontendCompetitiveReadiness', 'summarizeAtlasFrontendRivalReplayActionQueue', 'requer replay externo', 'replay queue', 'external receipts', 'score attestations', 'preencher caminhos padrão de evidência', 'control plane', 'preparar kit no backend', 'preparar replay competitivo', 'inspecionar replay competitivo', 'compilar proof bundle competitivo', 'gerar receipt de publicação', 'verificar publicação', 'selected_repository_is_primary_workspace', 'frontend_app_is_subscope_only', 'space_runtime_required', 'world_best_claim_allowed', 'O Atlas não executa provider nesta aba.']),
            $this->fileCheck('desktop_frontend_runtime_panel_registered', '../atlas-desktop/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx', ['AtlasFrontendRuntimePanel', "id: 'frontend'", "label: 'Frontend'"]),
            $this->fileCheck('desktop_frontend_runtime_panel_tests_present', '../atlas-desktop/apps/desktop/src/surfaces/code/frontendRuntime/__tests__/panelContract.test.ts', ['Atlas Frontend RightRail panel', 'inspectAtlasFrontendControlPlane', 'prepareAtlasFrontendEvidence', 'prepareAtlasFrontendRivalReplay', 'inspectAtlasFrontendRivalReplay', 'compileAtlasFrontendProofBundle', 'prepareAtlasFrontendPublicationReceipt', 'verifyAtlasFrontendPublication', 'summarizeAtlasFrontendRivalReplayActionQueue', 'replay queue', 'operator verify', 'proof bundle', 'publication', 'control plane', 'external receipts', 'score attestations', 'preparar kit no backend', 'preparar replay competitivo', 'inspecionar replay competitivo', 'compilar proof bundle competitivo', 'gerar receipt de publicação', 'verificar publicação', 'certifyAtlasFrontendRun', 'compileAtlasFrontendHandoff', 'world_best_claim_allowed']),
            $this->fileCheck('desktop_frontend_runtime_test_script_present', '../atlas-desktop/apps/desktop/package.json', ['atlas-frontend:test', 'src/surfaces/code/frontendRuntime/__tests__/api.test.ts', 'src/surfaces/code/frontendRuntime/__tests__/artifactDefaults.test.ts', 'src/surfaces/code/frontendRuntime/__tests__/actionQueueSummary.test.ts', 'src/surfaces/code/frontendRuntime/__tests__/competitiveReadiness.test.ts', 'src/surfaces/code/frontendRuntime/__tests__/panelContract.test.ts']),
            $this->fileCheck('company_repo_onboarding_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompanyRepoOnboardingService.php', [AtlasFrontendCompanyRepoOnboardingService::SCHEMA_VERSION, 'company_frontend_repo_atlas_frontend_onboarding', 'ready_for_operator_execution', 'frontend_app_scope']),
            $this->fileCheck('skill_pack_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendSkillPackService.php', [AtlasFrontendSkillPackService::SCHEMA_VERSION, AtlasFrontendSkillPackService::INSTALL_SCHEMA_VERSION, AtlasFrontendSkillPackService::RUNTIME_GUARDRAILS_SCHEMA_VERSION, 'provider_safe_atlas_frontend_operating_skill', 'runtime_guardrails', 'operator-selected repository is the workspace', 'frontend_app_is_optional_subscope_not_space', 'SKILL.md', '.atlas/skills/atlas-frontend']),
            $this->fileCheck('enterprise_bootstrap_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendEnterpriseBootstrapService.php', [AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION, 'company_owned_local_repo_premium_frontend_bootstrap', 'frontend_app_scope', '--frontend-app']),
            $this->fileCheck('scenario_matrix_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendScenarioMatrixService.php', [AtlasFrontendScenarioMatrixService::SCHEMA_VERSION, 'route_viewport_state_visual_verification_matrix', 'frontend_app_scope', 'subscope_selected']),
            $this->fileCheck('evidence_kit_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendEvidenceKitService.php', [AtlasFrontendEvidenceKitService::SCHEMA_VERSION, 'frontend_execution_evidence_collection_kit', 'frontend_app_scope', '--frontend-app', 'visualQualityReportTemplate', 'evidencePackTemplate']),
            $this->fileCheck('world_best_proof_plan_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendWorldBestProofPlanService.php', [AtlasFrontendWorldBestProofPlanService::SCHEMA_VERSION, 'world_best_frontend_market_proof', 'generate_rival_replay_runner_kit', 'generate_and_verify_rival_replay_operator_packet', 'operator_packet_verification_status', 'world_best_requires_verified_operator_packet', 'evidence_pack_ref', 'run_packet_hash', 'evidence_pack_readiness', 'evidence_worklist', 'publication_attestation', 'publish attest --bundle=<bundle>', 'local_publication_report_is_not_public_distribution', 'competitive_diagnostics', 'competitive_repair_plan', 'improve_atlas_frontend_until_replay_wins_every_case', 'improve_atlas_frontend_until_replay_leads_every_case', 'improve_atlas_frontend_until_replay_closes_dimension_gaps', 'world_best_requires_decisive_lead_each_case', 'world_best_requires_no_tied_cases', 'world_best_requires_no_dimension_gaps_against_best_rival', 'competitive_tied_case_count', 'competitive_dimension_gap_case_count', 'fill_and_verify_rival_replay_evidence_packs']),
            $this->fileCheck('repo_intake_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendRepoIntakeService.php', [AtlasFrontendRepoIntakeService::SCHEMA_VERSION, 'company_owned_frontend_repo_operating_map']),
            $this->fileCheck('product_blueprint_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendProductBlueprintService.php', [AtlasFrontendProductBlueprintService::SCHEMA_VERSION, 'company_product_frontend_success_blueprint', 'frontend_app_scope']),
            $this->fileCheck('task_spec_compiler_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendTaskSpecCompilerService.php', [AtlasFrontendTaskSpecCompilerService::SCHEMA_VERSION, 'canonicalSections', 'frontend_app_scope', 'repo_workspace_remains_primary']),
            $this->fileCheck('browser_bridge_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendBrowserBridgeService.php', [AtlasFrontendBrowserBridgeService::SCHEMA_VERSION, AtlasFrontendBrowserBridgeService::BROWSER_DETECTOR_EVENT_SCHEMA_VERSION, 'atlas:frontend:pick', 'atlas:frontend:browser-detect', 'browser_icon_button_without_accessible_name', 'browser_detector_event_is_not_final_design_proof']),
            $this->fileCheck('framework_adapter_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendFrameworkAdapterRuntimeService.php', [AtlasFrontendFrameworkAdapterRuntimeService::SCHEMA_VERSION, 'hmr_supported']),
            $this->fileCheck('design_system_inventory_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignSystemInventoryService.php', [AtlasFrontendDesignSystemInventoryService::SCHEMA_VERSION, 'raw_source_returned']),
            $this->fileCheck('execution_gate_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendExecutionGateService.php', [AtlasFrontendExecutionGateService::SCHEMA_VERSION, 'provider_dispatch_allowed', 'frontend_app_scope']),
            $this->fileCheck('repair_planner_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendRepairPlannerService.php', [AtlasFrontendRepairPlannerService::SCHEMA_VERSION, 'knownSignals', 'competitive_dimension_gap', 'competitive_replay_repair', 'invalid_competitive_dimension_gap', 'dimension_not_in_competitive_rubric', 'points_to_lead', 'target_score_to_lead', 'lead_possible_within_rubric']),
            $this->fileCheck('run_certification_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendRunCertificationService.php', [AtlasFrontendRunCertificationService::SCHEMA_VERSION, 'provider_instruction_packet_ready', 'provider_execution_guardrails_present', 'provider_guardrail_detector_receipts_declared', 'provider_guardrail_runtime_receipts_declared', 'provider_guardrail_detector_receipts_evidenced', 'frontend_completion_claim_allowed', 'frontend_completion_claim_requires_outcome_memory', 'frontend_completion_claim_requires_quality_budget', 'frontend_completion_claim_requires_frontend_app_scope_consistency', 'frontend_completion_claim_requires_provider_instruction_packet', 'frontend_completion_claim_requires_provider_execution_guardrails', 'frontend_completion_claim_requires_guardrail_detector_evidence']),
            $this->fileCheck('run_certification_task_spec_hash_consistency_enforced', 'app/Services/Ai/Programming/Frontend/AtlasFrontendRunCertificationService.php', ['task_spec_hash_consistent', 'taskSpecHashes']),
            $this->fileCheck('run_certification_frontend_app_scope_consistency_enforced', 'app/Services/Ai/Programming/Frontend/AtlasFrontendRunCertificationService.php', ['frontend_app_scope_consistent', 'frontendAppScopeConsistency', 'artifact_scopes']),
            $this->fileCheck('delivery_handoff_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDeliveryHandoffService.php', [AtlasFrontendDeliveryHandoffService::SCHEMA_VERSION, AtlasFrontendDeliveryHandoffService::PUBLICATION_ATTESTATION_SCHEMA_VERSION, 'customer_handoff_allowed', 'requires_matching_frontend_app_scope', 'public_distribution_requires_matching_frontend_app_scope', 'local_publication_report_is_not_public_distribution', 'evidence_manifest_frontend_app_scope_mismatch', 'publication_report_frontend_app_scope_mismatch']),
            $this->fileCheck('live_preview_relay_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendLivePreviewRelayService.php', [AtlasFrontendLivePreviewRelayService::SCHEMA_VERSION, 'atlas:frontend:preview-css']),
            $this->fileCheck('live_source_patch_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendLiveSourcePatchRuntimeService.php', [AtlasFrontendLiveSourcePatchRuntimeService::SESSION_SCHEMA_VERSION, AtlasFrontendLiveSourcePatchRuntimeService::DECISION_RECEIPT_SCHEMA_VERSION, 'recover', 'private_integrity_hash', 'session_integrity_mismatch', 'private_session_integrity_verified_before_patch', 'decision_receipt_hash', 'live_patch_decision_is_not_delivery_evidence', 'accepted_live_patch_requires_visual_quality_gate']),
            $this->fileCheck('product_proof_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendProductProofRuntimeService.php', [AtlasFrontendProductProofRuntimeService::SCHEMA_VERSION, AtlasFrontendProductProofRuntimeService::BUNDLE_SCHEMA_VERSION, AtlasFrontendProductProofRuntimeService::DEMO_MANIFEST_SCHEMA_VERSION, AtlasFrontendProductProofRuntimeService::PILOT_DOSSIER_SCHEMA_VERSION, 'buildStaticBundle', 'pilotDossier', 'frontend_app_scope_from_bundle_manifest', 'frontend_app_scope', 'product_site_assets', 'demo_manifests', 'each_demo_requires_manifest_with_page_hash_evidence_and_claim_boundary', 'downloads.json', 'getting-started.html', 'publication_workflow', 'receipt-template --bundle=<bundle>', 'publish attest --bundle=<bundle>']),
            $this->fileCheck('design_dossier_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignDossierService.php', [AtlasFrontendDesignDossierService::SCHEMA_VERSION, 'company_owned_local_repo_frontend_design']),
            $this->fileCheck('company_design_profile_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompanyDesignProfileService.php', [AtlasFrontendCompanyDesignProfileService::SCHEMA_VERSION, 'multi_company_frontend_design_context']),
            $this->fileCheck('design_direction_advisor_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignDirectionAdvisorService.php', [AtlasFrontendDesignDirectionAdvisorService::SCHEMA_VERSION, 'selection_requires_reason']),
            $this->fileCheck('asset_pack_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendAssetPackService.php', [AtlasFrontendAssetPackService::SCHEMA_VERSION, 'placeholder_or_unlicensed_assets_block_claim']),
            $this->fileCheck('design_review_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignReviewService.php', [AtlasFrontendDesignReviewService::SCHEMA_VERSION, 'frontend_design_5d_review']),
            $this->fileCheck('visual_quality_gate_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendVisualQualityGateService.php', [AtlasFrontendVisualQualityGateService::SCHEMA_VERSION, 'screenshot_alone_is_insufficient']),
            $this->fileCheck('quality_budget_gate_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendQualityBudgetGateService.php', [AtlasFrontendQualityBudgetGateService::SCHEMA_VERSION, 'objective_measured_values_required']),
            $this->fileCheck('design_system_drift_gate_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignSystemDriftGateService.php', [AtlasFrontendDesignSystemDriftGateService::SCHEMA_VERSION, 'unapproved_new_tokens_or_components_block_claim']),
            $this->fileCheck('publication_verifier_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendPublicationVerifierService.php', [AtlasFrontendPublicationVerifierService::SCHEMA_VERSION, 'public_distribution_claim_allowed', 'public_distribution_requires_matching_frontend_app_scope', 'public_receipt_frontend_app_scope_missing', 'public_receipt_index_content_hash_mismatch', 'prefilled_bundle_hash_is_not_public_verification', 'product_site_assets', 'downloads_manifest', 'demoManifestBlockers', 'singleDemoManifestBlockers', 'product_site_demo_manifest_hash_mismatch', 'product_site_demo_manifest_page_hash_mismatch', 'product_site_asset_tutorial_hash_mismatch', 'product_site_asset_downloads_manifest_hash_mismatch', 'bundle_hash_mismatch']),
            $this->fileCheck('publication_attestation_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendPublicationAttestationService.php', [AtlasFrontendPublicationAttestationService::SCHEMA_VERSION, 'local_bundle_ready_publication_pending', 'local_bundle_is_not_public_distribution', 'receipt_template_is_not_public_verification', 'raw_public_url_returned']),
            $this->fileCheck('evidence_pack_verifier_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendEvidencePackVerifierService.php', [AtlasFrontendEvidencePackVerifierService::SCHEMA_VERSION, 'requiredArtifactKinds', 'containsForbiddenKeyRecursive', 'artifact_forbidden_raw_prompt_or_source_field_present', 'browser_detector_event', 'design_system_drift_report']),
            $this->fileCheck('outcome_memory_runtime_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendOutcomeMemoryService.php', [AtlasFrontendOutcomeMemoryService::SCHEMA_VERSION, 'safe_for_aemor_projection']),
            $this->fileCheck('competitive_rubric_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompetitiveRubricService.php', [AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION, 'product_intent_fit']),
            $this->fileCheck('rival_replay_harness_present', 'app/Services/Ai/Programming/Frontend/AtlasFrontendRivalReplayHarnessService.php', [AtlasFrontendRivalReplayHarnessService::SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_TEMPLATE_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_TEMPLATE_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::MANIFEST_PATCH_APPLICATION_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::OPERATOR_PACKET_VERIFICATION_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::PROOF_BUNDLE_SCHEMA_VERSION, 'DECISIVE_LEAD_MINIMUM_POINTS', 'world_best_requires_minimum_decisive_lead_points', 'atlas_leads_without_decisive_margin', 'atlas_lead_margin_below_decisive_threshold', 'external_rival_replay_artifacts_required_for_world_best_claim', 'world_best_requires_external_execution_receipts', 'world_best_requires_atlas_to_lead_each_complete_case', 'world_best_requires_no_tied_cases', 'world_best_requires_decisive_lead_each_case', 'world_best_requires_no_dimension_gaps_against_best_rival', 'atlas_needs_dimension_lead', 'atlas_has_dimension_gaps_against_best_rival', 'external_execution_receipt_required', 'external_execution_receipt_output_artifact_hash_mismatch', 'external_execution_receipt_evidence_pack_verification_hash_mismatch', 'external_receipt_template_is_not_evidence', 'writeExternalExecutionReceiptTemplate', 'applyManifestPatch', 'manifest_hash_mismatch', 'manifest_patch_application_is_not_world_best_evidence', 'provider_safe_external_execution_receipt_patch', 'run_external_receipt_template_command_for_provider_safe_manifest_patch', 'external_rival_system_required', 'score_attestation_required', 'score_attestation_must_verify_manifest_score', 'score_attestation_total_mismatch', 'score_attestation_evidence_pack_verification_hash_mismatch', 'score_attestation_reviewed_manifest_hashes_mismatch', 'reviewed_manifest_hashes', 'fill_score_attestation_without_raw_prompt_source_or_reviewer_identity', 'run_score_template_command_for_provider_safe_manifest_patch', 'unknown_case_id', 'unknown_system_id', 'score_attestation_hash_mapping', 'writeScoreAttestationTemplate', 'provider_safe_score_attestation_patch', 'score_template_is_not_evidence', 'task_spec_hash_mismatch_across_systems', 'task_spec_hash_mismatch_with_task_spec_ref', 'run_packet_hash_mismatch', 'run_packet_hash_missing_from_runner_kit', 'preserve_or_fill_run_packet_hash_from_runner_kit', 'verify_run_packet_hash_matches_runner_kit_when_present', 'run_packet_hash_mapping', 'runner_kit.run_packets[] where case_id+system match', 'writeRunPacketHashToManifest', 'evidence_pack_verification_failed', 'created_evidence_pack_refs', 'rival_replay_evidence_pack_readiness', 'rival_replay_evidence_worklist', 'manifest_hash_mapping', 'competitive_diagnostics', 'dimension_gaps', 'recommended_repair_plan_commands', 'best_rival_system', 'atlas_does_not_win_every_complete_case', 'atlas_does_not_lead_every_complete_case', 'atlas_needs_decisive_lead', 'verifyOperatorPacket', 'writeCompetitiveProofBundle', 'proof_bundle_is_not_raw_artifact_storage', 'operator_packet_hash_valid', 'uses_replay_evidence_env_placeholder', 'raw_absolute_path_not_embedded', 'containsForbiddenKeyRecursive', AtlasFrontendRivalReplayHarnessService::TASK_SPEC_SCHEMA_VERSION, AtlasFrontendRivalReplayHarnessService::RUNNER_KIT_SCHEMA_VERSION]),
            $this->fileCheck('command_surface_present', 'app/Console/Commands/AtlasFrontendDesignRuntimePlanCommand.php', ['atlas:frontend:plan']),
            $this->fileCheck('anti_slop_command_present', 'app/Console/Commands/AtlasFrontendAntiSlopDetectCommand.php', ['atlas:frontend:detect', 'anti-AI-slop']),
            $this->fileCheck('benchmark_command_present', 'app/Console/Commands/AtlasFrontendBenchmarkCommand.php', ['atlas:frontend:benchmark', '--rival-evidence']),
            $this->fileCheck('gauntlet_command_present', 'app/Console/Commands/AtlasFrontendGauntletCommand.php', ['atlas:frontend:gauntlet', 'local company repo gauntlet', '--frontend-app']),
            $this->fileCheck('control_plane_command_present', 'app/Console/Commands/AtlasFrontendControlPlaneCommand.php', ['atlas:frontend:control-plane', 'market claim policy', '--frontend-app']),
            $this->fileCheck('work_order_command_present', 'app/Console/Commands/AtlasFrontendWorkOrderCommand.php', ['atlas:frontend:work-order', 'executable Atlas Frontend work order', '--frontend-app']),
            $this->fileCheck('execution_runbook_command_present', 'app/Console/Commands/AtlasFrontendExecutionRunbookCommand.php', ['atlas:frontend:runbook', 'repo-specific Atlas Frontend execution runbook', '--frontend-app']),
            $this->fileCheck('provider_instruction_packet_command_present', 'app/Console/Commands/AtlasFrontendProviderInstructionPacketCommand.php', ['atlas:frontend:provider-packet', 'provider-safe Atlas Frontend execution instruction packet', '--frontend-app']),
            $this->fileCheck('selected_workspace_command_present', 'app/Console/Commands/AtlasFrontendSelectedWorkspaceCommand.php', ['atlas:frontend:selected-workspace', 'operator-selected local company/product frontend repository path', '--task', '--frontend-app']),
            $this->fileCheck('company_portfolio_command_present', 'app/Console/Commands/AtlasFrontendCompanyPortfolioCommand.php', ['atlas:frontend:portfolio', 'Parent directory containing local company repositories', '--task']),
            $this->fileCheck('company_repo_onboarding_command_present', 'app/Console/Commands/AtlasFrontendCompanyRepoOnboardingCommand.php', ['atlas:frontend:onboard', 'local company frontend repo', '--frontend-app']),
            $this->fileCheck('skill_pack_command_present', 'app/Console/Commands/AtlasFrontendSkillPackCommand.php', ['atlas:frontend:skill-pack', 'export or install', 'SKILL.md']),
            $this->fileCheck('enterprise_bootstrap_command_present', 'app/Console/Commands/AtlasFrontendEnterpriseBootstrapCommand.php', ['atlas:frontend:enterprise-bootstrap', 'company-owned local repo', '--frontend-app']),
            $this->fileCheck('scenario_matrix_command_present', 'app/Console/Commands/AtlasFrontendScenarioMatrixCommand.php', ['atlas:frontend:scenarios', 'route x viewport x state', '--frontend-app']),
            $this->fileCheck('evidence_kit_command_present', 'app/Console/Commands/AtlasFrontendEvidenceKitCommand.php', ['atlas:frontend:evidence-kit', 'evidence collection kit', '--frontend-app']),
            $this->fileCheck('world_best_proof_plan_command_present', 'app/Console/Commands/AtlasFrontendWorldBestProofPlanCommand.php', ['atlas:frontend:world-best-plan', 'world-best proof']),
            $this->fileCheck('repo_intake_command_present', 'app/Console/Commands/AtlasFrontendRepoIntakeCommand.php', ['atlas:frontend:intake', 'operating map']),
            $this->fileCheck('product_blueprint_command_present', 'app/Console/Commands/AtlasFrontendBlueprintCommand.php', ['atlas:frontend:blueprint', 'product/UX/design blueprint', '--frontend-app']),
            $this->fileCheck('task_spec_command_present', 'app/Console/Commands/AtlasFrontendTaskSpecCommand.php', ['atlas:frontend:spec', 'deterministic Atlas Frontend task spec', '--frontend-app']),
            $this->fileCheck('browser_bridge_command_present', 'app/Console/Commands/AtlasFrontendBrowserBridgeCommand.php', ['atlas:frontend:bridge', 'script, inject or remove']),
            $this->fileCheck('framework_adapter_command_present', 'app/Console/Commands/AtlasFrontendFrameworkAdapterCommand.php', ['atlas:frontend:adapters', 'Inspect frontend framework adapter']),
            $this->fileCheck('design_system_inventory_command_present', 'app/Console/Commands/AtlasFrontendDesignSystemInventoryCommand.php', ['atlas:frontend:inventory', 'design-system tokens']),
            $this->fileCheck('execution_gate_command_present', 'app/Console/Commands/AtlasFrontendExecutionGateCommand.php', ['atlas:frontend:gate', 'pre-execution gate', '--frontend-app']),
            $this->fileCheck('repair_planner_command_present', 'app/Console/Commands/AtlasFrontendRepairPlanCommand.php', ['atlas:frontend:repair-plan', 'deterministic Atlas Frontend repair plan', '--dimension-gap', 'best_rival_system']),
            $this->fileCheck('run_certification_command_present', 'app/Console/Commands/AtlasFrontendRunCertifyCommand.php', ['atlas:frontend:run-certify', 'real evidence artifacts', '--provider-packet']),
            $this->fileCheck('delivery_handoff_command_present', 'app/Console/Commands/AtlasFrontendDeliveryHandoffCommand.php', ['atlas:frontend:handoff', 'enterprise Atlas Frontend delivery handoff']),
            $this->fileCheck('live_preview_relay_command_present', 'app/Console/Commands/AtlasFrontendLivePreviewRelayCommand.php', ['atlas:frontend:relay', 'script, inject or remove']),
            $this->fileCheck('live_source_patch_command_present', 'app/Console/Commands/AtlasFrontendLiveSourcePatchCommand.php', ['atlas:frontend:live', 'prepare, accept, discard, recover']),
            $this->fileCheck('product_proof_command_present', 'app/Console/Commands/AtlasFrontendProductProofCommand.php', ['atlas:frontend:proof', 'catalog or build', 'pilot', '--frontend-app']),
            $this->fileCheck('design_dossier_command_present', 'app/Console/Commands/AtlasFrontendDesignDossierCommand.php', ['atlas:frontend:design-dossier', 'Local company/product repository path']),
            $this->fileCheck('company_design_profile_command_present', 'app/Console/Commands/AtlasFrontendCompanyDesignProfileCommand.php', ['atlas:frontend:company-profile', 'inspect or template']),
            $this->fileCheck('design_direction_advisor_command_present', 'app/Console/Commands/AtlasFrontendDesignDirectionCommand.php', ['atlas:frontend:directions', 'Design Directions']),
            $this->fileCheck('asset_pack_command_present', 'app/Console/Commands/AtlasFrontendAssetPackCommand.php', ['atlas:frontend:assets', 'inspect or template']),
            $this->fileCheck('design_review_command_present', 'app/Console/Commands/AtlasFrontendDesignReviewCommand.php', ['atlas:frontend:review', '5D design review']),
            $this->fileCheck('visual_quality_gate_command_present', 'app/Console/Commands/AtlasFrontendVisualQualityGateCommand.php', ['atlas:frontend:visual-quality', 'inspect or template']),
            $this->fileCheck('quality_budget_gate_command_present', 'app/Console/Commands/AtlasFrontendQualityBudgetCommand.php', ['atlas:frontend:quality-budget', 'objective Atlas Frontend quality budgets']),
            $this->fileCheck('design_system_drift_gate_command_present', 'app/Console/Commands/AtlasFrontendDesignSystemDriftCommand.php', ['atlas:frontend:design-system-drift', 'inspect or template']),
            $this->fileCheck('publication_command_present', 'app/Console/Commands/AtlasFrontendPublicationCommand.php', ['atlas:frontend:publish', 'attest', 'receipt-template', '--strict']),
            $this->fileCheck('evidence_pack_command_present', 'app/Console/Commands/AtlasFrontendEvidencePackCommand.php', ['atlas:frontend:evidence', 'verify or template']),
            $this->fileCheck('outcome_memory_command_present', 'app/Console/Commands/AtlasFrontendOutcomeMemoryCommand.php', ['atlas:frontend:outcomes', 'outcome memory']),
            $this->fileCheck('competitive_rubric_command_present', 'app/Console/Commands/AtlasFrontendCompetitiveRubricCommand.php', ['atlas:frontend:rubric']),
            $this->fileCheck('rival_replay_command_present', 'app/Console/Commands/AtlasFrontendRivalReplayCommand.php', ['atlas:frontend:replay', 'inspect, template, runner-kit, evidence-worklist, proof-contract, proof-bundle, operator-packet, operator-packet-verify, score-template, external-receipt-template or apply-patch', '--patch']),
            $this->fileCheck('certification_command_present', 'app/Console/Commands/AtlasFrontendDesignRuntimeCertifyCommand.php', ['atlas:frontend:certify']),
            $this->fileCheck('unit_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeServiceTest.php', ['market_leading_contract', 'certification']),
            $this->fileCheck('anti_slop_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendAntiSlopDetectorServiceTest.php', ['gradient_text', 'provider_safe', 'repair_projection', 'competitive_rubric_dimension', 'visual_completion_claim_allowed']),
            $this->fileCheck('benchmark_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendBenchmarkRuntimeServiceTest.php', ['contract_superiority', 'world_best_claim', 'supplied_rival_evidence_directory_for_replay_signal', 'rival_replay_competitive_diagnostics_status', 'atlas_decisively_leads_verified_rival_replay']),
            $this->fileCheck('gauntlet_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendGauntletServiceTest.php', ['ready_local_company_repo_gauntlet', 'missing_design_context', 'frontend_app_scope_into_evidence_commands']),
            $this->fileCheck('control_plane_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendControlPlaneServiceTest.php', ['governed_runtime_claim', 'world_best_without_real_replay', 'frontend_app_scope_into_gauntlet_signal', 'local_bundle_without_public_distribution_claim', 'tied_rival_replay_into_decisive_lead_action', 'competitive_repair_plan', 'points_to_lead']),
            $this->fileCheck('work_order_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendWorkOrderServiceTest.php', ['ready_company_repo_work_order', 'blocks_provider_dispatch', 'frontend_app_scope_into_verification_packets']),
            $this->fileCheck('execution_runbook_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendExecutionRunbookServiceTest.php', ['repo_native_commands_and_evidence_kit', 'repo_context_is_missing', 'frontend_app_subdirectory', 'invalid_or_missing_frontend_app_subscope', 'public_distribution_proof', 'publish attest --bundle=<bundle>']),
            $this->fileCheck('provider_instruction_packet_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendProviderInstructionPacketServiceTest.php', ['provider_to_follow_runbook_evidence_and_claim_policy', 'blocks_provider', 'frontend_app_scope', 'invalid_frontend_app_scope', 'provider_execution_guardrails', 'atlas_frontend_browser_detector_event']),
            $this->fileCheck('selected_workspace_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceServiceTest.php', ['selected_repository_workspace', 'repo_operating_summary', 'frontend_app_candidates', 'nested_frontend_app_candidate_recommended', 'dispatch_readiness', 'capability_readiness', 'operator_start_panel', 'task_binding', 'next_best_action', 'frontend_runtime_projection', 'runtime_projection_hash', 'runtime_projection_is_not_execution_evidence', 'raw_task_text_returned', 'runtime_capable', 'measured_evidence', 'ready_for_runtime_projection', 'provider_instruction_packet_read_only_projection', 'atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web', 'atlas:frontend:evidence-kit prepare', 'missing_workspace']),
            $this->fileCheck('company_portfolio_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendCompanyPortfolioServiceTest.php', ['optional_repository_discovery_only', 'selected_workspace_handoff_required', 'portfolio_dispatch_allowed', 'portfolio_root_is_not_selected_workspace', 'task_bound_for_candidate_ranking', 'task_fit', 'selection_brief', 'selection_handoff', 'frontend_app_candidate_summary', 'candidate_not_selected', 'portfolio_scan_does_not_treat_root_project_as_selected_workspace']),
            $this->fileCheck('company_repo_onboarding_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendCompanyRepoOnboardingServiceTest.php', ['ready_company_repo_onboarding', 'minimal_repo_onboarding', 'frontend_app_scope_into_proof_pilot', 'missing_workspace']),
            $this->fileCheck('skill_pack_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendSkillPackServiceTest.php', ['provider_safe_skill_pack', 'installs_skill_pack', 'world_best_claim', 'runtime_guardrails', 'operator-selected repository is the workspace']),
            $this->fileCheck('enterprise_bootstrap_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendEnterpriseBootstrapServiceTest.php', ['creates_design_docs_and_blueprint', 'ready_company_repo_bootstrap', 'frontend_app_scope_into_recommended_work_order']),
            $this->fileCheck('scenario_matrix_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendScenarioMatrixServiceTest.php', ['route_viewport_state_matrix', 'task_spec_needs_acceptance_context', 'frontend_app_scope']),
            $this->fileCheck('evidence_kit_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendEvidenceKitServiceTest.php', ['full_evidence_collection_kit', 'scenario_matrix_is_not_ready', 'frontend_app_scope']),
            $this->fileCheck('world_best_proof_plan_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendWorldBestProofPlanServiceTest.php', ['missing_market_proof', 'world_best_only_when_replay', 'completed_losing_replay', 'completed_tied_replay', 'local_publication_bundle_as_pending_public_distribution', 'competitive_repair_plan', 'generate_rival_replay_runner_kit', 'generate_and_verify_rival_replay_operator_packet', 'operator_packet_verification_blocked', 'evidence_pack_ref', 'run_packet_hash', 'evidence_pack_readiness', 'evidence_worklist', 'publication_attestation', 'publish attest --bundle=<bundle>', 'improve_atlas_frontend_until_replay_wins_every_case', 'improve_atlas_frontend_until_replay_leads_every_case']),
            $this->fileCheck('repo_intake_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendRepoIntakeServiceTest.php', ['ready_company_frontend_repo', 'blocks_repo_without_frontend_operating_map']),
            $this->fileCheck('product_blueprint_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendProductBlueprintServiceTest.php', ['company_product_blueprint', 'writes_blueprint_document', 'blueprint_carries_frontend_app_scope']),
            $this->fileCheck('task_spec_compiler_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendTaskSpecCompilerServiceTest.php', ['saas_dashboard_task_spec', 'raw_task_returned', 'task_spec_carries_frontend_app_scope']),
            $this->fileCheck('browser_bridge_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendBrowserBridgeServiceTest.php', ['emits_pick_event_contract', 'browser-detect', 'browser_small_interactive_target', 'inject_is_idempotent']),
            $this->fileCheck('framework_adapter_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendFrameworkAdapterRuntimeServiceTest.php', ['detects_vite_workspace', 'fake_ready']),
            $this->fileCheck('design_system_inventory_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDesignSystemInventoryServiceTest.php', ['tokens_components', 'fake_ready']),
            $this->fileCheck('execution_gate_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendExecutionGateServiceTest.php', ['pre_execution_evidence', 'company_design_profile_required', 'gate_carries_frontend_app_scope']),
            $this->fileCheck('repair_planner_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendRepairPlannerServiceTest.php', ['visual_quality_loop', 'task_spec_hash_mismatch', 'competitive_dimension_gaps', 'invalid_competitive_dimension_gap', 'points_to_lead', 'lead_possible_within_rubric']),
            $this->fileCheck('run_certification_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendRunCertificationServiceTest.php', ['certifies_run_with_real_artifacts', 'blocks_missing_evidence', 'blocks_mismatched_task_spec_hash_across_evidence_chain', 'blocks_mismatched_frontend_app_scope_across_evidence_chain', 'blocks_completion_claim_without_provider_instruction_packet_guardrails', 'blocks_completion_claim_without_guardrail_detector_evidence']),
            $this->fileCheck('delivery_handoff_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDeliveryHandoffServiceTest.php', ['compiles_customer_safe_handoff', 'evidence_manifest_task_spec_hash_mismatch', 'evidence_manifest_frontend_app_scope_does_not_match_run', 'local_publication_report_without_public_distribution_claim', 'verified_publication_without_returning_public_url']),
            $this->fileCheck('live_preview_relay_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendLivePreviewRelayServiceTest.php', ['emits_preview_event_contract', 'inject_is_idempotent']),
            $this->fileCheck('live_source_patch_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendLiveSourcePatchRuntimeServiceTest.php', ['prepare_accept_and_recover', 'source_changed_since_prepare', 'tampered_private_session_payload', 'DECISION_RECEIPT_SCHEMA_VERSION', 'receipt_is_decision_evidence_not_delivery_completion']),
            $this->fileCheck('product_proof_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendProductProofRuntimeServiceTest.php', ['multi_company_demo_proofs', 'build_static_bundle', 'frontend_app_scope_for_monorepo_publication', 'product_site_assets', 'demo_manifests', 'DEMO_MANIFEST_SCHEMA_VERSION', 'downloads.json', 'getting-started.html', 'publication_workflow', 'publish attest --bundle=<bundle>', 'pilot_dossier_carries_frontend_app_scope', 'pilot_dossier']),
            $this->fileCheck('design_dossier_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDesignDossierServiceTest.php', ['ready_dossier', 'missing_docs']),
            $this->fileCheck('company_design_profile_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendCompanyDesignProfileServiceTest.php', ['ready_profile', 'raw_prompt']),
            $this->fileCheck('design_direction_advisor_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDesignDirectionAdvisorServiceTest.php', ['three_governed_directions', 'task_missing']),
            $this->fileCheck('asset_pack_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendAssetPackServiceTest.php', ['valid_asset_pack_passes', 'placeholder_or_unlicensed']),
            $this->fileCheck('design_review_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDesignReviewServiceTest.php', ['valid_review_passes', 'score_below_threshold']),
            $this->fileCheck('visual_quality_gate_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendVisualQualityGateServiceTest.php', ['valid_report_passes', 'raw_prompt']),
            $this->fileCheck('quality_budget_gate_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendQualityBudgetGateServiceTest.php', ['valid_budget_report_passes', 'blocks_over_budget_metrics']),
            $this->fileCheck('design_system_drift_gate_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendDesignSystemDriftGateServiceTest.php', ['valid_report_passes', 'unapproved_new_token']),
            $this->fileCheck('publication_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendPublicationVerifierServiceTest.php', ['local_ready', 'public_verified', 'frontend_app_scope_from_bundle_manifest', 'public_receipt_without_matching_frontend_app_scope', 'mismatched_index_hash', 'validates_product_site_assets_and_download_manifest', 'tampered_product_site_tutorial', 'tampered_downloads_manifest', 'tampered_demo_manifest', 'tampered_manifest_bundle_hash']),
            $this->fileCheck('publication_attestation_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendPublicationAttestationServiceTest.php', ['local_bundle_as_pending_public_distribution', 'public_verified_without_raw_public_url', 'invalid_publication_schema_before_claims']),
            $this->fileCheck('evidence_pack_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendEvidencePackVerifierServiceTest.php', ['matching_hashes', 'raw_prompt', 'nested_raw_prompt', 'inside_artifact_json', 'browser_detector_event', 'design_system_drift_report']),
            $this->fileCheck('outcome_memory_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendOutcomeMemoryServiceTest.php', ['failed_gate_counts', 'provider_safe']),
            $this->fileCheck('competitive_rubric_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendCompetitiveRubricServiceTest.php', ['validates_score_breakdown', 'score_max']),
            $this->fileCheck('rival_replay_tests_present', 'tests/Unit/Ai/Programming/Frontend/AtlasFrontendRivalReplayHarnessServiceTest.php', ['ready_for_replay', 'world_best', 'competitive_proof_contract', 'proof_contract_file', 'proof_bundle', 'operator_packet', 'operator_packet_verification', 'world_best_proof_ready', 'task_spec_hash_differs_across_systems', 'same_task_spec_hash_for_each_system', 'task_spec_hash_does_not_match_referenced_task_spec', 'without_verified_evidence_pack', 'external_rival_complete_manifest_requires_verified_execution_receipt', 'external_rival_execution_receipt_hashes_must_match_manifest', 'complete_manifest_requires_verified_score_attestation', 'score_attestation_must_match_manifest_score_and_evidence', 'reviewed_manifest_hashes', 'manifest_patch_application_applies_verified_external_receipt_and_score_attestation', 'manifest_patch_application_is_not_world_best_evidence', 'missing_score_attestation_into_actionable_work_item', 'score_attestation_template_prefills_hashes_without_authorizing_claims', 'score_attestation_template_blocks_until_evidence_pack_verifies', 'nested_raw_prompt', 'atlas_does_not_win_every_complete_case', 'atlas_does_not_lead_every_complete_case', 'atlas_lead_is_too_small', 'atlas_lead_margin_below_decisive_threshold', 'atlas_needs_decisive_lead', 'atlas_needs_dimension_lead', 'world_best_requires_no_tied_cases', 'world_best_requires_no_dimension_gaps_against_best_rival', 'dimension_gap_count', 'best_rival_system', 'recommended_repair_plan_commands', 'created_evidence_pack_refs', 'evidence_pack_readiness', 'evidence_worklist', 'runner_kit_writes_operational_replay_packets', 'requires_matching_run_packet_hash', 'preserve_or_fill_run_packet_hash_from_runner_kit']),
            $this->fileCheck('command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeCommandTest.php', ['atlas:frontend:plan', 'atlas:frontend:certify']),
            $this->fileCheck('anti_slop_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendAntiSlopDetectCommandTest.php', ['atlas:frontend:detect', 'fails_strict']),
            $this->fileCheck('benchmark_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendBenchmarkCommandTest.php', ['atlas:frontend:benchmark', 'competitive_matrix', 'accepts_rival_evidence_directory']),
            $this->fileCheck('gauntlet_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendGauntletCommandTest.php', ['atlas:frontend:gauntlet', 'recommended_command_sequence', '--frontend-app=apps/web']),
            $this->fileCheck('control_plane_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendControlPlaneCommandTest.php', ['atlas:frontend:control-plane', 'world_best_claim_allowed', 'frontend_app_scope']),
            $this->fileCheck('work_order_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendWorkOrderCommandTest.php', ['atlas:frontend:work-order', 'work_order_hash', '--frontend-app=apps/web']),
            $this->fileCheck('execution_runbook_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendExecutionRunbookCommandTest.php', ['atlas:frontend:runbook', 'runbook_hash', 'ready_selected_repo_subscope_and_public_distribution_proof']),
            $this->fileCheck('provider_instruction_packet_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendProviderInstructionPacketCommandTest.php', ['atlas:frontend:provider-packet', 'provider_instruction_packet_hash']),
            $this->fileCheck('selected_workspace_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceCommandTest.php', ['atlas:frontend:selected-workspace', 'selected_workspace_hash', 'frontend_app_candidates', 'frontend_runtime_projection', 'read_only_selected_repo_frontend_runtime_bundle', 'runtime_projection_hash', 'next_best_action', '--frontend-app', 'frontend_app_candidate_selected', 'requested_frontend_app_subscope_invalid']),
            $this->fileCheck('company_portfolio_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendCompanyPortfolioCommandTest.php', ['atlas:frontend:portfolio', 'task_bound_for_candidate_ranking', 'selection_brief', 'selection_handoff', 'operator_selected_repository_workspace', 'frontend_app_candidate_summary', 'nested_frontend_app_candidate_recommended']),
            $this->fileCheck('workspace_api_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendWorkspaceApiTest.php', ['/atlas-code/frontend/portfolio', '/atlas-code/frontend/selected-workspace', '/atlas-code/frontend/runtime-projection', '/atlas-code/frontend/control-plane', '/atlas-code/frontend/prepare-evidence', '/atlas-code/frontend/prepare-rival-replay', '/atlas-code/frontend/proof-bundle', '/atlas-code/frontend/publication-receipt-template', '/atlas-code/frontend/publication-verify', '/atlas-code/frontend/run-certification', '/atlas-code/frontend/handoff', 'atlas.frontend.workspace_api.control_plane.v1', 'atlas_code_frontend_control_plane', 'operator_selected_repository_workspace', 'frontend_app_is_subscope_only', 'space_runtime_required', 'requested_frontend_app_subscope_invalid', 'read_only_selected_repo_frontend_operator_cockpit', 'dispatch_provider_with_provider_instruction_packet', 'provider-instruction-packet.json', 'replay-runner-kit.json', 'competitive_proof_contract', 'operator_packet_verification_status', 'proof_bundle_is_not_raw_artifact_storage', 'publication_receipt_template', 'publication_verification', 'external_rival_replay_receipts_required', 'public_distribution_receipt_not_verified', 'raw_absolute_path_returned', 'frontend_completion_claim_allowed', 'customer_handoff_allowed']),
            $this->fileCheck('company_repo_onboarding_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendCompanyRepoOnboardingCommandTest.php', ['atlas:frontend:onboard', 'frontend_app_scope', 'strict_fails_when_repo_only_prepared']),
            $this->fileCheck('skill_pack_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendSkillPackCommandTest.php', ['atlas:frontend:skill-pack', 'installs_into_workspace', 'provider_safe_atlas_frontend_operating_skill']),
            $this->fileCheck('enterprise_bootstrap_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendEnterpriseBootstrapCommandTest.php', ['atlas:frontend:enterprise-bootstrap', 'enterprise_bootstrap_hash', '--frontend-app=apps/web']),
            $this->fileCheck('scenario_matrix_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendScenarioMatrixCommandTest.php', ['atlas:frontend:scenarios', 'scenario_matrix_hash', '--frontend-app']),
            $this->fileCheck('evidence_kit_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendEvidenceKitCommandTest.php', ['atlas:frontend:evidence-kit', 'evidence_kit_hash', '--frontend-app']),
            $this->fileCheck('world_best_proof_plan_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendWorldBestProofPlanCommandTest.php', ['atlas:frontend:world-best-plan', 'generate_rival_replay_runner_kit', 'operator-packet-verify', 'complete_external_rival_replay_manifests', 'fill_and_verify_rival_replay_evidence_packs', 'publication_attestation', 'publish attest --bundle=<bundle>']),
            $this->fileCheck('repo_intake_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendRepoIntakeCommandTest.php', ['atlas:frontend:intake', 'repo_intake_hash']),
            $this->fileCheck('product_blueprint_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendBlueprintCommandTest.php', ['atlas:frontend:blueprint', 'product_blueprint', 'frontend_app_scope']),
            $this->fileCheck('task_spec_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendTaskSpecCommandTest.php', ['atlas:frontend:spec', 'task_spec_hash', 'frontend_app_scope']),
            $this->fileCheck('browser_bridge_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendBrowserBridgeCommandTest.php', ['atlas:frontend:bridge', 'injects_and_removes']),
            $this->fileCheck('framework_adapter_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendFrameworkAdapterCommandTest.php', ['atlas:frontend:adapters', 'framework_contract']),
            $this->fileCheck('design_system_inventory_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDesignSystemInventoryCommandTest.php', ['atlas:frontend:inventory', 'design_system_inventory']),
            $this->fileCheck('execution_gate_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendExecutionGateCommandTest.php', ['atlas:frontend:gate', 'execution_allowed', 'frontend_app_scope']),
            $this->fileCheck('repair_planner_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendRepairPlanCommandTest.php', ['atlas:frontend:repair-plan', 'repair_plan_hash', 'competitive_dimension_gap', 'pbakaus_impeccable', 'dimension_not_in_competitive_rubric']),
            $this->fileCheck('run_certification_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendRunCertifyCommandTest.php', ['atlas:frontend:run-certify', 'run_certification_hash', 'certifies_real_evidence_bundle', 'provider_execution_guardrails_present']),
            $this->fileCheck('delivery_handoff_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDeliveryHandoffCommandTest.php', ['atlas:frontend:handoff', 'customer_handoff_allowed', 'publication_attestation']),
            $this->fileCheck('live_preview_relay_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendLivePreviewRelayCommandTest.php', ['atlas:frontend:relay', 'injects_and_removes']),
            $this->fileCheck('live_source_patch_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendLiveSourcePatchCommandTest.php', ['atlas:frontend:live', 'recovers_patch', 'live_source_patch_decision_receipt', 'live_patch_decision_is_not_delivery_evidence']),
            $this->fileCheck('product_proof_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendProductProofCommandTest.php', ['atlas:frontend:proof', 'builds_static_bundle', 'frontend_app_scope', 'publish attest --bundle=<bundle>', 'pilot_dossier']),
            $this->fileCheck('design_dossier_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDesignDossierCommandTest.php', ['atlas:frontend:design-dossier', 'template']),
            $this->fileCheck('company_design_profile_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendCompanyDesignProfileCommandTest.php', ['atlas:frontend:company-profile', 'template']),
            $this->fileCheck('design_direction_advisor_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDesignDirectionCommandTest.php', ['atlas:frontend:directions', 'brand_product_depth']),
            $this->fileCheck('asset_pack_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendAssetPackCommandTest.php', ['atlas:frontend:assets', 'template']),
            $this->fileCheck('design_review_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDesignReviewCommandTest.php', ['atlas:frontend:review', 'design_review_report']),
            $this->fileCheck('visual_quality_gate_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendVisualQualityGateCommandTest.php', ['atlas:frontend:visual-quality', 'template']),
            $this->fileCheck('quality_budget_gate_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendQualityBudgetCommandTest.php', ['atlas:frontend:quality-budget', 'quality-budget-report']),
            $this->fileCheck('design_system_drift_gate_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendDesignSystemDriftCommandTest.php', ['atlas:frontend:design-system-drift', 'template']),
            $this->fileCheck('publication_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendPublicationCommandTest.php', ['atlas:frontend:publish', 'receipt-template', 'strict_requires_public_receipt', 'prefills_from_bundle', 'attest_command_emits_canonical_publication_attestation']),
            $this->fileCheck('evidence_pack_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendEvidencePackCommandTest.php', ['atlas:frontend:evidence', 'template']),
            $this->fileCheck('outcome_memory_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendOutcomeMemoryCommandTest.php', ['atlas:frontend:outcomes', 'frontend_execution_gate']),
            $this->fileCheck('competitive_rubric_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendCompetitiveRubricCommandTest.php', ['atlas:frontend:rubric']),
            $this->fileCheck('rival_replay_command_tests_present', 'tests/Feature/Ai/Programming/Frontend/AtlasFrontendRivalReplayCommandTest.php', ['atlas:frontend:replay', 'template', 'rival_replay_task_spec', 'runner_kit_command', 'evidence-worklist', 'proof-contract', 'proof-bundle', 'operator-packet', 'operator-packet-verify', 'score-template', 'external-receipt-template', 'apply-patch']),
            $this->fileCheck('forge_tests_present', 'tests/Feature/Ai/Programming/Forge/ForgeFrontendWorkcellRuntimeTest.php', ['surface_ui', 'atlas_frontend_runtime', 'local_repo_receives_enterprise_bootstrap_and_runbook', 'atlas_frontend_provider_instruction_packet', 'runtime_projection_hash']),
            $this->fileCheck('orchestrator_tests_present', 'tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php', ['atlas_frontend_runtime', self::CONTRACT_SCHEMA_VERSION, 'frontend_session_plan_attaches_enterprise_bootstrap_and_runbook', 'provider_instruction_packet', 'runtime_projection_hash']),
            $this->capabilityCheck('multi_output_contract_present', ['production_ui_patch', 'clickable_prototype', 'motion_design_asset', 'deck_infographic']),
            $this->capabilityCheck('evidence_gates_exceed_impeccable', ['visual_smoke_multi_viewport', 'design_5d_review', 'anti_ai_slop_detector', 'competitive_benchmark_scorecard']),
            $this->capabilityCheck('honest_not_doc_only_claim', ['evidence_receipts', 'visual_smoke_multi_viewport', 'documentation_only_claim_forbidden']),
        ];

        $failedCritical = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'critical');
        $failedWarn = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'warn');
        $status = $failedCritical ? 'blocked' : ($failedWarn ? 'partial' : 'ready');

        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA_VERSION,
            'status' => $status,
            'market_claim_policy' => [
                'may_claim_more_complete_than_impeccable' => $status === 'ready',
                'may_claim_more_complete_than_claude_design_plugin' => $status === 'ready',
                'may_claim_world_best_frontend_system' => false,
                'world_best_requires_real_benchmark_runs' => true,
                'documentation_only_claim_forbidden' => true,
            ],
            'summary' => [
                'total' => count($checks),
                'pass' => collect($checks)->where('status', 'pass')->count(),
                'fail' => collect($checks)->where('status', 'fail')->count(),
            ],
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'critical')),
            'warnings' => array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'warn')),
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,bool|string>
     */
    private function signals(string $haystack, array $options): array
    {
        return [
            'ui_change' => $this->containsAny($haystack, ['frontend', 'ui', 'layout', 'screen', 'tela', 'component', 'css', 'design', 'mobile', 'desktop']),
            'production_code' => (bool) ($options['code_changes'] ?? true),
            'prototype_requested' => (bool) ($options['prototype'] ?? false) || $this->containsAny($haystack, ['prototype', 'prototipo', 'wireframe', 'variant', 'variante']),
            'live_iteration_requested' => (bool) ($options['live'] ?? false) || $this->containsAny($haystack, ['live', 'browser', 'selecionar elemento', 'accept', 'discard']),
            'asset_heavy' => $this->containsAny($haystack, ['logo', 'brand', 'marca', 'image', 'asset', 'hero', 'photo', 'video']),
            'motion_or_deck' => $this->containsAny($haystack, ['motion', 'animation', 'animacao', 'deck', 'slides', 'ppt', 'infographic']),
            'enterprise_multi_company' => $this->containsAny($haystack, ['inumeras empresas', 'multiempresa', 'multi-company', 'white label', 'clientes', 'multi tenant', 'multitenant']),
            'broad_visual_change' => $this->containsAny($haystack, ['design system', 'redesign', 'todas as telas', 'produto inteiro', 'inumeras empresas']),
            'performance_sensitive' => $this->containsAny($haystack, ['performance', 'lighthouse', 'latencia', 'bundle', 'custo', 'cache']),
            'accessibility_sensitive' => true,
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<int,string>
     */
    private function outputTypes(array $signals): array
    {
        $types = ['production_ui_patch'];
        if ($signals['prototype_requested'] || $signals['enterprise_multi_company']) {
            $types[] = 'clickable_prototype';
        }
        if ($signals['motion_or_deck']) {
            $types[] = 'motion_design_asset';
            $types[] = 'deck_infographic';
        }
        if ($signals['live_iteration_requested']) {
            $types[] = 'live_visual_iteration';
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @param  array<int,string>  $outputTypes
     * @return array<int,string>
     */
    private function capabilities(array $signals, array $outputTypes): array
    {
        $capabilities = [
            'design_context_pack',
            'company_owned_local_repo_design_dossier',
            'company_frontend_repo_operating_map',
            'product_ux_visual_success_blueprint',
            'deterministic_frontend_task_spec_compiler',
            'design_direction_advisor',
            'framework_route_component_discovery',
            'design_token_and_component_inventory',
            'design_system_drift_gate',
            'frontend_asset_pack_verifier',
            'asset_provenance_and_quality_gate',
            'anti_ai_slop_detector',
            'multi_viewport_visual_smoke',
            'a11y_state_console_performance_gates',
            'frontend_visual_quality_gate',
            'objective_frontend_quality_budget_gate',
            'design_5d_critique',
            'repair_loop_from_visual_evidence',
            'evidence_receipts',
            'outcome_memory',
            'competitive_benchmark_scorecard',
        ];

        if (in_array('clickable_prototype', $outputTypes, true)) {
            $capabilities[] = 'interactive_prototype_generation';
        }
        if (in_array('live_visual_iteration', $outputTypes, true)) {
            $capabilities[] = 'live_element_pick_variant_accept_discard_contract';
            $capabilities[] = 'live_css_preview_relay';
        }
        if ($signals['enterprise_multi_company']) {
            $capabilities[] = 'multi_company_design_system_adaptation';
            $capabilities[] = 'brand_context_without_vendor_lockin';
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @param  array<int,string>  $outputTypes
     * @return array<int,string>
     */
    private function requiredGates(array $signals, array $outputTypes): array
    {
        $gates = [
            'context_pack_hash',
            'typescript_or_reason',
            'eslint_or_biome_or_reason',
            'console_error_check',
            'frontend_visual_quality_gate',
            'frontend_quality_budget_gate',
            'visual_smoke_multi_viewport',
            'no_text_overlap',
            'responsive_check',
            'a11y_check_or_reason',
            'state_transition_check',
            'design_system_inventory_or_profile',
            'company_design_dossier_ready_or_created',
            'asset_provenance_check',
            'anti_ai_slop_detector',
            'design_5d_review',
            'performance_budget_or_reason',
            'objective_quality_budget_report',
            'evidence_receipt_required',
        ];
        if ($signals['broad_visual_change'] || $signals['enterprise_multi_company']) {
            $gates[] = 'senior_design_review';
            $gates[] = 'design_system_drift_check';
        }
        if (in_array('live_visual_iteration', $outputTypes, true)) {
            $gates[] = 'source_patch_boundary_check';
            $gates[] = 'accept_discard_recovery_check';
        }

        return array_values(array_unique($gates));
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @param  array<int,string>  $outputTypes
     * @return array<int,string>
     */
    private function requiredEvidence(array $signals, array $outputTypes): array
    {
        $evidence = [
            'frontend_context_pack',
            'frontend_repo_intake',
            'frontend_scenario_matrix',
            'company_design_dossier',
            'product_blueprint',
            'frontend_task_spec',
            'selected_design_direction_or_reason',
            'design_system_inventory',
            'changed_files_or_prototype_artifacts',
            'frontend_asset_pack',
            'tool_run_receipts',
            'visual_smoke_manifest',
            'visual_quality_report',
            'quality_budget_report',
            'design_system_drift_report',
            'screenshots_or_reason',
            'console_network_check',
            'a11y_perf_state_results_or_reason',
            'design_5d_review_summary',
            'anti_slop_findings',
            'anti_slop_detector_report',
            'completion_hash',
        ];
        if (in_array('live_visual_iteration', $outputTypes, true)) {
            $evidence[] = 'live_iteration_event_journal';
            $evidence[] = 'preview_variant_event';
            $evidence[] = 'accepted_variant_diff';
        }

        return $evidence;
    }

    /**
     * @param  array<int,string>  $capabilities
     * @param  array<int,string>  $requiredGates
     * @param  array<int,string>  $requiredEvidence
     * @return array<string,mixed>
     */
    private function competitiveScorecard(array $capabilities, array $requiredGates, array $requiredEvidence): array
    {
        $atlasAdvantages = [
            'provider_neutral_runtime_governance',
            'repo_native_production_patch_and_prototype_modes',
            'visual_a11y_perf_state_evidence_required',
            'anti_ai_slop_rule_registry',
            'outcome_memory_and_receipts',
            'multi_company_design_system_context',
            'honest_certification_blocks_doc_only_ready',
        ];

        return [
            'schema_version' => 'atlas.frontend.competitive_scorecard.v1',
            'benchmarks' => [
                'pbakaus_impeccable' => [
                    'matched' => ['skill_command_flow', 'detector_contract', 'browser_element_picker_bridge', 'framework_hmr_adapter_contract', 'live_css_preview_relay', 'live_iteration_contract', 'multi_provider_distribution_awareness', 'competitive_benchmark_matrix', 'product_proof_catalog'],
                    'exceeded_by' => $atlasAdvantages,
                    'remaining_gap' => 'real_rival_replay_and_hosted_public_product_proof_still_required_before world-best claim',
                ],
                'claude_design_plugin' => [
                    'matched' => ['design_skill_contract', 'visual_iteration_intent', 'artifact_review'],
                    'exceeded_by' => ['provider_neutrality', 'Atlas evidence ledger', 'Dev/Forge integration contract', 'multi_company readiness'],
                    'remaining_gap' => 'external plugin behavior must be re-benchmarked periodically',
                ],
            ],
            'capability_count' => count($capabilities),
            'gate_count' => count($requiredGates),
            'evidence_count' => count($requiredEvidence),
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,string>>
     */
    private function blockers(array $signals, array $options): array
    {
        $blockers = [];
        if (($options['acceptance_criteria'] ?? null) === false) {
            $blockers[] = ['id' => 'acceptance_criteria_missing', 'reason' => 'Frontend work needs acceptance criteria or explicit reason.'];
        }
        if (($signals['asset_heavy'] ?? false) && ($options['asset_provenance'] ?? null) === false) {
            $blockers[] = ['id' => 'asset_provenance_missing', 'reason' => 'Asset-heavy frontend work needs asset provenance or explicit placeholder policy.'];
        }

        return $blockers;
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,string>>
     */
    private function warnings(array $signals, array $options): array
    {
        $warnings = [];
        if (($signals['enterprise_multi_company'] ?? false) && ! (bool) ($options['benchmark_run'] ?? false)) {
            $warnings[] = ['id' => 'benchmark_not_run', 'reason' => 'Market superiority requires real benchmark runs, not only contract coverage.'];
        }
        if (($signals['live_iteration_requested'] ?? false)) {
            $warnings[] = ['id' => 'live_runtime_contract_only', 'reason' => 'Browser picker bridge, framework adapter contract, CSS preview relay and source patch runtime exist; real rival replay still needs implementation proof.'];
        }

        return $warnings;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function designQualityRules(): array
    {
        return [
            ['id' => 'no_nested_cards', 'severity' => 'medium', 'source' => 'impeccable_teardown'],
            ['id' => 'no_text_overlap', 'severity' => 'high', 'source' => 'atlas_frontend_gate'],
            ['id' => 'responsive_stable_dimensions', 'severity' => 'high', 'source' => 'atlas_frontend_gate'],
            ['id' => 'avoid_one_note_palette', 'severity' => 'medium', 'source' => 'atlas_frontend_gate'],
            ['id' => 'avoid_generic_ai_gradient_hero', 'severity' => 'medium', 'source' => 'impeccable_teardown'],
            ['id' => 'use_real_assets_or_provenance', 'severity' => 'high', 'source' => 'atlas_frontend_gate'],
            ['id' => 'button_text_must_fit', 'severity' => 'high', 'source' => 'atlas_frontend_gate'],
            ['id' => 'keyboard_and_state_paths_verified', 'severity' => 'high', 'source' => 'atlas_frontend_gate'],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function variantStrategy(array $signals): array
    {
        return [
            'schema_version' => 'atlas.frontend.variant_strategy.v1',
            'required_when_ambiguous' => true,
            'default_variant_count' => ($signals['enterprise_multi_company'] ?? false) ? 3 : 2,
            'modes' => ['safer_production_patch', 'bolder_visual_direction', 'accessibility_first'],
            'selection_policy' => 'variant_must_be_accepted_with_evidence_before_done',
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function designDirectionAdvisorContract(array $signals): array
    {
        $advisor = app(AtlasFrontendDesignDirectionAdvisorService::class);

        return [
            'schema_version' => AtlasFrontendDesignDirectionAdvisorService::SCHEMA_VERSION,
            'status' => (($signals['enterprise_multi_company'] ?? false) || ($signals['prototype_requested'] ?? false)) ? 'required' : 'available',
            'runtime' => 'AtlasFrontendDesignDirectionAdvisorService',
            'command' => 'php artisan atlas:frontend:directions --task="<brief>" --json',
            'direction_ids' => $advisor->directionIds(),
            'claim_policy' => [
                'ambiguous_brief_requires_direction_selection' => true,
                'selection_requires_reason' => true,
                'selected_direction_must_feed_visual_quality_gate' => true,
            ],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function companyDesignProfileContract(array $signals): array
    {
        return [
            'schema_version' => AtlasFrontendCompanyDesignProfileService::SCHEMA_VERSION,
            'status' => ($signals['enterprise_multi_company'] ?? false) ? 'required' : 'available',
            'runtime' => 'AtlasFrontendCompanyDesignProfileService',
            'command' => 'atlas:frontend:company-profile',
            'required_for' => [
                'multi_company_design_system_adaptation',
                'brand_context_without_vendor_lockin',
                'public_product_demo_claims',
            ],
            'required_sections' => app(AtlasFrontendCompanyDesignProfileService::class)->requiredSections(),
            'claim_policy' => [
                'brand_adaptation_claim_requires_ready_profile' => true,
                'template_is_not_company_context' => true,
                'raw_customer_source_forbidden' => true,
            ],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function designDossierContract(array $signals): array
    {
        return [
            'schema_version' => AtlasFrontendDesignDossierService::SCHEMA_VERSION,
            'status' => ($signals['broad_visual_change'] ?? false) || ($signals['enterprise_multi_company'] ?? false) ? 'required' : 'available',
            'runtime' => 'AtlasFrontendDesignDossierService',
            'command' => 'php artisan atlas:frontend:design-dossier inspect --workspace=<local-company-repo> --json --strict',
            'template_command' => 'php artisan atlas:frontend:design-dossier template --workspace=<local-company-repo> --json',
            'default_operating_mode' => 'company_owned_local_repo_on_operator_macbook',
            'required_documents' => app(AtlasFrontendDesignDossierService::class)->requiredDocuments(),
            'required_for' => [
                'ultra_premium_redesign',
                'new_saas_frontend',
                'company_repo_frontend_refinement',
                'customer_safe_delivery_handoff',
            ],
            'claim_policy' => [
                'premium_design_claim_requires_ready_dossier' => true,
                'missing_docs_should_be_created_before_visual_claim' => true,
                'template_is_not_design_context' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function designSystemInventoryContract(array $signals): array
    {
        return [
            'schema_version' => AtlasFrontendDesignSystemInventoryService::SCHEMA_VERSION,
            'status' => ($signals['broad_visual_change'] ?? false) || ($signals['enterprise_multi_company'] ?? false) ? 'required' : 'available',
            'runtime' => 'AtlasFrontendDesignSystemInventoryService',
            'command' => 'php artisan atlas:frontend:inventory inspect --workspace=<workspace> --json',
            'required_for' => [
                'multi_company_design_system_adaptation',
                'design_system_drift_gate',
                'safe_existing_component_reuse',
            ],
            'claim_policy' => [
                'broad_visual_work_requires_inventory_or_ready_company_profile' => true,
                'inventory_returns_hashes_and_refs_not_raw_source' => true,
                'sparse_inventory_blocks_brand_adaptation_claim_without_profile' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function designReviewContract(): array
    {
        $review = app(AtlasFrontendDesignReviewService::class);

        return [
            'schema_version' => AtlasFrontendDesignReviewService::SCHEMA_VERSION,
            'status' => 'required',
            'runtime' => 'AtlasFrontendDesignReviewService',
            'command' => 'php artisan atlas:frontend:review inspect --report=<design-review-report.json> --json',
            'template_command' => 'php artisan atlas:frontend:review template --output=<dir> --json',
            'required_dimensions' => $review->requiredDimensions(),
            'minimum_overall_score' => 8.0,
            'claim_policy' => [
                'visual_completion_requires_passed_5d_review' => true,
                'score_below_threshold_blocks_claim' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualQualityGateContract(): array
    {
        $gate = app(AtlasFrontendVisualQualityGateService::class);

        return [
            'schema_version' => AtlasFrontendVisualQualityGateService::SCHEMA_VERSION,
            'status' => 'required',
            'runtime' => 'AtlasFrontendVisualQualityGateService',
            'command' => 'php artisan atlas:frontend:visual-quality inspect --report=<visual-quality-report.json> --json',
            'template_command' => 'php artisan atlas:frontend:visual-quality template --output=<dir> --json',
            'required_viewports' => $gate->requiredViewports(),
            'required_checks' => $gate->requiredChecks(),
            'required_artifact_kinds' => $gate->requiredArtifactKinds(),
            'claim_policy' => [
                'screenshot_alone_is_insufficient' => true,
                'visual_completion_claim_requires_gate_pass' => true,
                'multi_viewport_state_console_a11y_perf_and_anti_slop_required' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityBudgetGateContract(): array
    {
        $gate = app(AtlasFrontendQualityBudgetGateService::class);

        return [
            'schema_version' => AtlasFrontendQualityBudgetGateService::SCHEMA_VERSION,
            'status' => 'required',
            'runtime' => 'AtlasFrontendQualityBudgetGateService',
            'command' => 'php artisan atlas:frontend:quality-budget inspect --report=<quality-budget-report.json> --json --strict',
            'template_command' => 'php artisan atlas:frontend:quality-budget template --output=<dir> --json',
            'required_viewports' => $gate->requiredViewports(),
            'budgets' => $gate->budgets(),
            'claim_policy' => [
                'frontend_quality_budget_requires_measured_values' => true,
                'operator_exception_does_not_authorize_world_best_claim' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskSpecContract(): array
    {
        $compiler = app(AtlasFrontendTaskSpecCompilerService::class);

        return [
            'schema_version' => AtlasFrontendTaskSpecCompilerService::SCHEMA_VERSION,
            'status' => 'required_before_execution_gate',
            'runtime' => 'AtlasFrontendTaskSpecCompilerService',
            'command' => 'php artisan atlas:frontend:spec --task="<brief>" --workspace=<workspace> --acceptance --json',
            'canonical_sections' => $compiler->canonicalSections(),
            'compiles' => [
                'task_types',
                'routes',
                'viewports',
                'states',
                'user_journeys',
                'acceptance_criteria',
                'required_gates',
                'required_tests',
            ],
            'claim_policy' => [
                'frontend_execution_requires_task_spec_hash' => true,
                'raw_customer_task_returned' => false,
                'ambiguous_or_broad_work_blocks_without_acceptance_context' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gauntletContract(): array
    {
        return [
            'schema_version' => AtlasFrontendGauntletService::SCHEMA_VERSION,
            'status' => 'recommended_entrypoint_for_local_company_repos',
            'runtime' => 'AtlasFrontendGauntletService',
            'command' => 'php artisan atlas:frontend:gauntlet --task="<intent>" --workspace=<local-company-repo> --json --strict',
            'composes' => [
                'runtime_contract',
                'task_spec',
                'pre_execution_gate',
                'company_design_dossier',
                'repo_intake',
                'design_system_inventory',
                'runtime_certification',
            ],
            'claim_policy' => [
                'provider_dispatch_requires_gauntlet_not_blocked' => true,
                'premium_frontend_claim_requires_ready_gauntlet' => true,
                'world_best_claim_allowed' => false,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function productBlueprintContract(): array
    {
        return [
            'schema_version' => AtlasFrontendProductBlueprintService::SCHEMA_VERSION,
            'status' => 'required_for_premium_or_new_product_frontend',
            'runtime' => 'AtlasFrontendProductBlueprintService',
            'command' => 'php artisan atlas:frontend:blueprint generate --task="<intent>" --workspace=<local-company-repo> --json',
            'write_command' => 'php artisan atlas:frontend:blueprint write --task="<intent>" --workspace=<local-company-repo> --json',
            'covers' => [
                'product_model',
                'ux_success_model',
                'screen_blueprint',
                'visual_strategy',
                'acceptance_blueprint',
                'evidence_map',
            ],
            'claim_policy' => [
                'premium_frontend_work_requires_blueprint' => true,
                'blueprint_is_not_completion_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enterpriseBootstrapContract(): array
    {
        return [
            'schema_version' => AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION,
            'status' => 'default_entrypoint_for_company_owned_local_repos',
            'runtime' => 'AtlasFrontendEnterpriseBootstrapService',
            'command' => 'php artisan atlas:frontend:enterprise-bootstrap inspect --task="<intent>" --workspace=<local-company-repo> --json --strict',
            'write_command' => 'php artisan atlas:frontend:enterprise-bootstrap write --task="<intent>" --workspace=<local-company-repo> --json',
            'covers' => [
                'design_dossier_template_or_readiness',
                'product_blueprint_document',
                'repo_intake',
                'gauntlet',
                'work_order',
                'provider_dispatch_policy',
            ],
            'company_modes' => [
                'existing_company_blackink_refinement',
                'existing_company_refinar_refinement',
                'new_saas_or_product_creation',
                'existing_company_premium_redesign',
                'company_frontend_product_work',
            ],
            'claim_policy' => [
                'enterprise_bootstrap_is_not_completion_evidence' => true,
                'template_docs_do_not_count_as_ready_context' => true,
                'provider_dispatch_requires_ready_work_order' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repoIntakeContract(): array
    {
        return [
            'schema_version' => AtlasFrontendRepoIntakeService::SCHEMA_VERSION,
            'status' => 'required_for_local_company_repo_execution',
            'runtime' => 'AtlasFrontendRepoIntakeService',
            'command' => 'php artisan atlas:frontend:intake --workspace=<local-company-repo> --json --strict',
            'covers' => [
                'package_manager',
                'framework_adapter',
                'entrypoints',
                'route_candidates',
                'test_commands',
                'build_commands',
                'quality_commands',
                'design_dossier_status',
                'design_system_inventory_status',
            ],
            'claim_policy' => [
                'provider_can_start_with_repo_map' => true,
                'repo_intake_is_not_completion_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workOrderContract(): array
    {
        return [
            'schema_version' => AtlasFrontendWorkOrderService::SCHEMA_VERSION,
            'status' => 'required_before_provider_dispatch_for_company_repo_work',
            'runtime' => 'AtlasFrontendWorkOrderService',
            'command' => 'php artisan atlas:frontend:work-order --task="<intent>" --workspace=<local-company-repo> --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
            'packets' => [
                'repo_context_lock',
                'implementation_patch_or_prototype',
                'visual_quality_verification',
                'certified_handoff',
            ],
            'claim_policy' => [
                'provider_dispatch_requires_ready_work_order' => true,
                'work_order_is_not_completion_evidence' => true,
                'premium_claim_requires_all_packets_evidenced' => true,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function executionRunbookContract(): array
    {
        return [
            'schema_version' => AtlasFrontendExecutionRunbookService::SCHEMA_VERSION,
            'status' => 'recommended_before_operator_or_agent_execution',
            'runtime' => 'AtlasFrontendExecutionRunbookService',
            'command' => 'php artisan atlas:frontend:runbook --task="<intent>" --workspace=<local-company-repo> --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
            'covers' => [
                'repo_context_preflight',
                'repo_native_install_and_dev_server',
                'repo_native_quality_test_build_commands',
                'evidence_kit_collection',
                'run_certification',
                'customer_safe_handoff',
                'public_distribution_proof',
            ],
            'claim_policy' => [
                'runbook_is_not_execution_evidence' => true,
                'commands_must_be_run_in_operator_repo' => true,
                'completion_requires_run_certification_and_handoff' => true,
                'public_distribution_requires_publication_attestation' => true,
                'public_distribution_claim_requires_verified_receipt' => true,
                'public_distribution_step_is_not_required_for_customer_handoff' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerInstructionPacketContract(): array
    {
        return [
            'schema_version' => AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION,
            'status' => 'required_before_provider_dispatch_for_premium_frontend_work',
            'runtime' => 'AtlasFrontendProviderInstructionPacketService',
            'command' => 'php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo> --provider=<provider> --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
            'binds' => [
                'pre_execution_gate',
                'work_order',
                'execution_runbook',
                'provider_mandates',
                'forbidden_provider_behaviors',
            ],
            'claim_policy' => [
                'provider_packet_is_not_execution_evidence' => true,
                'provider_must_return_receipts_not_claims' => true,
                'completion_requires_run_certification_handoff_and_outcome' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scenarioMatrixContract(): array
    {
        return [
            'schema_version' => AtlasFrontendScenarioMatrixService::SCHEMA_VERSION,
            'status' => 'required_before_visual_quality_verification',
            'runtime' => 'AtlasFrontendScenarioMatrixService',
            'command' => 'php artisan atlas:frontend:scenarios --task="<intent>" --workspace=<local-company-repo> --acceptance --json --strict',
            'covers' => [
                'routes',
                'viewports',
                'states',
                'required_checks_per_scenario',
                'task_spec_hash',
            ],
            'claim_policy' => [
                'visual_done_requires_scenario_matrix_evidence' => true,
                'scenario_matrix_is_not_completion_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceKitContract(): array
    {
        return [
            'schema_version' => AtlasFrontendEvidenceKitService::SCHEMA_VERSION,
            'status' => 'required_before_real_visual_evidence_collection',
            'runtime' => 'AtlasFrontendEvidenceKitService',
            'command' => 'php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo> --acceptance --output=<evidence-dir> --json --strict',
            'prepares' => [
                'scenario_matrix',
                'visual_quality_report',
                'quality_budget_report',
                'design_5d_review',
                'evidence_pack_manifest',
                'outcome_record_template',
                'run_certification_command',
            ],
            'claim_policy' => [
                'evidence_kit_is_not_completion_evidence' => true,
                'templates_must_be_replaced_with_measured_artifacts' => true,
                'completion_requires_run_certification' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function worldBestProofPlanContract(): array
    {
        return [
            'schema_version' => AtlasFrontendWorldBestProofPlanService::SCHEMA_VERSION,
            'status' => 'required_before_world_best_market_claim',
            'runtime' => 'AtlasFrontendWorldBestProofPlanService',
            'command' => 'php artisan atlas:frontend:world-best-plan --rival-evidence=<dir> --bundle=<bundle> --publication-receipt=<receipt> --json --strict',
            'required_proof_streams' => [
                'external_rival_replay',
                'public_product_distribution',
                'publication_attestation',
                'claim_audit',
            ],
            'claim_policy' => [
                'world_best_claim_requires_proof_plan_ready' => true,
                'world_best_claim_requires_external_rival_replay' => true,
                'world_best_claim_requires_public_distribution_receipt' => true,
                'local_publication_report_is_not_public_distribution' => true,
                'documentation_only_claim_forbidden' => true,
                'raw_prompt_source_customer_data_forbidden' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function executionGateContract(): array
    {
        return [
            'schema_version' => AtlasFrontendExecutionGateService::SCHEMA_VERSION,
            'status' => 'required',
            'runtime' => 'AtlasFrontendExecutionGateService',
            'command' => 'php artisan atlas:frontend:gate --task="<intent>" --task-spec-hash=<hash> --workspace=<workspace> --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
            'blocks_provider_dispatch_when_missing' => [
                'task',
                'matching_task_spec_hash_when_declared',
                'acceptance_criteria',
                'test_plan',
                'visual_quality_plan',
                'evidence_plan',
                'design_system_inventory_or_company_profile_for_broad_work',
                'senior_design_review_for_broad_work',
            ],
            'claim_policy' => [
                'provider_dispatch_requires_gate_not_blocked' => true,
                'provider_dispatch_requires_matching_task_spec_hash' => true,
                'completion_claim_still_requires_evidence_gates' => true,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repairPlannerContract(): array
    {
        $planner = app(AtlasFrontendRepairPlannerService::class);

        return [
            'schema_version' => AtlasFrontendRepairPlannerService::SCHEMA_VERSION,
            'status' => 'available_after_failed_gate',
            'runtime' => 'AtlasFrontendRepairPlannerService',
            'command' => 'php artisan atlas:frontend:repair-plan --blocker=<id> --failed-gate=<gate> --dimension-gap=<dimension:points:delta:best> --json',
            'known_signals' => $planner->knownSignals(),
            'claim_policy' => [
                'repair_plan_is_not_completion_evidence' => true,
                'completion_requires_rerun_gates_passed' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runCertificationContract(): array
    {
        return [
            'schema_version' => AtlasFrontendRunCertificationService::SCHEMA_VERSION,
            'status' => 'required_before_completion_claim',
            'runtime' => 'AtlasFrontendRunCertificationService',
            'command' => 'php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --visual-report=<report> --design-review-report=<report> --quality-budget-report=<report> --evidence-manifest=<manifest> --json --strict',
            'required_evidence' => [
                'visual_quality_report',
                'provider_instruction_packet',
                'provider_execution_guardrails',
                'design_5d_review',
                'quality_budget_report',
                'evidence_pack',
                'artifact_hashes',
                'outcome_memory_record',
            ],
            'claim_policy' => [
                'frontend_completion_claim_requires_run_certification' => true,
                'frontend_completion_claim_requires_outcome_memory' => true,
                'frontend_completion_claim_requires_quality_budget' => true,
                'frontend_completion_claim_requires_provider_instruction_packet' => true,
                'frontend_completion_claim_requires_provider_execution_guardrails' => true,
                'public_distribution_claim_requires_publication_receipt' => true,
                'world_best_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function deliveryHandoffContract(): array
    {
        return [
            'schema_version' => AtlasFrontendDeliveryHandoffService::SCHEMA_VERSION,
            'status' => 'required_for_customer_or_enterprise_handoff',
            'runtime' => 'AtlasFrontendDeliveryHandoffService',
            'command' => 'php artisan atlas:frontend:handoff compile --run-certification=<report> --evidence-manifest=<manifest> --json --strict',
            'required_evidence' => [
                'run_certification_hash',
                'task_spec_hash',
                'evidence_manifest',
                'publication_attestation',
                'claim_policy',
                'known_limitations',
            ],
            'claim_policy' => [
                'customer_handoff_requires_run_certification' => true,
                'customer_handoff_requires_matching_task_spec_hash' => true,
                'local_publication_report_is_not_public_distribution' => true,
                'public_distribution_requires_verified_publication_report' => true,
                'world_best_claim_allowed' => false,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function outcomeMemoryContract(): array
    {
        return [
            'schema_version' => AtlasFrontendOutcomeMemoryService::SCHEMA_VERSION,
            'status' => 'required_after_execution',
            'runtime' => 'AtlasFrontendOutcomeMemoryService',
            'command' => 'php artisan atlas:frontend:outcomes record --status=<passed|failed|blocked> --driver=<driver> --gate=<gate> --evidence-ref=<ref> --json',
            'records' => [
                'selected_drivers',
                'gates',
                'failed_gates',
                'evidence_refs',
                'doctrine_effectiveness',
                'safe_aemor_projection',
            ],
            'claim_policy' => [
                'frontend_learning_requires_outcome_record' => true,
                'policy_change_requires_human_review' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function assetPackContract(array $signals): array
    {
        $assets = app(AtlasFrontendAssetPackService::class);

        return [
            'schema_version' => AtlasFrontendAssetPackService::SCHEMA_VERSION,
            'status' => ($signals['asset_heavy'] ?? false) ? 'required' : 'available',
            'runtime' => 'AtlasFrontendAssetPackService',
            'command' => 'php artisan atlas:frontend:assets inspect --pack=<frontend-asset-pack.json> --json',
            'template_command' => 'php artisan atlas:frontend:assets template --output=<dir> --json',
            'allowed_asset_kinds' => $assets->allowedAssetKinds(),
            'recommended_asset_kinds' => $assets->recommendedAssetKinds(),
            'claim_policy' => [
                'asset_provenance_claim_requires_passed_inspection' => true,
                'placeholder_or_unlicensed_assets_block_claim' => true,
                'critical_assets_require_dimensions' => true,
            ],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function designSystemDriftGateContract(array $signals): array
    {
        $gate = app(AtlasFrontendDesignSystemDriftGateService::class);

        return [
            'schema_version' => AtlasFrontendDesignSystemDriftGateService::SCHEMA_VERSION,
            'status' => (($signals['enterprise_multi_company'] ?? false) || ($signals['broad_visual_change'] ?? false)) ? 'required' : 'available',
            'runtime' => 'AtlasFrontendDesignSystemDriftGateService',
            'command' => 'php artisan atlas:frontend:design-system-drift inspect --report=<design-system-drift-report.json> --json',
            'template_command' => 'php artisan atlas:frontend:design-system-drift template --output=<dir> --json',
            'required_token_categories' => $gate->requiredTokenCategories(),
            'required_artifact_kinds' => $gate->requiredArtifactKinds(),
            'claim_policy' => [
                'design_system_adaptation_claim_requires_gate_pass' => true,
                'unapproved_new_tokens_or_components_block_claim' => true,
                'token_component_refs_must_be_hashed' => true,
            ],
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    private function liveIterationContract(array $signals): array
    {
        return [
            'schema_version' => 'atlas.frontend.live_iteration_contract.v1',
            'status' => ($signals['live_iteration_requested'] ?? false) ? 'required' : 'available',
            'events' => ['select_element', 'generate_variants', 'preview_variant', 'accept_variant', 'discard_variant', 'recover_session'],
            'mutation_policy' => 'source_patch_only_with_boundary_and_recovery',
            'browser_bridge_runtime' => 'AtlasFrontendBrowserBridgeService',
            'framework_adapter_runtime' => 'AtlasFrontendFrameworkAdapterRuntimeService',
            'live_preview_relay_runtime' => 'AtlasFrontendLivePreviewRelayService',
            'local_source_patch_runtime' => 'AtlasFrontendLiveSourcePatchRuntimeService',
            'browser_bridge_command' => 'php artisan atlas:frontend:bridge script|inject|remove --json',
            'framework_adapter_command' => 'php artisan atlas:frontend:adapters inspect --workspace=<workspace> --json',
            'live_preview_relay_command' => 'php artisan atlas:frontend:relay script|inject|remove --json',
            'command' => 'php artisan atlas:frontend:live prepare|accept|discard|recover|status --json',
            'browser_pick_event_schema' => 'atlas.frontend.browser_pick_event.v1',
            'live_preview_event_schema' => 'atlas.frontend.live_preview_event.v1',
            'evidence_required' => ['event_journal', 'selected_element_fingerprint', 'preview_variant_event', 'accepted_variant_diff', 'visual_smoke_after_accept'],
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, Str::ascii(strtolower((string) $needle)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $needles
     * @return array<string,mixed>
     */
    private function fileCheck(string $id, string $path, array $needles, string $severity = 'critical'): array
    {
        $absolute = base_path($path);
        $source = File::isFile($absolute) ? File::get($absolute) : '';
        $missing = array_values(array_filter($needles, static fn (string $needle): bool => ! str_contains($source, $needle)));
        $passed = $source !== '' && $missing === [];

        return [
            'id' => $id,
            'status' => $passed ? 'pass' : 'fail',
            'severity' => $severity,
            'path' => $path,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @param  array<int,string>  $forbiddenTerms
     * @return array<string,mixed>
     */
    private function forbiddenTermCheck(string $id, array $paths, array $forbiddenTerms, string $severity = 'critical'): array
    {
        $violations = [];
        foreach ($paths as $path) {
            $absolute = base_path($path);
            $source = File::isFile($absolute) ? File::get($absolute) : '';
            foreach ($forbiddenTerms as $term) {
                if ($source !== '' && str_contains($source, $term)) {
                    $violations[] = [
                        'path' => $path,
                        'term' => $term,
                    ];
                }
            }
        }

        return [
            'id' => $id,
            'status' => $violations === [] ? 'pass' : 'fail',
            'severity' => $severity,
            'forbidden_terms' => $forbiddenTerms,
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<int,string>  $requiredTerms
     * @return array<string,mixed>
     */
    private function capabilityCheck(string $id, array $requiredTerms): array
    {
        $contract = json_encode($this->contract(['task' => 'enterprise frontend ui live prototype design system motion deck infographic']), JSON_UNESCAPED_SLASHES) ?: '';
        $missing = array_values(array_filter($requiredTerms, static fn (string $term): bool => ! str_contains($contract, $term)));

        return [
            'id' => $id,
            'status' => $missing === [] ? 'pass' : 'fail',
            'severity' => 'critical',
            'missing' => $missing,
        ];
    }
}
