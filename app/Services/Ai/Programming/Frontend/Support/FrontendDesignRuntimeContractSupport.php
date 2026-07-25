<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend\Support;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemInventoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendGauntletService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductBlueprintService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRepoIntakeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendScenarioMatrixService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorkOrderService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorldBestProofPlanService;
use App\Services\Ai\Support\AiTextMatcher;

/**
 * Pure design-runtime contract projection peeled from
 * {@see \App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService}.
 *
 * Signals, output types, capabilities, gates, evidence, scorecard, blockers,
 * warnings, quality rules, variant strategy, and pure static sub-contracts
 * (gauntlet/blueprint/runbook/etc without app()). No FS, no app()/DI, no
 * provider I/O — host keeps certify() FS checks and app()-backed nested contracts.
 */
final class FrontendDesignRuntimeContractSupport
{
    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,bool|string>
     */
    public static function signals(string $haystack, array $options): array
    {
        return [
            'ui_change' => self::containsAny($haystack, ['frontend', 'ui', 'layout', 'screen', 'tela', 'component', 'css', 'design', 'mobile', 'desktop']),
            'production_code' => (bool) ($options['code_changes'] ?? true),
            'prototype_requested' => (bool) ($options['prototype'] ?? false) || self::containsAny($haystack, ['prototype', 'prototipo', 'wireframe', 'variant', 'variante']),
            'live_iteration_requested' => (bool) ($options['live'] ?? false) || self::containsAny($haystack, ['live', 'browser', 'selecionar elemento', 'accept', 'discard']),
            'asset_heavy' => self::containsAny($haystack, ['logo', 'brand', 'marca', 'image', 'asset', 'hero', 'photo', 'video']),
            'motion_or_deck' => self::containsAny($haystack, ['motion', 'animation', 'animacao', 'deck', 'slides', 'ppt', 'infographic']),
            'enterprise_multi_company' => self::containsAny($haystack, ['inumeras empresas', 'multiempresa', 'multi-company', 'white label', 'clientes', 'multi tenant', 'multitenant']),
            'broad_visual_change' => self::containsAny($haystack, ['design system', 'redesign', 'todas as telas', 'produto inteiro', 'inumeras empresas']),
            'performance_sensitive' => self::containsAny($haystack, ['performance', 'lighthouse', 'latencia', 'bundle', 'custo', 'cache']),
            'accessibility_sensitive' => true,
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<int,string>
     */
    public static function outputTypes(array $signals): array
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
    public static function capabilities(array $signals, array $outputTypes): array
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
    public static function requiredGates(array $signals, array $outputTypes): array
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
    public static function requiredEvidence(array $signals, array $outputTypes): array
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
    public static function competitiveScorecard(array $capabilities, array $requiredGates, array $requiredEvidence): array
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
    public static function blockers(array $signals, array $options): array
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
    public static function warnings(array $signals, array $options): array
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
    public static function designQualityRules(): array
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
    public static function variantStrategy(array $signals): array
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
    public static function designSystemInventoryContract(array $signals): array
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
     * @param  array<string,bool|string>  $signals
     * @return array<string,mixed>
     */
    public static function liveIterationContract(array $signals): array
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


    /**
     * @return array<string,mixed>
     */
    public static function productBlueprintContract(): array
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
    public static function enterpriseBootstrapContract(): array
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
    public static function gauntletContract(): array
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
    public static function workOrderContract(): array
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
    public static function executionRunbookContract(): array
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
                'private_benchmark_and_optional_publication_proof',
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
    public static function providerInstructionPacketContract(): array
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
    public static function scenarioMatrixContract(): array
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
    public static function evidenceKitContract(): array
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
    public static function privateBenchmarkProofPlanContract(): array
    {
        return [
            'schema_version' => AtlasFrontendPrivateBenchmarkProofPlanService::SCHEMA_VERSION,
            'status' => 'required_for_private_competitive_improvement_loop',
            'runtime' => 'AtlasFrontendPrivateBenchmarkProofPlanService',
            'command' => 'php artisan atlas:frontend:private-benchmark-plan --rival-evidence=<dir> --bundle=<bundle> --publication-receipt=<receipt> --json --strict',
            'reuses_legacy_runtime_for_projection_only' => 'AtlasFrontendWorldBestProofPlanService',
            'required_proof_streams' => [
                'external_rival_replay',
                'operator_packet_verification',
                'score_attestation',
                'private_outcome_memory',
            ],
            'optional_proof_streams' => [
                'publication_receipt_for_audit',
            ],
            'claim_policy' => [
                'private_benchmark_for_internal_improvement_only' => true,
                'public_superiority_claims_disabled' => true,
                'world_best_claim_allowed' => false,
                'may_claim_more_complete_than_impeccable' => false,
                'may_claim_more_complete_than_claude_design_plugin' => false,
                'documentation_only_claim_forbidden' => true,
                'raw_prompt_source_customer_data_forbidden' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function worldBestProofPlanContract(): array
    {
        return [
            'schema_version' => AtlasFrontendWorldBestProofPlanService::SCHEMA_VERSION,
            'status' => 'legacy_compatibility_only_not_operator_default',
            'runtime' => 'AtlasFrontendWorldBestProofPlanService',
            'command' => 'php artisan atlas:frontend:world-best-plan --rival-evidence=<dir> --bundle=<bundle> --publication-receipt=<receipt> --json --strict',
            'canonical_replacement' => 'private_benchmark_proof_plan_contract',
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
    public static function repoIntakeContract(): array
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
    public static function executionGateContract(): array
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
    public static function runCertificationContract(): array
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
    public static function deliveryHandoffContract(): array
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
    public static function outcomeMemoryContract(): array
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
     * @param  array<int,string>  $needles
     */
    public static function containsAny(string $haystack, array $needles): bool
    {
        return AiTextMatcher::containsAnyAsciiLowerNeedle($haystack, $needles);
    }
}
