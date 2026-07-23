<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;


final class CodeRealityFlowFamilySection
{
    public function __construct(
        private readonly CodeRealityPrimitives $primitives,
    ) {}


    /**
     * @param  array<int,string>  $matches
     * @return array<int,array<string,mixed>>
     */
    public function flowFamilyGroups(array $matches, string $topic): array
    {
        $groups = [];
        foreach ($matches as $path) {
            $family = $this->flowFamilyForPath($path, $topic);
            if ($family === null) {
                continue;
            }
            $groups[$family][] = $path;
        }

        return $this->primitives->formatPathCountGroups($groups);
    }

    private function flowFamilyForPath(string $path, string $topic): ?string
    {
        $basename = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $normalized = strtolower(str_replace(['-', '_', '/', '\\'], '', $path));

        if ($topic === 'frontend_programming') {
            return match (true) {
                str_contains($normalized, 'frontendprivatebenchmarkproofplan') || str_contains($normalized, 'frontendworldbestproofplan') || str_contains($normalized, 'frontendcompetitivebenchmarkplan') || str_contains($normalized, 'frontendbenchmarkruntime') || str_contains($normalized, 'frontendreplay') || str_contains($normalized, 'frontendrivalreplay') => 'frontend_benchmark_proof',
                str_contains($normalized, 'frontendevidence') || str_contains($normalized, 'frontendruncertification') || str_contains($normalized, 'frontendruncertify') || str_contains($normalized, 'frontenddeliveryhandoff') || str_contains($normalized, 'frontendpublication') => 'frontend_evidence_certification',
                str_contains($normalized, 'frontendcompanyportfolio') || str_contains($normalized, 'frontendselectedworkspace') || str_contains($normalized, 'frontendworkspaceruntimeprojection') || str_contains($normalized, 'frontendcontrolplane') => 'frontend_workspace_control',
                str_contains($normalized, 'frontendlive') || str_contains($normalized, 'frontendbrowserbridge') => 'frontend_live_mode',
                str_contains($normalized, 'frontenddesignruntime') || str_contains($normalized, 'frontenddesignreview') || str_contains($normalized, 'frontendvisualquality') || str_contains($normalized, 'frontendqualitybudget') || str_contains($normalized, 'frontendantislop') => 'frontend_design_quality',
                default => null,
            };
        }

        if ($topic !== 'rag_retrieval') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'localrag') => 'local_rag',
            str_contains($normalized, 'agenticrag') => 'agentic_rag',
            str_contains($normalized, 'graphrag') || str_contains($normalized, 'graphretrieval') => 'graph_retrieval',
            str_contains($normalized, 'hybridretrieval') => 'hybrid_retrieval',
            str_contains($normalized, 'pythondataretrieval') => 'python_data_retrieval',
            str_contains($normalized, 'semanticembedding') || $basename === 'embeddingservice' => 'semantic_embedding',
            str_contains($normalized, 'retrievalfeedback') || str_contains($normalized, 'ragfeedback') => 'retrieval_feedback',
            str_contains($normalized, 'retrievalevaluation') || str_contains($normalized, 'retrievalbenchmark') => 'retrieval_eval',
            str_contains($normalized, 'contextranking') || str_contains($normalized, 'reranker') => 'context_ranking_rerank',
            str_contains($normalized, 'contextcompiler') || str_contains($normalized, 'contextcachecompiler') => 'context_compiler_cache',
            str_contains($normalized, 'persistentcontext') => 'persistent_context',
            str_contains($normalized, 'openbrain') => 'open_brain',
            str_contains($normalized, 'memoryrecall') => 'memory_recall',
            str_contains($normalized, 'memorymaintenance') || str_contains($normalized, 'memoryquality') || str_contains($normalized, 'memorygovernance') => 'memory_governance_quality',
            str_contains($normalized, 'contextpack') => 'context_pack',
            default => null,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $families
     * @return array<int,array<string,mixed>>
     */
    public function flowFamilyReviewQueue(string $topic, array $families): array
    {
        $items = [];
        foreach ($families as $family) {
            $value = (string) ($family['value'] ?? '');
            $paths = (array) ($family['samples'] ?? []);
            $severity = match ($value) {
                'graph_retrieval', 'semantic_embedding' => 'high',
                'frontend_benchmark_proof', 'frontend_evidence_certification', 'frontend_workspace_control' => 'high',
                'local_rag', 'context_ranking_rerank', 'retrieval_feedback', 'context_pack' => 'medium',
                'frontend_live_mode', 'frontend_design_quality' => 'medium',
                'open_brain', 'python_data_retrieval', 'persistent_context', 'context_compiler_cache' => 'review',
                default => 'low',
            };
            $requiredDecision = match ($value) {
                'graph_retrieval' => 'decide_context_service_vs_programming_runtime_boundary',
                'semantic_embedding' => 'decide_context_embedding_vs_semantic_embedding_boundary',
                'context_ranking_rerank' => 'decide_context_ranking_vs_programming_reranker_boundary',
                'local_rag' => 'document_as_benchmark_readiness_only_or_promote_to_owner_runtime',
                'retrieval_feedback' => 'ensure_feedback_loop_has_single_owner_between_context_and_compounding',
                'context_pack' => 'ensure_context_pack_model_store_builder_have_single_contract',
                'context_compiler_cache' => 'decide_compiler_vs_cache_compiler_runtime_boundary',
                'persistent_context' => 'keep_persistent_context_as_storage_runtime_not_second_context_pack_owner',
                'open_brain' => 'document_open_brain_as_projection_surface_not_parallel_memory_source',
                'python_data_retrieval' => 'keep_python_data_retrieval_behind_runtime_language_boundary',
                'retrieval_eval' => 'keep_retrieval_eval_as_benchmark_not_primary_retrieval_runtime',
                'agentic_rag' => 'keep_agentic_rag_as_context_orchestration_framework_not_memory_owner',
                'hybrid_retrieval' => 'keep_hybrid_retrieval_as_infrastructure_layer_under_unified_context_contract',
                'memory_recall' => 'keep_memory_recall_as_query_surface_over_memory_contracts',
                'memory_governance_quality' => 'keep_memory_quality_as_governance_metric_not_retrieval_owner',
                'frontend_benchmark_proof' => 'make_private_benchmark_plan_canonical_and_keep_world_best_as_legacy_alias',
                'frontend_evidence_certification' => 'keep_evidence_pack_run_certify_handoff_as_sequential_stages_not_parallel_proof_owners',
                'frontend_workspace_control' => 'keep_portfolio_selected_workspace_projection_and_control_plane_as_ordered_workspace_selection_flow',
                'frontend_live_mode' => 'keep_browser_bridge_live_visual_selection_and_source_patch_as_live_mode_stages_not_independent_editing_flows',
                'frontend_design_quality' => 'keep_design_runtime_quality_budget_visual_gate_and_review_as_quality_gates_under_frontend_owner',
                default => 'document_owner_boundary_or_mark_intentional_surface_wrapper',
            };
            $items[] = [
                'id' => $topic.':'.$value,
                'family' => $value,
                'severity' => $severity,
                'status' => 'owner_boundary_review_required',
                'count' => (int) ($family['count'] ?? 0),
                'sample_paths' => $paths,
                'current_evidence' => $this->flowFamilyEvidenceHint($value),
                'boundary_contract' => $this->flowFamilyBoundaryContract($value),
                'cleanup_classification' => $this->flowFamilyCleanupClassification($value),
                'cleanup_sequence' => $this->flowFamilyCleanupSequence($value),
                'required_decision' => $requiredDecision,
                'claim_policy' => 'review_queue_is_not_dead_code_or_delete_authority',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $items;
    }

    /**
     * @return array<string,string>
     */
    private function flowFamilyEvidenceHint(string $value): array
    {
        return match ($value) {
            'graph_retrieval' => [
                'reachability' => 'context_service_high_reachability; programming_runtime_medium_reachability',
                'observed_boundary' => 'Context AtlasGraphRetrievalNetworkService has command/test/owner docs; ProgrammingGraphRagRuntime is a programming consumer/helper reached by planner and completion audit',
                'cleanup_bias' => 'do_not_delete; document context owner and programming adapter/runtime boundary',
            ],
            'semantic_embedding' => [
                'reachability' => 'context_service_high_reachability; semantic_embedding_service_medium_reachability',
                'observed_boundary' => 'Context semantic foundation has command/test/owner docs; Semantic EmbeddingService is called by attachment/note/search indexing',
                'cleanup_bias' => 'do_not_delete; define Context orchestration vs Semantic primitive embedding boundary',
            ],
            'retrieval_feedback' => [
                'reachability' => 'context_loop_high_reachability; compounding_feedback_service_high_reachability',
                'observed_boundary' => 'Context owns retrieval loop command/test/doc; Compounding owns persisted feedback consumer and programming certification integration',
                'cleanup_bias' => 'do_not_delete; define single feedback contract and owner handoff',
            ],
            'context_pack' => [
                'reachability' => 'builder_high_reachability; programming_store_high_reachability',
                'observed_boundary' => 'AiContextPackBuilder is runtime builder with command/tests; ProgrammingContextPackStore persists programming-specific packs',
                'cleanup_bias' => 'do_not_delete; define base context pack contract vs programming persistence boundary',
            ],
            'local_rag' => [
                'reachability' => 'readiness_and_benchmark_surface_present',
                'observed_boundary' => 'LocalRagBenchmarkService and LocalRagReadinessService look like evaluation/readiness surfaces, not primary canonical RAG owner',
                'cleanup_bias' => 'document as benchmark/readiness unless promoted by owner decision',
            ],
            'context_ranking_rerank' => [
                'reachability' => 'context_ranking_and_programming_reranker_both_present',
                'observed_boundary' => 'Context ranking is canonical retrieval ranking; ProgrammingProfessionalReranker is domain-specific rerank consumer',
                'cleanup_bias' => 'document shared ranking contract and programming-specific adapter boundary',
            ],
            'context_compiler_cache' => [
                'reachability' => 'context_compiler_command_and_context_cache_compiler_services_present',
                'observed_boundary' => 'ContextCompilerRuntime composes source context; ContextCacheCompilerRuntime optimizes/cache-compiles the same context family',
                'cleanup_bias' => 'do_not_delete; define compiler vs cache compiler responsibility before consolidation',
            ],
            'persistent_context' => [
                'reachability' => 'persistent_context_model_service_and_migration_present',
                'observed_boundary' => 'PersistentContext runtime stores durable packs; base context pack builder remains the composition contract',
                'cleanup_bias' => 'do_not_delete; document storage boundary and avoid second context pack owner',
            ],
            'open_brain' => [
                'reachability' => 'open_brain_injection_mcp_and_programming_projection_adapter_present',
                'observed_boundary' => 'Open Brain exposes provider-safe context projections; it must not author canonical docs or decide implementation',
                'cleanup_bias' => 'document projection/surface role and avoid treating Open Brain as parallel memory authority',
            ],
            'python_data_retrieval' => [
                'reachability' => 'python_data_retrieval_command_and_context_service_present',
                'observed_boundary' => 'Python data retrieval is a runtime-boundary integration point, not Laravel-owned ML/vector runtime',
                'cleanup_bias' => 'keep behind AP-201 runtime language boundary and decision receipt',
            ],
            'retrieval_eval' => [
                'reachability' => 'retrieval_benchmark_and_evaluation_arena_surfaces_present',
                'observed_boundary' => 'Retrieval evaluation benchmarks retrieval behavior; it must not become the primary retrieval runtime',
                'cleanup_bias' => 'document benchmark-only role unless owner promotes runtime explicitly',
            ],
            'agentic_rag' => [
                'reachability' => 'agentic_rag_command_service_and_programming_spec_present',
                'observed_boundary' => 'Agentic RAG orchestrates retrieval strategy for programming; memory/context owners remain canonical',
                'cleanup_bias' => 'do_not_delete; prevent framework from becoming parallel memory owner',
            ],
            'hybrid_retrieval' => [
                'reachability' => 'hybrid_retrieval_command_service_and_owner_doc_present',
                'observed_boundary' => 'Hybrid retrieval is infrastructure under unified context retrieval, not an isolated RAG fork',
                'cleanup_bias' => 'document as infrastructure layer under context/retrieval contracts',
            ],
            'memory_recall' => [
                'reachability' => 'memory_recall_command_and_controller_present',
                'observed_boundary' => 'Memory recall is a query surface over memory contracts, not a new memory store or retrieval owner',
                'cleanup_bias' => 'document as surface and keep policy in memory contracts',
            ],
            'memory_governance_quality' => [
                'reachability' => 'memory_quality_service_present',
                'observed_boundary' => 'Memory quality scores/governs memory and retrieval health; it does not retrieve context directly',
                'cleanup_bias' => 'document as governance metric and avoid coupling it as retrieval owner',
            ],
            'frontend_benchmark_proof' => [
                'reachability' => 'private_benchmark_world_best_replay_and_benchmark_surfaces_present',
                'observed_boundary' => 'PrivateBenchmarkProofPlan is canonical superiority-proof planner; WorldBestProofPlan is a legacy alias/projection; RivalReplay and BenchmarkRuntime supply evidence inputs',
                'cleanup_bias' => 'do_not_delete; mark canonical command and legacy alias boundary before merging proof planners',
            ],
            'frontend_evidence_certification' => [
                'reachability' => 'evidence_pack_run_certify_handoff_publication_surfaces_present',
                'observed_boundary' => 'Evidence kit/pack prepares artifacts, run-certify validates them, handoff summarizes delivery, publication verifies distribution receipts',
                'cleanup_bias' => 'document sequential proof pipeline and forbid parallel completion claims from templates',
            ],
            'frontend_workspace_control' => [
                'reachability' => 'portfolio_selected_workspace_runtime_projection_and_control_plane_surfaces_present',
                'observed_boundary' => 'Portfolio scans candidates, SelectedWorkspace binds one repo/frontend-app, ControlPlane aggregates readiness and proof state',
                'cleanup_bias' => 'document ordered workspace selection flow to avoid multiple workspace owners',
            ],
            'frontend_live_mode' => [
                'reachability' => 'browser_bridge_live_visual_selection_and_live_source_patch_surfaces_present',
                'observed_boundary' => 'Browser bridge captures UI context, live visual selection chooses targets, live source patch prepares/accepts/recover patches',
                'cleanup_bias' => 'document live mode stages and forbid direct editing paths outside source patch receipts',
            ],
            'frontend_design_quality' => [
                'reachability' => 'design_runtime_visual_quality_quality_budget_design_review_surfaces_present',
                'observed_boundary' => 'Design runtime orchestrates frontend quality gates; visual quality, quality budget, anti-slop and review are gates, not separate feature owners',
                'cleanup_bias' => 'document gate roles and keep claims blocked until measured evidence replaces templates',
            ],
            default => [
                'reachability' => 'run_target_reachability_before_cleanup',
                'observed_boundary' => 'owner_boundary_review_required',
                'cleanup_bias' => 'do_not_delete_without_owner_review',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function flowFamilyBoundaryContract(string $value): array
    {
        return match ($value) {
            'graph_retrieval' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-graph-retrieval-network.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php',
                'allowed_direction' => 'programming_consumes_context_graph_retrieval_or_python_handoff',
                'forbidden' => 'programming_must_not_become_second_global_graph_retrieval_owner',
            ],
            'semantic_embedding' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php',
                'adapter_or_consumer' => 'app/Services/Semantic/EmbeddingService.php',
                'owner_role' => 'embedding_policy_manifest_chunking_privacy_and_runtime_promotion_gate',
                'adapter_role' => 'real_semantic_rag_or_openai_embedding_adapter_with_explicit_failure_no_hash_fake',
                'schema_authority' => 'atlas.aucri.semantic_embedding_foundation.v1',
                'promotion_gate' => 'external_or_heavy_embedding_generation_requires_python_ai_data_decision_receipt',
                'allowed_direction' => 'context_orchestrates_embedding_policy_semantic_service_provides_primitive_embedding',
                'forbidden' => 'semantic_service_must_not_bypass_context_policy_privacy_or_runtime_boundary',
            ],
            'retrieval_feedback' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Compounding/AtlasRagFeedbackService.php',
                'owner_role' => 'retrieval_feedback_event_context_roi_noise_missed_ref_and_learning_candidate_contract',
                'adapter_role' => 'persisted_compounding_feedback_consumer_with_deterministic_next_retrieval_hint',
                'schema_authority' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'consumer_schema' => 'atlas.ai.rag.feedback.v1',
                'allowed_direction' => 'context_records_retrieval_feedback_compounding_consumes_or_distills_learning',
                'forbidden' => 'compounding_must_not_create_parallel_retrieval_feedback_schema_or_owner',
            ],
            'context_pack' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/memory/retrieval-and-context.md',
                'owner_runtime' => 'app/Services/Ai/Context/AiContextPackBuilder.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/ProgrammingContextPackStore.php',
                'owner_role' => 'base_context_pack_composition_memory_retrieval_privacy_and_prompt_context_contract',
                'adapter_role' => 'programming_domain_persistence_and_replay_store_for_existing_context_pack_payloads',
                'schema_authority' => 'App\\Services\\Ai\\ValueObjects\\AiContextPack',
                'storage_table' => 'atlas_programming_context_packs',
                'allowed_direction' => 'base_builder_composes_context_programming_store_persists_domain_specific_pack',
                'forbidden' => 'programming_store_must_not_redefine_base_context_pack_contract',
            ],
            'local_rag' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
                'owner_runtime' => 'app/Services/Ai/Context/LocalRagReadinessService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Context/LocalRagBenchmarkService.php',
                'allowed_direction' => 'readiness_and_benchmark_only_until_owner_promotes_runtime',
                'forbidden' => 'local_rag_benchmark_must_not_become_canonical_rag_runtime_by_accident',
            ],
            'context_ranking_rerank' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-context-ranking-system.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasContextRankingSystemService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/ProgrammingProfessionalReranker.php',
                'allowed_direction' => 'programming_reranker_applies_domain_scoring_after_context_ranking_contract',
                'forbidden' => 'programming_reranker_must_not_fork_global_context_ranking_policy',
            ],
            'context_compiler_cache' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-context-compiler-runtime.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasContextCompilerRuntimeService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Context/AtlasContextCacheCompilerRuntimeService.php',
                'allowed_direction' => 'cache_compiler_may_optimize_context_compiler_outputs_without_redefining_context_sources',
                'forbidden' => 'cache_compiler_must_not_become_second_context_compiler_owner',
            ],
            'persistent_context' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-persistent-context-runtime.md',
                'owner_runtime' => 'app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php',
                'adapter_or_consumer' => 'app/Models/AtlasPersistentContextPack.php',
                'allowed_direction' => 'persistent_context_stores_durable_packs_under_memory_context_contracts',
                'forbidden' => 'persistent_context_must_not_redefine_base_context_pack_or_open_brain_contract',
            ],
            'open_brain' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/open-brain-context-injection.md',
                'owner_runtime' => 'app/Services/Ai/AtlasOpenBrainContextInjectionService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/AtlasDev/Discovery/OpenBrainProjectionAdapter.php',
                'allowed_direction' => 'open_brain_exports_provider_safe_context_projection_from_canonical_memory_docs_and_code_intelligence',
                'forbidden' => 'open_brain_must_not_author_docs_or_override_canonical_repo_docs',
            ],
            'python_data_retrieval' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasPythonDataRetrievalRuntimeService.php',
                'adapter_or_consumer' => 'app/Console/Commands/AtlasPythonDataRetrievalRuntimeCommand.php',
                'allowed_direction' => 'laravel_invokes_python_data_retrieval_through_runtime_boundary_and_receipts',
                'forbidden' => 'do_not_implement_python_rag_vector_or_ml_runtime_inside_laravel_app_services',
            ],
            'retrieval_eval' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasRetrievalEvaluationBenchmarkArenaService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/ProgrammingRetrievalBenchmarkService.php',
                'allowed_direction' => 'evaluation_benchmarks_measure_retrieval_quality_and_feed_memory_quality',
                'forbidden' => 'retrieval_eval_must_not_become_primary_retrieval_runtime_or_memory_source',
            ],
            'agentic_rag' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-agentic-rag-framework.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasAgenticRagFrameworkService.php',
                'adapter_or_consumer' => 'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md',
                'allowed_direction' => 'agentic_rag_orchestrates_retrieval_strategy_over_existing_memory_context_and_code_intelligence',
                'forbidden' => 'agentic_rag_must_not_create_parallel_memory_store_or_context_authority',
            ],
            'hybrid_retrieval' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasHybridRetrievalInfrastructureService.php',
                'adapter_or_consumer' => 'docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md',
                'allowed_direction' => 'hybrid_retrieval_combines_existing_retrieval_channels_under_unified_context_contract',
                'forbidden' => 'hybrid_retrieval_must_not_become_isolated_rag_runtime_without_context_owner_review',
            ],
            'memory_recall' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/memory/retrieval-and-context.md',
                'owner_runtime' => 'app/Console/Commands/AtlasMemoryRecallCommand.php',
                'adapter_or_consumer' => 'app/Http/Controllers/AtlasMemoryRecallController.php',
                'allowed_direction' => 'memory_recall_surfaces_queries_over_memory_contracts_and_open_brain_policy',
                'forbidden' => 'memory_recall_must_not_create_parallel_retrieval_policy_or_memory_store',
            ],
            'memory_governance_quality' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/memory/retrieval-and-context.md',
                'owner_runtime' => 'app/Services/Ai/Memory/AtlasMemoryQualityService.php',
                'adapter_or_consumer' => 'docs/engineering-knowledge-base/memory/foundation-map.md',
                'allowed_direction' => 'memory_quality_scores_retrieval_and_memory_health_without_serving_context_directly',
                'forbidden' => 'memory_quality_must_not_be_used_as_retrieval_owner_or_context_source',
            ],
            'frontend_benchmark_proof' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
                'owner_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendPrivateBenchmarkProofPlanService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendWorldBestProofPlanService.php',
                'supporting_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendRivalReplayHarnessService.php',
                'canonical_command' => 'atlas:frontend:private-benchmark-plan',
                'legacy_alias_command' => 'atlas:frontend:world-best-plan',
                'allowed_direction' => 'private_benchmark_plan_owns_competitive_proof_world_best_projects_legacy_disabled_claims',
                'forbidden' => 'world_best_or_benchmark_surfaces_must_not_claim_public_superiority_without_private_benchmark_evidence_and_publication_receipt',
            ],
            'frontend_evidence_certification' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
                'owner_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendEvidenceKitService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendRunCertificationService.php',
                'handoff_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDeliveryHandoffService.php',
                'allowed_direction' => 'evidence_kit_prepares_templates_run_certify_validates_measured_artifacts_handoff_summarizes_delivery',
                'forbidden' => 'evidence_templates_or_handoff_must_not_be_treated_as_delivery_proof_without_run_certification',
            ],
            'frontend_workspace_control' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
                'owner_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompanyPortfolioService.php',
                'control_plane_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendControlPlaneService.php',
                'allowed_direction' => 'portfolio_suggests_selected_workspace_binds_control_plane_aggregates_runtime_state',
                'forbidden' => 'portfolio_or_control_plane_must_not_replace_selected_workspace_as_operator_bound_workspace_owner',
            ],
            'frontend_live_mode' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
                'owner_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendLiveSourcePatchRuntimeService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendBrowserBridgeService.php',
                'selection_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendLiveVisualSelectionInboxService.php',
                'allowed_direction' => 'browser_bridge_and_visual_selection_feed_live_source_patch_receipts',
                'forbidden' => 'live_visual_selection_or_browser_bridge_must_not_patch_source_without_live_source_patch_decision_receipt',
            ],
            'frontend_design_quality' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
                'owner_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendVisualQualityGateService.php',
                'review_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignReviewService.php',
                'allowed_direction' => 'design_runtime_orchestrates_visual_quality_quality_budget_anti_slop_and_review_gates',
                'forbidden' => 'quality_gate_or_review_service_must_not_become_independent_frontend_delivery_owner',
            ],
            default => [
                'canonical_owner' => 'owner_review_required',
                'owner_runtime' => 'owner_review_required',
                'adapter_or_consumer' => 'owner_review_required',
                'allowed_direction' => 'declare_before_new_code',
                'forbidden' => 'do_not_create_parallel_rag_memory_or_context_owner',
            ],
        };
    }

    /**
     * @return array<string,string>
     */
    private function flowFamilyCleanupClassification(string $value): array
    {
        return match ($value) {
            'graph_retrieval', 'semantic_embedding', 'context_ranking_rerank', 'context_compiler_cache' => [
                'bucket' => 'context_owner_with_programming_adapter',
                'cleanup_pressure' => 'boundary_or_adapter_rename',
                'safe_interpretation' => 'context_runtime_is_owner_programming_side_is_adapter_or_consumer',
            ],
            'local_rag', 'retrieval_eval' => [
                'bucket' => 'benchmark_or_readiness_surface',
                'cleanup_pressure' => 'document_not_primary_runtime',
                'safe_interpretation' => 'surface_measures_or_preflights_retrieval_not_canonical_runtime',
            ],
            'retrieval_feedback' => [
                'bucket' => 'feedback_owner_handoff',
                'cleanup_pressure' => 'single_feedback_contract',
                'safe_interpretation' => 'context_records_feedback_compounding_consumes_learning',
            ],
            'context_pack', 'persistent_context' => [
                'bucket' => 'context_pack_contract_vs_storage',
                'cleanup_pressure' => 'contract_boundary',
                'safe_interpretation' => 'builder_contract_and_persistence_store_are_distinct',
            ],
            'open_brain' => [
                'bucket' => 'provider_projection_surface',
                'cleanup_pressure' => 'authority_boundary',
                'safe_interpretation' => 'open_brain_projects_context_but_never_authors_truth',
            ],
            'python_data_retrieval' => [
                'bucket' => 'runtime_language_boundary',
                'cleanup_pressure' => 'adapter_boundary',
                'safe_interpretation' => 'python_retrieval_stays_behind_runtime_boundary_and_receipts',
            ],
            'agentic_rag', 'hybrid_retrieval', 'memory_recall', 'memory_governance_quality' => [
                'bucket' => 'orchestration_or_surface_not_owner',
                'cleanup_pressure' => 'document_surface_role',
                'safe_interpretation' => 'uses_memory_context_contracts_without_becoming_new_owner',
            ],
            'frontend_benchmark_proof' => [
                'bucket' => 'frontend_competitive_proof_pipeline',
                'cleanup_pressure' => 'canonical_command_and_legacy_alias_boundary',
                'safe_interpretation' => 'private_benchmark_plan_owns_claims_world_best_is_legacy_projection',
            ],
            'frontend_evidence_certification' => [
                'bucket' => 'frontend_evidence_certification_pipeline',
                'cleanup_pressure' => 'stage_boundary',
                'safe_interpretation' => 'templates_certification_handoff_and_publication_are_ordered_stages',
            ],
            'frontend_workspace_control' => [
                'bucket' => 'frontend_workspace_selection_pipeline',
                'cleanup_pressure' => 'single_workspace_owner_boundary',
                'safe_interpretation' => 'portfolio_control_plane_and_projection_support_selected_workspace',
            ],
            'frontend_live_mode' => [
                'bucket' => 'frontend_live_mode_pipeline',
                'cleanup_pressure' => 'receipt_boundary',
                'safe_interpretation' => 'browser_bridge_and_visual_selection_feed_live_source_patch_receipts',
            ],
            'frontend_design_quality' => [
                'bucket' => 'frontend_quality_gate_pipeline',
                'cleanup_pressure' => 'gate_role_boundary',
                'safe_interpretation' => 'quality_services_are_gates_under_design_runtime_not_delivery_owners',
            ],
            default => [
                'bucket' => 'owner_review_required',
                'cleanup_pressure' => 'review',
                'safe_interpretation' => 'read_owner_doc_before_new_retrieval_flow',
            ],
        };
    }

    /**
     * @return array<int,string>
     */
    private function flowFamilyCleanupSequence(string $value): array
    {
        return match ($value) {
            'graph_retrieval' => [
                'keep_atlas_graph_retrieval_network_as_context_owner',
                'keep_programming_graph_rag_runtime_as_consumer_or_adapter',
                'add_adapter_tests_before_any_rename_or_merge',
                'forbid_programming_global_graph_retrieval_owner',
            ],
            'semantic_embedding' => [
                'keep_context_semantic_embedding_policy_as_owner',
                'keep_semantic_embedding_service_as_primitive_embedding_provider',
                'prove_privacy_policy_before_external_embedding_or_vector_store',
                'route_heavy_embedding_generation_through_python_ai_data_decision_receipt',
                'forbid_semantic_service_bypassing_context_policy',
            ],
            'local_rag' => [
                'keep_local_rag_readiness_and_benchmark_as_preflight_surfaces',
                'do_not_promote_local_rag_to_primary_runtime_without_owner_decision',
                'mark_benchmark_outputs_as_evidence_not_authority',
            ],
            'retrieval_eval' => [
                'keep_evaluation_arena_as_benchmark_surface',
                'feed_results_to_quality_governance_without_becoming_retrieval_owner',
                'require_golden_set_before_claiming_runtime_quality',
            ],
            'context_pack' => [
                'keep_ai_context_pack_builder_as_base_contract',
                'keep_programming_context_pack_store_as_domain_persistence',
                'add_projection_adapter_before_schema_merge_or_rename',
                'forbid_programming_store_redefining_base_context_pack_contract',
            ],
            'context_ranking_rerank' => [
                'keep_context_ranking_system_as_global_policy_owner',
                'keep_programming_professional_reranker_as_domain_adapter',
                'add_ranking_adapter_tests_before_policy_or_schema_merge',
                'forbid_programming_reranker_forking_global_context_ranking',
            ],
            'retrieval_feedback' => [
                'keep_atlas_retrieval_feedback_loop_as_feedback_contract_owner',
                'keep_compounding_rag_feedback_as_learning_consumer',
                'add_feedback_handoff_tests_before_schema_or_owner_change',
                'forbid_compounding_parallel_retrieval_feedback_schema',
            ],
            'context_compiler_cache' => [
                'keep_context_compiler_runtime_as_source_composition_owner',
                'keep_context_cache_compiler_as_cache_optimizer_only',
                'add_compiler_cache_parity_tests_before_consolidation',
                'forbid_cache_compiler_second_context_compiler_owner',
            ],
            'persistent_context' => [
                'keep_ai_context_pack_builder_as_composition_contract',
                'keep_persistent_context_as_durable_storage_runtime',
                'add_projection_tests_before_storage_schema_or_pack_contract_change',
                'forbid_persistent_context_redefining_context_pack_or_open_brain_contract',
            ],
            'open_brain' => [
                'keep_open_brain_as_provider_safe_projection',
                'forbid_open_brain_as_authoring_source',
                'regenerate_projection_from_canonical_docs_after_owner_changes',
            ],
            'python_data_retrieval' => [
                'keep_laravel_to_python_boundary_explicit',
                'require_receipts_for_python_runtime_calls',
                'forbid_laravel_vector_or_ml_runtime_fork',
            ],
            'agentic_rag' => [
                'keep_agentic_rag_as_orchestration_framework_over_existing_retrieval_owners',
                'reuse_memory_context_and_code_intelligence_contracts',
                'add_owner_review_before_new_store_policy_or_context_authority',
                'forbid_agentic_rag_parallel_memory_store',
            ],
            'hybrid_retrieval' => [
                'keep_hybrid_retrieval_under_unified_context_retrieval_contract',
                'reuse_existing_vector_graph_memory_and_code_intelligence_channels',
                'add_infrastructure_adapter_tests_before_new_channel',
                'forbid_isolated_hybrid_rag_runtime_without_context_owner_review',
            ],
            'memory_recall' => [
                'keep_memory_recall_as_query_surface_over_memory_contracts',
                'keep_policy_and_storage_in_memory_owner_docs',
                'add_command_controller_parity_tests_before_surface_change',
                'forbid_parallel_retrieval_policy_or_memory_store',
            ],
            'memory_governance_quality' => [
                'keep_memory_quality_as_governance_metric',
                'feed_quality_scores_to_owner_review_not_runtime_selection',
                'add_quality_metric_tests_before_threshold_or_policy_change',
                'forbid_memory_quality_as_retrieval_owner_or_context_source',
            ],
            'frontend_benchmark_proof' => [
                'keep_private_benchmark_plan_as_canonical_competitive_proof_planner',
                'keep_world_best_plan_as_legacy_alias_with_public_superiority_claims_disabled',
                'use_rival_replay_and_benchmark_runtime_as_evidence_inputs_only',
                'forbid_new_frontend_benchmark_or_proof_command_without_owner_decision',
            ],
            'frontend_evidence_certification' => [
                'keep_evidence_kit_as_template_preparation_not_completion_proof',
                'keep_run_certify_as_measured_artifact_gate',
                'keep_handoff_as_summary_after_certification',
                'forbid_delivery_claims_from_templates_or_publication_receipts_alone',
            ],
            'frontend_workspace_control' => [
                'keep_selected_workspace_as_operator_bound_workspace_owner',
                'keep_company_portfolio_as_candidate_discovery_only',
                'keep_control_plane_as_read_model_over_selected_workspace_and_proof_state',
                'forbid_control_plane_or_portfolio_selecting_new_workspace_without_operator_binding',
            ],
            'frontend_live_mode' => [
                'keep_browser_bridge_as_capture_adapter',
                'keep_live_visual_selection_as_target_selection_message_or_inbox',
                'keep_live_source_patch_as_only_patch_receipt_owner',
                'forbid_direct_source_patch_from_browser_bridge_or_visual_selection',
            ],
            'frontend_design_quality' => [
                'keep_design_runtime_as_orchestrator',
                'keep_visual_quality_quality_budget_anti_slop_and_review_as_gates',
                'require_measured_reports_before_run_certify_or_delivery_claims',
                'forbid_quality_gate_services_becoming_parallel_frontend_delivery_flow',
            ],
            default => [
                'read_canonical_owner',
                'run_reachability',
                'decide_keep_adapter_rename_quarantine_or_promote',
            ],
        };
    }
}
