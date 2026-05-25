<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasCodeRealityUsageIntelligenceServiceTest extends TestCase
{
    public function test_classifies_known_command_with_evidence_without_writes(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('app/Console/Commands/AtlasDocumentationRealityCommand.php');

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('classify', $payload['action']);
        $this->assertSame('active_runtime', $payload['classification']);
        $this->assertSame('app/Console/Commands/AtlasDocumentationRealityCommand.php', $payload['target_path']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['deletes_files']);
        $this->assertFalse($payload['claim_policy']['dead_code_confirmation_allowed']);
        $this->assertContains('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $payload['evidence']['tests']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-documentation-reality-system.md', $payload['evidence']['owner_docs']);
        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::REACHABILITY_SCHEMA_VERSION, data_get($payload, 'evidence.reachability.schema_version'));
        $this->assertSame('reachable', data_get($payload, 'evidence.reachability.status'));
        $this->assertSame('high', data_get($payload, 'evidence.reachability.confidence'));
        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_command_entrypoint'));
        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_test_coverage'));
        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_owner_doc'));
        $this->assertNotEmpty(data_get($payload, 'evidence.reachability.edges'));

        $codeRealityCommand = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('app/Console/Commands/AtlasCodeRealityCommand.php');
        $this->assertSame('app/Console/Commands/AtlasCodeRealityCommand.php', $codeRealityCommand['target_path']);
        $this->assertContains('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $codeRealityCommand['evidence']['tests']);
    }

    public function test_unknown_target_blocks_with_review_policy_not_dead_code(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('NoSuchAtlasRuntimeThing');

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('unknown_requires_audit', $payload['classification']);
        $this->assertSame('target_not_found', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['claim_policy']['dead_code_confirmation_allowed']);
        $this->assertSame('not_found', data_get($payload, 'evidence.reachability.status'));
        $this->assertSame('none', data_get($payload, 'evidence.reachability.confidence'));
    }

    public function test_dead_code_candidates_never_confirms_dead_code_or_delete(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->deadCodeCandidates();

        $this->assertSame('ready', $payload['status']);
        $this->assertSame([], $payload['dead_code_confirmed']);
        $this->assertSame('conservative_no_dead_code_confirmation', $payload['classification_policy']);
        $this->assertContains('human_approval', $payload['required_quarantine_sequence']);
        $this->assertFalse($payload['claim_policy']['deletes_files']);
    }

    public function test_deletion_preflight_blocks_delete_even_for_unused_or_active_targets(): void
    {
        $service = app(AtlasCodeRealityUsageIntelligenceService::class);

        $active = $service->deletionPreflight('app/Console/Commands/AtlasDocumentationRealityCommand.php');
        $unknown = $service->deletionPreflight('NoSuchAtlasRuntimeThing');

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::DELETION_PREFLIGHT_SCHEMA_VERSION, $active['schema_version']);
        $this->assertSame('ready', $active['status']);
        $this->assertFalse($active['allowed_to_delete']);
        $this->assertSame('block_delete_active_or_available_target', $active['decision']);
        $this->assertFalse($active['claim_policy']['delete_authorization_allowed']);
        $this->assertContains('human_approval', $active['required_before_delete']);
        $this->assertNotEmpty($active['evidence']['reachability_edges']);

        $this->assertSame('ready', $unknown['status']);
        $this->assertFalse($unknown['allowed_to_delete']);
        $this->assertSame('block_delete_until_quarantine_and_human_approval', $unknown['decision']);
        $this->assertFalse($unknown['claim_policy']['dead_code_confirmation_allowed']);
    }

    public function test_reality_audit_scores_adrs_runtime_cluster_without_mutations(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->realityAudit();

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::REALITY_AUDIT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('adrs_acrui_aurc_runtime_cluster', $payload['scope']);
        $this->assertSame(6, $payload['target_count']);
        $this->assertSame(0, $payload['unknown_or_unused_count']);
        $this->assertSame(0, $payload['weak_reachability_count']);
        $this->assertTrue($payload['risk_register']['delete_claim_blocked']);
        $this->assertFalse($payload['writes']);

        foreach ($payload['targets'] as $target) {
            $this->assertContains($target['classification'], ['active_runtime', 'active_read_only']);
            $this->assertContains($target['reachability_confidence'], ['high', 'medium']);
            $this->assertGreaterThan(0, $target['owner_doc_count']);
            $this->assertGreaterThan(0, $target['test_count']);
        }
    }

    public function test_large_text_files_are_streamed_without_loading_full_contents(): void
    {
        $targetPath = base_path('tests/Fixtures/AtlasAcruiLargeScanTarget.php');
        $largeReferencePath = base_path('tests/Fixtures/atlas-acrui-large-reference.md');
        File::ensureDirectoryExists(dirname($targetPath));
        File::put($targetPath, "<?php\n\nfinal class AtlasAcruiLargeScanTarget {}\n");
        File::put($largeReferencePath, str_repeat('padding line without match '.str_repeat('x', 120)."\n", 900)."\nAtlasAcruiLargeScanTarget\n");

        try {
            $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->classify('AtlasAcruiLargeScanTarget.php');

            $this->assertSame('tests/Fixtures/AtlasAcruiLargeScanTarget.php', $payload['target_path']);
            $this->assertContains('tests/Fixtures/atlas-acrui-large-reference.md', $payload['evidence']['references']);
        } finally {
            File::delete([$targetPath, $largeReferencePath]);
        }
    }

    public function test_anti_duplicate_and_context_pack_are_provider_safe(): void
    {
        $service = app(AtlasCodeRealityUsageIntelligenceService::class);

        $antiDuplicate = $service->antiDuplicate('documentation reality cartography');
        $contextPack = $service->contextPack('implementar runtime de documentation reality');
        $reachability = $service->reachability('app/Console/Commands/AtlasDocumentationRealityCommand.php');

        $this->assertSame('ready', $antiDuplicate['status']);
        $this->assertContains($antiDuplicate['decision'], ['proceed_with_owner_lookup', 'reuse_or_extend_before_new_runtime']);
        $this->assertSame('run_feature_placement_and_read_owner_docs_before_implementation', $antiDuplicate['required_next_step']);

        $this->assertSame('ready', $contextPack['status']);
        $this->assertTrue($contextPack['provider_safe']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', $contextPack['minimal_sources']);
        $this->assertContains('dead_code_confirmed_without_quarantine', $contextPack['do_not_claim']);
        $this->assertContains('php artisan atlas:code-reality reality-audit --json', $contextPack['required_commands']);
        $this->assertContains('php artisan atlas:code-reality global-duplication-audit --json', $contextPack['required_commands']);
        $this->assertContains('php artisan atlas:code-reality status-drift-audit --json', $contextPack['required_commands']);
        $this->assertContains('php artisan atlas:code-reality reachability --target="<target>" --json', $contextPack['required_commands']);
        $this->assertContains('php artisan atlas:code-reality deletion-preflight --target="<target>" --json', $contextPack['required_commands']);

        $this->assertSame('ready', $reachability['status']);
        $this->assertSame('reachability', $reachability['action']);
        $this->assertSame('reachable', data_get($reachability, 'reachability.status'));
        $this->assertSame('high', data_get($reachability, 'reachability.confidence'));
    }

    public function test_global_duplication_audit_surfaces_candidates_without_deleting_or_claiming_cleanliness(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->globalDuplicationAudit();

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::GLOBAL_DUPLICATION_AUDIT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('global-duplication-audit', $payload['action']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['deletes_files']);
        $this->assertFalse($payload['claim_policy']['dead_code_confirmation_allowed']);
        $this->assertFalse($payload['claim_policy']['global_no_duplication_claim_allowed']);
        $this->assertGreaterThan(0, data_get($payload, 'summary.doc_count'));
        $this->assertSame(0, data_get($payload, 'summary.duplicate_canonical_doc_id_group_count'));
        $this->assertSame(0, data_get($payload, 'summary.duplicate_canonical_doc_graph_id_group_count'));
        $this->assertSame(0, data_get($payload, 'summary.duplicate_canonical_doc_title_group_count'));
        $this->assertArrayHasKey('duplicate_active_doc_path_stem_group_count', $payload['summary']);
        $this->assertArrayHasKey('duplicate_canonical_doc_id_groups', $payload['documentation']);
        $this->assertArrayHasKey('duplicate_canonical_doc_graph_id_groups', $payload['documentation']);
        $this->assertArrayHasKey('duplicate_canonical_doc_title_groups', $payload['documentation']);
        $this->assertArrayHasKey('duplicate_active_doc_path_stem_groups', $payload['documentation']);
        $this->assertGreaterThan(0, data_get($payload, 'summary.php_class_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.route_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.runtime_route_count'));
        $this->assertArrayHasKey('rag_retrieval', $payload['topic_clusters']);
        $this->assertArrayHasKey('critical_topic_pressure', $payload);
        $this->assertArrayHasKey('critical_topic_source_material_count', $payload['summary']);
        $this->assertArrayHasKey('source_material_shadow_queue_count', $payload['summary']);
        $this->assertArrayHasKey('status_drift_owner_group_count', $payload['summary']);
        $this->assertArrayHasKey('status_drift_doc_area_group_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_source_material_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_flow_family_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_flow_family_review_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_code_role_count', $payload['summary']);
        $this->assertArrayHasKey('canonical_owner_exists', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('source_material_doc_count', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('code_subareas', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('code_roles', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('flow_families', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('flow_family_review_queue', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('source_material_shadow_queue', $payload['documentation']);
        $this->assertGreaterThan(0, data_get($payload, 'summary.source_material_shadow_queue_count'));
        $sourceMaterialShadow = collect($payload['documentation']['source_material_shadow_queue'])->firstWhere('topic', 'rag_retrieval');
        $this->assertSame('archived_source_material_shadow', $sourceMaterialShadow['kind'] ?? null);
        $this->assertSame('high', $sourceMaterialShadow['severity'] ?? null);
        $this->assertSame('source_material_shadow_not_authority', data_get($sourceMaterialShadow, 'boundary_contract.classification'));
        $this->assertSame('do_not_use_archived_source_material_as_current_runtime_feature_or_flow_owner', data_get($sourceMaterialShadow, 'boundary_contract.forbidden'));
        $graphRetrievalReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'graph_retrieval');
        $this->assertSame('high', $graphRetrievalReview['severity'] ?? null);
        $this->assertArrayHasKey('current_evidence', $graphRetrievalReview);
        $this->assertArrayHasKey('boundary_contract', $graphRetrievalReview);
        $this->assertSame('do_not_delete; document context owner and programming adapter/runtime boundary', data_get($graphRetrievalReview, 'current_evidence.cleanup_bias'));
        $this->assertSame('app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php', data_get($graphRetrievalReview, 'boundary_contract.owner_runtime'));
        $this->assertSame('programming_must_not_become_second_global_graph_retrieval_owner', data_get($graphRetrievalReview, 'boundary_contract.forbidden'));
        $openBrainReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'open_brain');
        $this->assertSame('docs/engineering-knowledge-base/open-brain-context-injection.md', data_get($openBrainReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('open_brain_must_not_author_docs_or_override_canonical_repo_docs', data_get($openBrainReview, 'boundary_contract.forbidden'));
        $pythonRetrievalReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'python_data_retrieval');
        $this->assertSame('docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md', data_get($pythonRetrievalReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('do_not_implement_python_rag_vector_or_ml_runtime_inside_laravel_app_services', data_get($pythonRetrievalReview, 'boundary_contract.forbidden'));
        $contextCompilerReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'context_compiler_cache');
        $this->assertSame('docs/engineering-knowledge-base/atlas-context-compiler-runtime.md', data_get($contextCompilerReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('cache_compiler_must_not_become_second_context_compiler_owner', data_get($contextCompilerReview, 'boundary_contract.forbidden'));
        $memoryRecallReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'memory_recall');
        $this->assertSame('docs/engineering-knowledge-base/memory/retrieval-and-context.md', data_get($memoryRecallReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('memory_recall_must_not_create_parallel_retrieval_policy_or_memory_store', data_get($memoryRecallReview, 'boundary_contract.forbidden'));
        $this->assertIsArray($payload['critical_topic_pressure']['items']);
        $this->assertArrayHasKey('duplicate_route_groups', $payload['code']);
        $this->assertArrayHasKey('runtime_route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_signal_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_area_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_triage_queue', $payload['code']);
        $this->assertArrayHasKey('ai_service_hotspots', $payload['code']);
        $this->assertGreaterThan(0, data_get($payload, 'code.ai_service_hotspots.file_count'));
        $this->assertArrayHasKey('legacy_signal_samples', $payload['code']);
        $this->assertGreaterThan(0, data_get($payload, 'summary.legacy_triage_queue_count'));
        $deprecatedLegacy = collect($payload['code']['legacy_triage_queue'])->firstWhere('signal', 'deprecated');
        $this->assertSame('high', $deprecatedLegacy['severity'] ?? null);
        $this->assertFalse(data_get($deprecatedLegacy, 'cleanup_recommendation.delete_allowed'));
        $this->assertFalse(data_get($deprecatedLegacy, 'cleanup_recommendation.rename_allowed_without_owner_decision'));
        $this->assertContains('reachability', data_get($deprecatedLegacy, 'boundary_contract.evidence_required_before_cleanup'));
        $this->assertSame('do_not_delete_rename_or_reimplement_from_keyword_signal_alone', data_get($deprecatedLegacy, 'boundary_contract.forbidden'));
        $this->assertContains('php artisan atlas:code-reality deletion-preflight --target="'.$deprecatedLegacy['path'].'" --json', $deprecatedLegacy['next_commands']);
        $engineeringLegacy = collect($payload['code']['legacy_triage_queue'])
            ->first(fn (array $item): bool => data_get($item, 'area') === 'app/Services/Engineering');
        $this->assertContains('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', data_get($engineeringLegacy, 'boundary_contract.owner_docs'));
        $this->assertIsArray($payload['triage_queue']);
        $this->assertContains('duplicate_class_name', collect($payload['triage_queue'])->pluck('kind')->all());
        $operationEnvelope = collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:operationenvelope');
        $this->assertSame('critical', $operationEnvelope['severity'] ?? null);
        $this->assertSame('do_not_delete; prefer explicit naming or owner doc boundary before merge', data_get($operationEnvelope, 'current_evidence.cleanup_bias'));
        $this->assertSame('app/Services/Ai/Kernel/Envelope/OperationEnvelope.php', data_get($operationEnvelope, 'boundary_contract.primary_runtime'));
        $this->assertContains('docs/engineering-knowledge-base/kernel/contracts.md', data_get($operationEnvelope, 'boundary_contract.owner_docs'));
        $this->assertSame('atlas.envelope.v1', data_get($operationEnvelope, 'boundary_contract.schema_versions.kernel'));
        $this->assertSame('atlas.dev.operation_envelope.v1', data_get($operationEnvelope, 'boundary_contract.schema_versions.atlas_dev'));
        $this->assertSame('local_pipeline_dto_without_kernel_schema', data_get($operationEnvelope, 'boundary_contract.schema_versions.sdd_pipeline'));
        $this->assertContains('app/Services/Ai/Programming/Sdd/Pipeline/OperationEnvelope.php', data_get($operationEnvelope, 'boundary_contract.specialized_variants'));
        $this->assertSame('do_not_import_specialized_programming_envelope_as_kernel_contract_or_merge_without_adapter_plan', data_get($operationEnvelope, 'boundary_contract.forbidden'));
        $this->assertSame(1, data_get($operationEnvelope, 'cleanup_recommendation.priority'));
        $this->assertFalse(data_get($operationEnvelope, 'cleanup_recommendation.delete_allowed'));
        $this->assertSame('programming_variants_only', data_get($operationEnvelope, 'cleanup_recommendation.rename_candidate'));
        $frontmatterParser = collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:frontmatterparser');
        $this->assertSame(2, data_get($frontmatterParser, 'cleanup_recommendation.priority'));
        $this->assertSame('App\\Services\\Vault\\FrontmatterParser', data_get($frontmatterParser, 'cleanup_recommendation.rename_candidate'));
        $this->assertFalse(data_get($frontmatterParser, 'cleanup_recommendation.merge_allowed_without_owner_decision'));
        $this->assertContains('docs/engineering-knowledge-base/obsidian-atlas-vault.md', data_get($frontmatterParser, 'boundary_contract.owner_docs'));
        $this->assertTrue(data_get($frontmatterParser, 'boundary_contract.contract_features.semantic_parser.returns_errors'));
        $this->assertTrue(data_get($frontmatterParser, 'boundary_contract.contract_features.semantic_parser.builds_markdown'));
        $this->assertFalse(data_get($frontmatterParser, 'boundary_contract.contract_features.vault_parser.returns_errors'));
        $this->assertTrue(data_get($frontmatterParser, 'boundary_contract.contract_features.vault_parser.supports_gear_flow_object_lists'));
        $this->assertSame('do_not_use_vault_parser_as_canonical_engineering_doc_parser', data_get($frontmatterParser, 'boundary_contract.forbidden'));
        $verificationRunner = collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:verificationcommandrunner');
        $this->assertSame('high', $verificationRunner['severity'] ?? null);
        $this->assertSame('atlas.code.verification_run.v1', data_get($verificationRunner, 'boundary_contract.contract_features.atlas_code_runner.schema_version'));
        $this->assertSame('concrete_service', data_get($verificationRunner, 'boundary_contract.contract_features.atlas_code_runner.kind'));
        $this->assertContains('evidence_persistence', data_get($verificationRunner, 'boundary_contract.contract_features.atlas_code_runner.guards'));
        $this->assertSame('interface_contract', data_get($verificationRunner, 'boundary_contract.contract_features.atlas_dev_gate_runner.kind'));
        $this->assertSame('App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\SymfonyProcessCommandRunner', data_get($verificationRunner, 'boundary_contract.contract_features.atlas_dev_gate_runner.implementation'));
        $this->assertContains('UnsafeCommandPolicy', data_get($verificationRunner, 'boundary_contract.contract_features.atlas_dev_gate_runner.guards'));
        $this->assertSame('do_not_swap_interface_and_concrete_runner_by_short_class_name', data_get($verificationRunner, 'boundary_contract.forbidden'));
        $aiExecutionPlan = collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:aiexecutionplan');
        $this->assertSame('medium', $aiExecutionPlan['severity'] ?? null);
        $this->assertSame('app/Models/AiExecutionPlan.php', data_get($aiExecutionPlan, 'boundary_contract.primary_runtime'));
        $this->assertSame('eloquent_model', data_get($aiExecutionPlan, 'boundary_contract.contract_features.persistent_model.kind'));
        $this->assertSame('ai_execution_plans', data_get($aiExecutionPlan, 'boundary_contract.contract_features.persistent_model.table'));
        $this->assertContains('AtlasAutonomousEngineeringService', data_get($aiExecutionPlan, 'boundary_contract.contract_features.persistent_model.primary_consumers'));
        $this->assertSame('prompt_runtime_value_object', data_get($aiExecutionPlan, 'boundary_contract.contract_features.prompt_value_object.kind'));
        $this->assertContains('agent_behavior_contract', data_get($aiExecutionPlan, 'boundary_contract.contract_features.prompt_value_object.required_payload'));
        $this->assertSame('do_not_typehint_value_object_when_database_model_contract_is_required', data_get($aiExecutionPlan, 'boundary_contract.forbidden'));
        $smokeSubject = collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:smokesubject');
        $this->assertSame('low', $smokeSubject['severity'] ?? null);
        $this->assertSame('generated_fixture_inside_smoke_workspace', data_get($smokeSubject, 'boundary_contract.primary_runtime'));
        $this->assertSame('generated_workspace_fixture', data_get($smokeSubject, 'boundary_contract.contract_features.fixture_generators.kind'));
        $this->assertContains('src/SmokeSubject.php', data_get($smokeSubject, 'boundary_contract.contract_features.fixture_generators.generated_files'));
        $this->assertFalse(data_get($smokeSubject, 'boundary_contract.contract_features.production_boundary.persistence'));
        $this->assertFalse(data_get($smokeSubject, 'boundary_contract.contract_features.production_boundary.route_surface'));
        $this->assertSame('do_not_treat_as_production_domain_class', data_get($smokeSubject, 'boundary_contract.forbidden'));
        $observedSessionImport = collect($payload['triage_queue'])->firstWhere('id', 'route_action_alias:app.http.controllers.atlascodeobservedsessioncontroller@import');
        $this->assertSame('medium', $observedSessionImport['severity'] ?? null);
        $this->assertSame('atlas_code_observed_session_import', data_get($observedSessionImport, 'boundary_contract.canonical_owner'));
        $this->assertSame('backward_compatibility_endpoint', data_get($observedSessionImport, 'boundary_contract.alias_family'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md', data_get($observedSessionImport, 'boundary_contract.owner_docs'));
        $this->assertSame('app/Http/Controllers/AtlasCodeObservedSessionController.php', data_get($observedSessionImport, 'boundary_contract.primary_controller'));
        $this->assertSame('post:/atlas-code/works/{project}/observed-sessions/{session}/import', data_get($observedSessionImport, 'boundary_contract.primary_route'));
        $this->assertSame('alias_must_call_same_controller_action_and_return_same_import_contract', data_get($observedSessionImport, 'boundary_contract.parity_rule'));
        $this->assertSame('do_not_add_third_import_endpoint_or_choose_alias_without_owner_decision', data_get($observedSessionImport, 'boundary_contract.forbidden'));
        $voiceMobileAlias = collect($payload['triage_queue'])
            ->where('kind', 'runtime_route_action_alias')
            ->first(fn (array $item): bool => data_get($item, 'boundary_contract.alias_family') === 'voice_realtime_mobile_base_alias');
        $this->assertSame('low', $voiceMobileAlias['severity'] ?? null);
        $this->assertSame('voice_realtime_base_api_action', data_get($voiceMobileAlias, 'boundary_contract.canonical_owner'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md', data_get($voiceMobileAlias, 'boundary_contract.owner_docs'));
        $this->assertSame('mobile_alias_must_remain_same_controller_action_same_auth_same_payload_same_response_contract', data_get($voiceMobileAlias, 'boundary_contract.parity_rule'));
        $this->assertSame('do_not_implement_separate_mobile_business_logic_inside_same_action_without_wrapper_or_owner_doc', data_get($voiceMobileAlias, 'boundary_contract.forbidden'));
        $telemetryMobileAlias = collect($payload['triage_queue'])
            ->where('kind', 'runtime_route_action_alias')
            ->first(fn (array $item): bool => data_get($item, 'boundary_contract.alias_family') === 'telemetry_mobile_base_alias');
        $this->assertSame('telemetry_base_api_action', data_get($telemetryMobileAlias, 'boundary_contract.canonical_owner'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md', data_get($telemetryMobileAlias, 'boundary_contract.owner_docs'));
        $constelacaoMobileAlias = collect($payload['triage_queue'])
            ->where('kind', 'runtime_route_action_alias')
            ->first(fn (array $item): bool => data_get($item, 'boundary_contract.alias_family') === 'constelacao_mobile_base_alias');
        $this->assertSame('constelacao_base_api_action', data_get($constelacaoMobileAlias, 'boundary_contract.canonical_owner'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-constelacao-surface.md', data_get($constelacaoMobileAlias, 'boundary_contract.owner_docs'));
        $this->assertContains($payload['status'], ['ready', 'blocked']);
    }

    public function test_status_drift_audit_surfaces_doc_status_pressure_without_mutating_docs(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->statusDriftAudit();

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::STATUS_DRIFT_AUDIT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('status-drift-audit', $payload['action']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['deletes_files']);
        $this->assertFalse($payload['claim_policy']['declares_doc_wrong_automatically']);
        $this->assertTrue($payload['claim_policy']['requires_owner_review_before_status_change']);
        $this->assertGreaterThan(0, data_get($payload, 'summary.canonical_doc_count'));
        $this->assertArrayHasKey('status_counts', $payload['summary']);
        $this->assertArrayHasKey('owner_group_count', $payload['summary']);
        $this->assertArrayHasKey('doc_area_group_count', $payload['summary']);
        $this->assertArrayHasKey('planned_or_future_with_existing_code_count', $payload['summary']);
        $this->assertArrayHasKey('planned_future_boundary_explained_count', $payload['summary']);
        $this->assertArrayHasKey('scaffold_language_with_existing_code_count', $payload['summary']);
        $this->assertIsArray($payload['owner_groups']);
        $this->assertIsArray($payload['doc_area_groups']);
        $this->assertGreaterThan(0, data_get($payload, 'owner_groups.0.count'));
        $this->assertArrayHasKey('samples', $payload['owner_groups'][0]);
        $this->assertIsArray($payload['review_items']);
        $genericReview = collect($payload['review_items'])
            ->first(fn (array $item): bool => data_get($item, 'boundary_contract.classification') === 'status_language_review_over_existing_evidence');
        $this->assertSame('code_test_command_references_are_review_pressure_not_automatic_status_truth', data_get($genericReview, 'boundary_contract.current_runtime_boundary'));
        $this->assertSame('candidate_evidence_requiring_owner_doc_reachability_tests_and_operator_decision', data_get($genericReview, 'boundary_contract.existing_runtime_refs_are'));
        $this->assertSame('do_not_auto_change_frontmatter_or_claim_doc_wrong_from_heuristic_evidence', data_get($genericReview, 'boundary_contract.forbidden'));
        $this->assertContains('php artisan atlas:code-reality status-drift-audit --json', data_get($genericReview, 'boundary_contract.safe_next_commands'));
        $rivalsLedger = collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-forge-rivals-intelligence-ledger-v1');
        $this->assertSame('planned_next_layer_over_existing_provider_performance_ledger', data_get($rivalsLedger, 'boundary_contract.classification'));
        $this->assertSame('do_not_treat_existing_forge_rivals_ledger_services_as_intelligence_ledger_v1_complete', data_get($rivalsLedger, 'boundary_contract.forbidden'));
        $this->assertContains($payload['status'], ['ready', 'review']);
    }

    public function test_cli_actions_emit_json(): void
    {
        $cases = [
            ['classify', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['usage-map', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['reachability', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['anti-duplicate', ['--feature' => 'documentation reality cartography'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['dead-code-candidates', [], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['deletion-preflight', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::DELETION_PREFLIGHT_SCHEMA_VERSION],
            ['reality-audit', [], AtlasCodeRealityUsageIntelligenceService::REALITY_AUDIT_SCHEMA_VERSION],
            ['context-pack', ['--task' => 'implementar runtime de documentation reality'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
        ];

        foreach ($cases as [$action, $options, $schema]) {
            $exit = Artisan::call('atlas:code-reality', array_merge([
                'action' => $action,
                '--json' => true,
                '--strict' => true,
            ], $options));

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, $action);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse($payload['writes']);
        }

        $exit = Artisan::call('atlas:code-reality', [
            'action' => 'global-duplication-audit',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::GLOBAL_DUPLICATION_AUDIT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse($payload['writes']);

        $exit = Artisan::call('atlas:code-reality', [
            'action' => 'status-drift-audit',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::STATUS_DRIFT_AUDIT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse($payload['writes']);
    }
}
