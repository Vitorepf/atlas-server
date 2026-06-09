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

    public function test_code_reality_summary_json_omits_heavy_sections(): void
    {
        $exit = Artisan::call('atlas:code-reality', [
            'action' => 'classify',
            '--target' => 'app/Console/Commands/AtlasCodeRealityCommand.php',
            '--json' => true,
            '--summary' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('classify', $payload['action']);
        $this->assertTrue(data_get($payload, 'summary_output.enabled'));
        $this->assertArrayNotHasKey('evidence', $payload);
        $this->assertContains('code', data_get($payload, 'summary_output.omits_heavy_sections'));
        $this->assertSame('ready', $payload['status']);
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
        $this->assertArrayHasKey('doc_path_stem_boundary_queue_count', $payload['summary']);
        $this->assertArrayHasKey('documented_doc_path_stem_boundary_queue_count', $payload['summary']);
        $this->assertArrayHasKey('review_doc_path_stem_boundary_queue_count', $payload['summary']);
        $this->assertArrayHasKey('doc_path_stem_retrieval_risk_count', $payload['summary']);
        $this->assertArrayHasKey('duplicate_canonical_doc_id_groups', $payload['documentation']);
        $this->assertArrayHasKey('duplicate_canonical_doc_graph_id_groups', $payload['documentation']);
        $this->assertArrayHasKey('duplicate_canonical_doc_title_groups', $payload['documentation']);
        $this->assertArrayHasKey('duplicate_active_doc_path_stem_groups', $payload['documentation']);
        $this->assertArrayHasKey('doc_path_stem_boundary_queue', $payload['documentation']);
        $this->assertArrayHasKey('documented_doc_path_stem_boundary_queue', $payload['documentation']);
        $this->assertArrayHasKey('review_doc_path_stem_boundary_queue', $payload['documentation']);
        $this->assertSame(
            data_get($payload, 'summary.doc_path_stem_boundary_queue_count'),
            count(data_get($payload, 'documentation.doc_path_stem_boundary_queue'))
        );
        $this->assertSame(
            data_get($payload, 'summary.doc_path_stem_boundary_queue_count'),
            data_get($payload, 'summary.documented_doc_path_stem_boundary_queue_count')
                + data_get($payload, 'summary.review_doc_path_stem_boundary_queue_count')
        );
        $contractsStem = collect($payload['documentation']['doc_path_stem_boundary_queue'])->firstWhere('stem', 'contracts');
        $this->assertSame('area_contract_family', data_get($contractsStem, 'classification.bucket'));
        $this->assertSame('owner_scope_label', data_get($contractsStem, 'classification.cleanup_pressure'));
        $this->assertContains('use_owner_path_and_id_for_retrieval_ranking', data_get($contractsStem, 'cleanup_sequence'));
        $failureModesStem = collect($payload['documentation']['doc_path_stem_boundary_queue'])->firstWhere('stem', 'failure-modes');
        $this->assertSame('failure_modes_family', data_get($failureModesStem, 'classification.bucket'));
        $schemasStem = collect($payload['documentation']['doc_path_stem_boundary_queue'])->firstWhere('stem', 'schemas-and-packets');
        $this->assertSame('schema_packet_family', data_get($schemasStem, 'classification.bucket'));
        $documentedStemBuckets = collect(data_get($payload, 'documentation.documented_doc_path_stem_boundary_queue'))
            ->pluck('classification.bucket')
            ->all();
        $this->assertContains('area_contract_family', $documentedStemBuckets);
        $this->assertContains('area_runbook_family', $documentedStemBuckets);
        $this->assertContains('failure_modes_family', $documentedStemBuckets);
        $this->assertContains('schema_packet_family', $documentedStemBuckets);
        $this->assertContains('roadmap_family', $documentedStemBuckets);
        $this->assertNotContains('area_index_family', $documentedStemBuckets);
        $reviewStemBuckets = collect(data_get($payload, 'documentation.review_doc_path_stem_boundary_queue'))
            ->pluck('classification.bucket')
            ->all();
        $this->assertSame([], $reviewStemBuckets);
        $this->assertSame(0, data_get($payload, 'summary.review_doc_path_stem_boundary_queue_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.php_class_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.route_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.runtime_route_count'));
        $this->assertSame(
            data_get($payload, 'summary.duplicate_class_group_count'),
            data_get($payload, 'summary.generated_fixture_duplicate_class_group_count')
                + data_get($payload, 'summary.blocking_duplicate_class_group_count')
        );
        $this->assertSame(0, data_get($payload, 'summary.blocking_duplicate_class_group_count'));
        $this->assertNull(collect($payload['blockers'])->firstWhere('reason', 'duplicate_php_class_names'));
        $this->assertSame(0, data_get($payload, 'summary.runtime_duplicate_route_name_group_count'));
        $this->assertArrayHasKey('rag_retrieval', $payload['topic_clusters']);
        $this->assertArrayHasKey('critical_topic_pressure', $payload);
        $this->assertArrayHasKey('critical_topic_source_material_count', $payload['summary']);
        $this->assertArrayHasKey('source_material_shadow_queue_count', $payload['summary']);
        $this->assertArrayHasKey('ai_confusion_cleanup_queue_count', $payload['summary']);
        $this->assertArrayHasKey('ai_confusion_cleanup_queue', $payload);
        $this->assertArrayHasKey('status_drift_owner_group_count', $payload['summary']);
        $this->assertArrayHasKey('status_drift_doc_area_group_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_source_material_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_flow_family_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_flow_family_review_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_flow_family_boundary_inventory_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_resolved_boundary_queue_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_cleanup_queue_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_code_role_count', $payload['summary']);
        $this->assertArrayHasKey('frontend_programming_code_match_count', $payload['summary']);
        $this->assertArrayHasKey('frontend_programming_flow_family_count', $payload['summary']);
        $this->assertArrayHasKey('frontend_programming_cleanup_queue_count', $payload['summary']);
        $this->assertArrayHasKey('rag_retrieval_resolved_boundary_queue', $payload);
        $this->assertArrayHasKey('rag_retrieval_cleanup_queue', $payload);
        $this->assertArrayHasKey('frontend_programming_cleanup_queue', $payload);
        $this->assertArrayHasKey('canonical_owner_exists', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('frontend_programming', $payload['topic_clusters']);
        $this->assertArrayHasKey('source_material_doc_count', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('source_material_paths', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('code_subareas', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('code_roles', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('flow_families', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('flow_family_boundary_queue', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('flow_family_review_queue', $payload['topic_clusters']['rag_retrieval']);
        $this->assertArrayHasKey('source_material_shadow_queue', $payload['documentation']);
        $this->assertSame(
            data_get($payload, 'summary.critical_topic_source_material_count'),
            data_get($payload, 'summary.source_material_shadow_queue_count')
        );
        $sourceMaterialShadow = collect($payload['documentation']['source_material_shadow_queue'])->firstWhere('topic', 'rag_retrieval');
        $this->assertSame('archived_source_material_shadow', $sourceMaterialShadow['kind'] ?? null);
        $this->assertSame('high', $sourceMaterialShadow['severity'] ?? null);
        $this->assertSame('source_material_shadow_not_authority', data_get($sourceMaterialShadow, 'boundary_contract.classification'));
        $this->assertTrue(data_get($sourceMaterialShadow, 'boundary_contract.canonical_owner_required'));
        $this->assertSame('below_canonical_owner_and_current_runtime_docs', data_get($sourceMaterialShadow, 'boundary_contract.retrieval_rank'));
        $this->assertContains('never_change_runtime_based_on_archive_alone', data_get($sourceMaterialShadow, 'boundary_contract.promotion_preflight'));
        $this->assertSame('do_not_use_archived_source_material_as_current_runtime_feature_or_flow_owner', data_get($sourceMaterialShadow, 'boundary_contract.forbidden'));
        $this->assertContains('read_canonical_owner_first', data_get($sourceMaterialShadow, 'cleanup_sequence'));
        $this->assertContains('rank_archive_below_current_owner_in_retrieval', data_get($sourceMaterialShadow, 'cleanup_sequence'));
        $this->assertContains('never_use_archive_doc_as_runtime_owner_or_current_feature_status', data_get($sourceMaterialShadow, 'cleanup_sequence'));
        $this->assertContains('test -f "docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md"', data_get($sourceMaterialShadow, 'next_commands'));
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('reason', 'archived_source_material_can_look_duplicate'),
            'Archived source material with resolved canonical owners stays in source_material_shadow_queue, not top-level review items.'
        );
        $this->assertNull(
            collect($payload['ai_confusion_cleanup_queue'])->firstWhere('id', 'ai_confusion:'.$sourceMaterialShadow['id']),
            'Archived source material with a resolved canonical owner stays in documentation.source_material_shadow_queue, not operational AI confusion cleanup.'
        );
        $graphRetrievalReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'graph_retrieval');
        $this->assertSame('high', $graphRetrievalReview['severity'] ?? null);
        $this->assertArrayHasKey('current_evidence', $graphRetrievalReview);
        $this->assertArrayHasKey('boundary_contract', $graphRetrievalReview);
        $this->assertSame('do_not_delete; document context owner and programming adapter/runtime boundary', data_get($graphRetrievalReview, 'current_evidence.cleanup_bias'));
        $this->assertSame('app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php', data_get($graphRetrievalReview, 'boundary_contract.owner_runtime'));
        $this->assertSame('programming_must_not_become_second_global_graph_retrieval_owner', data_get($graphRetrievalReview, 'boundary_contract.forbidden'));
        $this->assertSame('context_owner_with_programming_adapter', data_get($graphRetrievalReview, 'cleanup_classification.bucket'));
        $this->assertContains('keep_atlas_graph_retrieval_network_as_context_owner', data_get($graphRetrievalReview, 'cleanup_sequence'));
        $this->assertContains('forbid_programming_global_graph_retrieval_owner', data_get($graphRetrievalReview, 'cleanup_sequence'));
        $graphRetrievalBoundary = collect($payload['rag_retrieval_resolved_boundary_queue'])->firstWhere('family', 'graph_retrieval');
        $this->assertSame(1, data_get($graphRetrievalBoundary, 'priority'));
        $this->assertSame('rag_retrieval_resolved_boundary', data_get($graphRetrievalBoundary, 'kind'));
        $this->assertSame('resolved_boundary_inventory', data_get($graphRetrievalBoundary, 'status'));
        $this->assertFalse(data_get($graphRetrievalBoundary, 'delete_allowed'));
        $this->assertFalse(data_get($graphRetrievalBoundary, 'new_runtime_allowed_without_owner_decision'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-graph-retrieval-network.md', data_get($graphRetrievalBoundary, 'canonical_owner'));
        $this->assertSame('context_owner_with_programming_adapter', data_get($graphRetrievalBoundary, 'cleanup_classification.bucket'));
        $this->assertSame('reachable', data_get($graphRetrievalBoundary, 'reachability_snapshots.owner_runtime.status'));
        $this->assertContains(data_get($graphRetrievalBoundary, 'reachability_snapshots.owner_runtime.confidence'), ['high', 'medium']);
        $this->assertFalse(data_get($graphRetrievalBoundary, 'reachability_snapshots.owner_runtime.delete_allowed'));
        $this->assertSame('reachable', data_get($graphRetrievalBoundary, 'reachability_snapshots.adapter_or_consumer.status'));
        $this->assertContains(data_get($graphRetrievalBoundary, 'reachability_snapshots.adapter_or_consumer.confidence'), ['high', 'medium']);
        $this->assertSame('reachability_snapshot_guides_owner_review_but_never_authorizes_delete', data_get($graphRetrievalBoundary, 'reachability_snapshots.adapter_or_consumer.claim_policy'));
        $this->assertContains('add_adapter_tests_before_any_rename_or_merge', data_get($graphRetrievalBoundary, 'cleanup_sequence'));
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', data_get($graphRetrievalBoundary, 'next_commands'));
        $this->assertContains('php artisan atlas:code-reality reachability --target="app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php" --json', data_get($graphRetrievalBoundary, 'next_commands'));
        $this->assertContains('php artisan atlas:code-reality reachability --target="app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php" --json', data_get($graphRetrievalBoundary, 'next_commands'));
        $semanticEmbeddingBoundary = collect($payload['rag_retrieval_resolved_boundary_queue'])->firstWhere('family', 'semantic_embedding');
        $this->assertSame('embedding_policy_manifest_chunking_privacy_and_runtime_promotion_gate', data_get($semanticEmbeddingBoundary, 'boundary_contract.owner_role'));
        $this->assertSame('real_semantic_rag_or_openai_embedding_adapter_with_explicit_failure_no_hash_fake', data_get($semanticEmbeddingBoundary, 'boundary_contract.adapter_role'));
        $this->assertSame('atlas.aucri.semantic_embedding_foundation.v1', data_get($semanticEmbeddingBoundary, 'boundary_contract.schema_authority'));
        $this->assertSame('external_or_heavy_embedding_generation_requires_python_ai_data_decision_receipt', data_get($semanticEmbeddingBoundary, 'boundary_contract.promotion_gate'));
        $this->assertContains('route_heavy_embedding_generation_through_python_ai_data_decision_receipt', data_get($semanticEmbeddingBoundary, 'cleanup_sequence'));
        $this->assertSame('reachable', data_get($semanticEmbeddingBoundary, 'reachability_snapshots.owner_runtime.status'));
        $this->assertSame('reachable', data_get($semanticEmbeddingBoundary, 'reachability_snapshots.adapter_or_consumer.status'));
        $localRagReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'local_rag');
        $this->assertSame('benchmark_or_readiness_surface', data_get($localRagReview, 'cleanup_classification.bucket'));
        $this->assertContains('do_not_promote_local_rag_to_primary_runtime_without_owner_decision', data_get($localRagReview, 'cleanup_sequence'));
        $openBrainReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'open_brain');
        $this->assertSame('docs/engineering-knowledge-base/open-brain-context-injection.md', data_get($openBrainReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('open_brain_must_not_author_docs_or_override_canonical_repo_docs', data_get($openBrainReview, 'boundary_contract.forbidden'));
        $this->assertSame('provider_projection_surface', data_get($openBrainReview, 'cleanup_classification.bucket'));
        $this->assertContains('forbid_open_brain_as_authoring_source', data_get($openBrainReview, 'cleanup_sequence'));
        $openBrainBoundary = collect($payload['rag_retrieval_resolved_boundary_queue'])->firstWhere('family', 'open_brain');
        $this->assertSame('provider_projection_surface', data_get($openBrainBoundary, 'cleanup_classification.bucket'));
        $this->assertSame('reachable', data_get($openBrainBoundary, 'reachability_snapshots.adapter_or_consumer.status'));
        $pythonRetrievalReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'python_data_retrieval');
        $this->assertSame('docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md', data_get($pythonRetrievalReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('do_not_implement_python_rag_vector_or_ml_runtime_inside_laravel_app_services', data_get($pythonRetrievalReview, 'boundary_contract.forbidden'));
        $this->assertSame('runtime_language_boundary', data_get($pythonRetrievalReview, 'cleanup_classification.bucket'));
        $this->assertContains('forbid_laravel_vector_or_ml_runtime_fork', data_get($pythonRetrievalReview, 'cleanup_sequence'));
        $pythonRetrievalBoundary = collect($payload['rag_retrieval_resolved_boundary_queue'])->firstWhere('family', 'python_data_retrieval');
        $this->assertSame('runtime_language_boundary', data_get($pythonRetrievalBoundary, 'cleanup_classification.bucket'));
        $contextCompilerReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'context_compiler_cache');
        $this->assertSame('docs/engineering-knowledge-base/atlas-context-compiler-runtime.md', data_get($contextCompilerReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('cache_compiler_must_not_become_second_context_compiler_owner', data_get($contextCompilerReview, 'boundary_contract.forbidden'));
        $this->assertContains('keep_context_compiler_runtime_as_source_composition_owner', data_get($contextCompilerReview, 'cleanup_sequence'));
        $retrievalFeedbackReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'retrieval_feedback');
        $this->assertSame('docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md', data_get($retrievalFeedbackReview, 'boundary_contract.canonical_owner'));
        $this->assertContains('keep_atlas_retrieval_feedback_loop_as_feedback_contract_owner', data_get($retrievalFeedbackReview, 'cleanup_sequence'));
        $retrievalFeedbackBoundary = collect($payload['rag_retrieval_resolved_boundary_queue'])->firstWhere('family', 'retrieval_feedback');
        $this->assertSame('retrieval_feedback_event_context_roi_noise_missed_ref_and_learning_candidate_contract', data_get($retrievalFeedbackBoundary, 'boundary_contract.owner_role'));
        $this->assertSame('persisted_compounding_feedback_consumer_with_deterministic_next_retrieval_hint', data_get($retrievalFeedbackBoundary, 'boundary_contract.adapter_role'));
        $this->assertSame('atlas.aucri.retrieval_feedback_loop.v1', data_get($retrievalFeedbackBoundary, 'boundary_contract.schema_authority'));
        $this->assertSame('atlas.ai.rag.feedback.v1', data_get($retrievalFeedbackBoundary, 'boundary_contract.consumer_schema'));
        $this->assertSame('reachable', data_get($retrievalFeedbackBoundary, 'reachability_snapshots.owner_runtime.status'));
        $this->assertSame('reachable', data_get($retrievalFeedbackBoundary, 'reachability_snapshots.adapter_or_consumer.status'));
        $contextPackBoundary = collect($payload['rag_retrieval_resolved_boundary_queue'])->firstWhere('family', 'context_pack');
        $this->assertSame('base_context_pack_composition_memory_retrieval_privacy_and_prompt_context_contract', data_get($contextPackBoundary, 'boundary_contract.owner_role'));
        $this->assertSame('programming_domain_persistence_and_replay_store_for_existing_context_pack_payloads', data_get($contextPackBoundary, 'boundary_contract.adapter_role'));
        $this->assertSame('App\\Services\\Ai\\ValueObjects\\AiContextPack', data_get($contextPackBoundary, 'boundary_contract.schema_authority'));
        $this->assertSame('atlas_programming_context_packs', data_get($contextPackBoundary, 'boundary_contract.storage_table'));
        $this->assertContains('forbid_programming_store_redefining_base_context_pack_contract', data_get($contextPackBoundary, 'cleanup_sequence'));
        $this->assertSame('reachable', data_get($contextPackBoundary, 'reachability_snapshots.owner_runtime.status'));
        $this->assertSame('reachable', data_get($contextPackBoundary, 'reachability_snapshots.adapter_or_consumer.status'));
        $contextRankingReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'context_ranking_rerank');
        $this->assertContains('keep_context_ranking_system_as_global_policy_owner', data_get($contextRankingReview, 'cleanup_sequence'));
        $memoryRecallReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'memory_recall');
        $this->assertSame('docs/engineering-knowledge-base/memory/retrieval-and-context.md', data_get($memoryRecallReview, 'boundary_contract.canonical_owner'));
        $this->assertSame('memory_recall_must_not_create_parallel_retrieval_policy_or_memory_store', data_get($memoryRecallReview, 'boundary_contract.forbidden'));
        $this->assertContains('keep_memory_recall_as_query_surface_over_memory_contracts', data_get($memoryRecallReview, 'cleanup_sequence'));
        $hybridRetrievalReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'hybrid_retrieval');
        $this->assertContains('keep_hybrid_retrieval_under_unified_context_retrieval_contract', data_get($hybridRetrievalReview, 'cleanup_sequence'));
        $agenticRagReview = collect($payload['topic_clusters']['rag_retrieval']['flow_family_review_queue'])->firstWhere('family', 'agentic_rag');
        $this->assertContains('forbid_agentic_rag_parallel_memory_store', data_get($agenticRagReview, 'cleanup_sequence'));
        $this->assertIsArray($payload['critical_topic_pressure']['items']);
        $this->assertSame(0, data_get($payload, 'summary.rag_retrieval_flow_family_review_count'));
        $this->assertSame(0, data_get($payload, 'summary.rag_retrieval_cleanup_queue_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.rag_retrieval_resolved_boundary_queue_count'));
        $this->assertSame(
            data_get($payload, 'summary.rag_retrieval_resolved_boundary_queue_count'),
            data_get($payload, 'summary.rag_retrieval_flow_family_boundary_inventory_count')
        );
        $this->assertSame([], $payload['rag_retrieval_cleanup_queue']);
        $this->assertNotContains('critical_topic_pressure', collect($payload['ai_confusion_cleanup_queue'])->pluck('kind')->all());
        $this->assertNull(
            collect($payload['ai_confusion_cleanup_queue'])->firstWhere('id', 'ai_confusion:critical_topic:rag_retrieval'),
            'Resolved RAG owner boundaries stay in boundary inventory, not operational AI confusion cleanup.'
        );
        $frontendReviewQueue = collect($payload['topic_clusters']['frontend_programming']['flow_family_review_queue']);
        $documentedFrontendPipelines = [
            'frontend_benchmark_proof' => 'frontend_competitive_proof_pipeline',
            'frontend_evidence_certification' => 'frontend_evidence_certification_pipeline',
            'frontend_workspace_control' => 'frontend_workspace_selection_pipeline',
            'frontend_live_mode' => 'frontend_live_mode_pipeline',
            'frontend_design_quality' => 'frontend_quality_gate_pipeline',
        ];
        foreach ($documentedFrontendPipelines as $family => $bucket) {
            $frontendReview = $frontendReviewQueue->firstWhere('family', $family);
            $this->assertNotNull($frontendReview);
            $this->assertSame('docs/engineering-knowledge-base/domains/programming-frontend-superpower.md', data_get($frontendReview, 'boundary_contract.canonical_owner'));
            $this->assertSame($bucket, data_get($frontendReview, 'cleanup_classification.bucket'));
            $this->assertNull(
                collect($payload['frontend_programming_cleanup_queue'])->firstWhere('family', $family),
                'Documented frontend pipeline boundaries remain in review inventory, not operational cleanup.'
            );
        }
        $frontendBenchmarkReview = $frontendReviewQueue->firstWhere('family', 'frontend_benchmark_proof');
        $this->assertSame('app/Services/Ai/Programming/Frontend/AtlasFrontendPrivateBenchmarkProofPlanService.php', data_get($frontendBenchmarkReview, 'boundary_contract.owner_runtime'));
        $this->assertSame('app/Services/Ai/Programming/Frontend/AtlasFrontendWorldBestProofPlanService.php', data_get($frontendBenchmarkReview, 'boundary_contract.adapter_or_consumer'));
        $this->assertSame('atlas:frontend:private-benchmark-plan', data_get($frontendBenchmarkReview, 'boundary_contract.canonical_command'));
        $this->assertSame('atlas:frontend:world-best-plan', data_get($frontendBenchmarkReview, 'boundary_contract.legacy_alias_command'));
        $this->assertContains('keep_world_best_plan_as_legacy_alias_with_public_superiority_claims_disabled', data_get($frontendBenchmarkReview, 'cleanup_sequence'));
        $frontendEvidenceReview = $frontendReviewQueue->firstWhere('family', 'frontend_evidence_certification');
        $this->assertContains('keep_run_certify_as_measured_artifact_gate', data_get($frontendEvidenceReview, 'cleanup_sequence'));
        $this->assertSame(0, data_get($payload, 'summary.frontend_programming_cleanup_queue_count'));
        $this->assertNull(
            collect($payload['ai_confusion_cleanup_queue'])->firstWhere('id', 'ai_confusion:critical_topic:frontend_programming'),
            'Frontend documented stage boundaries should not inflate operational AI confusion cleanup.'
        );
        $this->assertNull(
            collect($payload['ai_confusion_cleanup_queue'])->firstWhere('source', 'doc_path_stem_boundary_queue'),
            'Documented area-scoped doc stem families should remain boundary inventory, not AI confusion cleanup.'
        );
        $this->assertNotContains('legacy_operational_cleanup_pressure', collect($payload['ai_confusion_cleanup_queue'])->pluck('kind')->all());
        $this->assertArrayHasKey('duplicate_route_groups', $payload['code']);
        $this->assertArrayHasKey('runtime_route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('documented_static_route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('review_static_route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('confirmed_duplicate_route_groups', $payload['code']);
        $this->assertArrayHasKey('prefix_blind_static_route_duplicate_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_signal_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_area_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_signal_inventory', $payload['code']);
        $this->assertArrayHasKey('legacy_triage_queue', $payload['code']);
        $this->assertArrayHasKey('legacy_operational_groups', $payload['code']);
        $this->assertArrayHasKey('legacy_operational_summary', $payload['code']);
        $this->assertArrayHasKey('generated_fixture_duplicate_class_groups', $payload['code']);
        $this->assertArrayHasKey('generated_fixture_duplicate_class_boundary_queue', $payload['code']);
        $this->assertArrayHasKey('blocking_duplicate_class_groups', $payload['code']);
        $this->assertArrayHasKey('duplicate_class_cleanup_queue', $payload['code']);
        $this->assertArrayHasKey('ai_service_hotspots', $payload['code']);
        $this->assertGreaterThan(0, data_get($payload, 'code.ai_service_hotspots.file_count'));
        $this->assertArrayHasKey('legacy_signal_samples', $payload['code']);
        $this->assertArrayHasKey('runtime_root_api_compatibility_alias_groups', $payload['code']);
        $this->assertArrayHasKey('runtime_documented_route_action_alias_groups', $payload['code']);
        $this->assertArrayHasKey('runtime_documented_route_alias_boundary_queue', $payload['code']);
        $this->assertArrayHasKey('runtime_review_route_action_alias_groups', $payload['code']);
        $this->assertSame(
            data_get($payload, 'summary.route_action_alias_group_count'),
            data_get($payload, 'summary.documented_static_route_action_alias_group_count')
                + data_get($payload, 'summary.review_static_route_action_alias_group_count')
        );
        $this->assertSame(0, data_get($payload, 'summary.review_static_route_action_alias_group_count'));
        $this->assertCount(0, data_get($payload, 'code.review_static_route_action_alias_groups'));
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('reason', 'route_action_aliases_present'),
            'Static route-action aliases already classified by runtime must not create a second review queue.'
        );
        $documentedStaticAliasBuckets = collect(data_get($payload, 'code.documented_static_route_action_alias_groups'))
            ->pluck('static_alias_classification.bucket')
            ->all();
        $this->assertContains('telemetry_mobile_base_alias', $documentedStaticAliasBuckets);
        $this->assertContains('prefix_blind_static_route_action_alias', $documentedStaticAliasBuckets);
        $this->assertContains('backward_compatibility_endpoint', $documentedStaticAliasBuckets);
        $this->assertGreaterThan(0, data_get($payload, 'summary.runtime_root_api_compatibility_alias_group_count'));
        $this->assertSame(
            data_get($payload, 'summary.runtime_route_action_alias_group_count'),
            data_get($payload, 'summary.runtime_root_api_compatibility_alias_group_count')
                + data_get($payload, 'summary.runtime_documented_route_action_alias_group_count')
                + data_get($payload, 'summary.runtime_review_route_action_alias_group_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.runtime_documented_route_action_alias_group_count'),
            data_get($payload, 'summary.runtime_documented_route_alias_boundary_queue_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.runtime_review_route_action_alias_group_count'),
            data_get($payload, 'summary.runtime_route_alias_cleanup_queue_count')
        );
        $this->assertSame(0, data_get($payload, 'summary.runtime_review_route_action_alias_group_count'));
        $this->assertSame(0, data_get($payload, 'summary.runtime_route_alias_cleanup_queue_count'));
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('reason', 'runtime_route_action_aliases_present'),
            'Documented runtime aliases belong in the boundary queue, not owner-review cleanup.'
        );
        $healthRootApiAlias = collect(data_get($payload, 'code.runtime_root_api_compatibility_alias_groups'))
            ->firstWhere('value', 'app.http.controllers.healthcontroller');
        $this->assertContains('get:/health', data_get($healthRootApiAlias, 'method_uris'));
        $this->assertContains('get:/api/health', data_get($healthRootApiAlias, 'method_uris'));
        $this->assertNull(
            collect(data_get($payload, 'code.runtime_route_alias_cleanup_queue'))
                ->firstWhere('action', 'app.http.controllers.healthcontroller'),
            'Root/API compatibility wrapper aliases are documented transport aliases, not owner-review cleanup items.'
        );
        $this->assertSame(
            data_get($payload, 'summary.generated_fixture_duplicate_class_group_count'),
            count(data_get($payload, 'code.generated_fixture_duplicate_class_groups'))
        );
        $this->assertSame(
            data_get($payload, 'summary.generated_fixture_duplicate_class_group_count'),
            data_get($payload, 'summary.generated_fixture_duplicate_class_boundary_queue_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.blocking_duplicate_class_group_count'),
            count(data_get($payload, 'code.blocking_duplicate_class_groups'))
        );
        $this->assertNull(
            collect($payload['code']['runtime_duplicate_route_name_groups'])->firstWhere('value', 'api.'),
            'The /api route wrapper name prefix is not a meaningful registered route name by itself.'
        );
        $this->assertSame(0, data_get($payload, 'summary.confirmed_duplicate_route_group_count'));
        $this->assertSame(
            data_get($payload, 'summary.duplicate_route_group_count'),
            data_get($payload, 'summary.prefix_blind_static_route_duplicate_group_count')
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('reason', 'literal_route_method_uri_overlap'),
            'Prefix-blind static route overlaps must not be promoted to owner-review when route:list has no runtime duplicate.'
        );
        $this->assertLessThanOrEqual(
            data_get($payload, 'summary.duplicate_class_group_count'),
            data_get($payload, 'summary.duplicate_class_cleanup_queue_count')
        );
        $this->assertNull(
            collect($payload['code']['duplicate_class_cleanup_queue'])->firstWhere('short_name', 'operationenvelope'),
            'Programming OperationEnvelope variants now use explicit real classes; only the Kernel keeps OperationEnvelope as the canonical envelope.'
        );
        $this->assertTrue(class_exists('App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\AtlasDevOperationEnvelope'));
        $this->assertTrue(is_a(
            'App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\OperationEnvelope',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\AtlasDevOperationEnvelope',
            true
        ));
        $this->assertTrue(class_exists('App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\SddPipelineOperationEnvelope'));
        $this->assertTrue(is_a(
            'App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\OperationEnvelope',
            'App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\SddPipelineOperationEnvelope',
            true
        ));
        $this->assertNull(
            collect($payload['code']['duplicate_class_cleanup_queue'])->firstWhere('short_name', 'frontmatterparser'),
            'Vault frontmatter parser was renamed to VaultNoteFrontmatterParser; old FrontmatterParser remains compatibility alias only.'
        );
        $this->assertStringContainsString(
            'VaultNoteFrontmatterParser $parser',
            File::get(base_path('app/Services/Vault/ObsidianVaultReader.php')),
        );
        $this->assertStringContainsString(
            'VaultNoteFrontmatterParser $parser',
            File::get(base_path('app/Services/Vault/RepoVaultReader.php')),
        );
        $this->assertNull(
            collect($payload['code']['duplicate_class_cleanup_queue'])->firstWhere('short_name', 'verificationcommandrunner'),
            'VerificationCommandRunner variants now use explicit real names with old FQCNs kept only as compatibility aliases.'
        );
        $this->assertNull(
            collect($payload['code']['duplicate_class_cleanup_queue'])->firstWhere('short_name', 'aiexecutionplan'),
            'AiExecutionPlan variants now use explicit real classes with old FQCNs kept only as compatibility aliases.'
        );
        $this->assertNull(
            collect($payload['code']['duplicate_class_cleanup_queue'])->firstWhere('short_name', 'smokesubject'),
            'Generated SmokeSubject workspace fixtures are documented in triage, but must not enter production duplicate cleanup.'
        );
        $this->assertGreaterThan(0, data_get($payload, 'summary.legacy_signal_inventory_count'));
        $this->assertSame(0, data_get($payload, 'summary.legacy_triage_queue_count'));
        $this->assertSame(0, data_get($payload, 'summary.legacy_cleanup_queue_count'));
        $this->assertSame(0, data_get($payload, 'summary.legacy_cleanup_high_risk_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.legacy_operational_group_count'));
        $this->assertSame(0, data_get($payload, 'summary.legacy_cleanup_review_count'));
        $this->assertSame(0, data_get($payload, 'summary.legacy_documentation_review_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.legacy_documentation_inventory_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.legacy_keyword_inventory_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.legacy_false_positive_or_taxonomy_count'));
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('reason', 'legacy_or_scaffold_signals_present'),
            'Raw legacy/scaffold keyword count must stay in summary/code, not in review_items as if all signals were cleanup.'
        );
        $legacyCompatibilityReview = collect($payload['review_items'])
            ->firstWhere('reason', 'legacy_compatibility_adapter_cleanup_candidates_present');
        $this->assertNull(
            $legacyCompatibilityReview,
            'Compatibility adapter cleanup review should disappear once only documented wrappers and taxonomy signals remain.'
        );
        $legacyDocumentationReview = collect($payload['review_items'])
            ->firstWhere('reason', 'legacy_documentation_review_signals_present');
        $this->assertNull(
            $legacyDocumentationReview,
            'Comment/docblock legacy words are preserved as inventory, not review_items.'
        );
        $this->assertSame(
            data_get($payload, 'summary.legacy_cleanup_queue_count'),
            count(data_get($payload, 'code.legacy_cleanup_queue'))
        );
        $this->assertSame(
            data_get($payload, 'summary.legacy_cleanup_review_count'),
            data_get($payload, 'code.legacy_operational_summary.cleanup_review_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.legacy_documentation_review_count'),
            data_get($payload, 'code.legacy_operational_summary.documentation_review_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.legacy_documentation_inventory_count'),
            data_get($payload, 'code.legacy_operational_summary.documentation_inventory_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.legacy_keyword_inventory_count'),
            data_get($payload, 'code.legacy_operational_summary.keyword_inventory_count')
        );
        $this->assertSame(
            data_get($payload, 'summary.legacy_false_positive_or_taxonomy_count'),
            data_get($payload, 'code.legacy_operational_summary.false_positive_or_taxonomy_count')
        );
        $this->assertSame('summary_prioritizes_cleanup_review_and_preserves_inventory_but_never_authorizes_delete_without_preflight', data_get($payload, 'code.legacy_operational_summary.claim_policy'));
        $documentationInventoryGroup = collect($payload['code']['legacy_operational_groups'])->firstWhere('bucket', 'commentary_or_documentation_language');
        $this->assertSame('inventory', data_get($documentationInventoryGroup, 'cleanup_pressure'));
        $this->assertSame('low', data_get($documentationInventoryGroup, 'ia_confusion_risk'));
        $keywordInventoryGroup = collect($payload['code']['legacy_operational_groups'])->firstWhere('bucket', 'runtime_keyword_inventory');
        $this->assertSame('inventory', data_get($keywordInventoryGroup, 'cleanup_pressure'));
        $this->assertSame('medium', data_get($keywordInventoryGroup, 'ia_confusion_risk'));
        $scaffoldOperationalGroup = collect($payload['code']['legacy_operational_groups'])->firstWhere('bucket', 'scaffold_taxonomy_or_guardrail');
        $this->assertSame('none', data_get($scaffoldOperationalGroup, 'cleanup_pressure'));
        $this->assertSame('medium', data_get($scaffoldOperationalGroup, 'ia_confusion_risk'));
        $this->assertContains('scaffold_status_or_literal', collect(data_get($scaffoldOperationalGroup, 'subtypes'))->pluck('value')->all());
        $parkedScaffoldGroup = collect($payload['code']['legacy_operational_groups'])->firstWhere('bucket', 'parked_scaffold_contract');
        $this->assertSame('none', data_get($parkedScaffoldGroup, 'cleanup_pressure'));
        $this->assertContains('kernel_pipeline_scaffold', collect(data_get($parkedScaffoldGroup, 'subtypes'))->pluck('value')->all());
        $this->assertContains('runtime_provenance_signature', collect(data_get($scaffoldOperationalGroup, 'subtypes'))->pluck('value')->all());
        $architectureMatrixReference = collect($payload['code']['legacy_signal_inventory'])
            ->firstWhere('path', 'app/Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php');
        $this->assertSame('diagnostic_architecture_matrix_reference', data_get($architectureMatrixReference, 'operational_classification.bucket'));
        $this->assertSame('none', data_get($architectureMatrixReference, 'operational_classification.cleanup_pressure'));
        $this->assertSame('implemented_vs_scaffold_matrix_is_diagnostic_read_model_not_legacy_runtime', data_get($architectureMatrixReference, 'operational_classification.safe_interpretation'));
        $kernelPipelineScaffold = collect($payload['code']['legacy_signal_inventory'])
            ->firstWhere('path', 'app/Services/Ai/Kernel/Pipeline/ScaffoldAtlasKernelPipeline.php');
        $this->assertSame('parked_scaffold_contract', data_get($kernelPipelineScaffold, 'operational_classification.bucket'));
        $this->assertSame('kernel_pipeline_scaffold', data_get($kernelPipelineScaffold, 'operational_classification.subtype'));
        $statusOperationalGroup = collect($payload['code']['legacy_operational_groups'])->firstWhere('bucket', 'status_taxonomy_value');
        $this->assertSame('none', data_get($statusOperationalGroup, 'cleanup_pressure'));
        $deprecatedLegacy = collect($payload['code']['legacy_signal_inventory'])->firstWhere('signal', 'deprecated');
        $this->assertSame('high', $deprecatedLegacy['severity'] ?? null);
        $this->assertIsInt($deprecatedLegacy['line'] ?? null);
        $this->assertNotEmpty($deprecatedLegacy['excerpt'] ?? null);
        $this->assertContains($deprecatedLegacy['source_type'] ?? null, ['docblock', 'comment', 'string_literal', 'code']);
        $this->assertContains(
            data_get($deprecatedLegacy, 'operational_classification.bucket'),
            ['status_taxonomy_value', 'lifecycle_taxonomy_value', 'canonical_schema_evolution_field_lifecycle']
        );
        $this->assertSame('none', data_get($deprecatedLegacy, 'operational_classification.cleanup_pressure'));
        $this->assertContains(
            data_get($deprecatedLegacy, 'operational_classification.safe_interpretation'),
            [
                'allowed_status_or_filter_value_not_dead_code',
                'deprecated_is_lifecycle_taxonomy_or_schema_contract_not_deprecated_runtime_code',
                'deprecated_fields_are_schema_lifecycle_payload_not_deprecated_runtime_code',
            ]
        );
        $this->assertFalse(data_get($deprecatedLegacy, 'cleanup_recommendation.delete_allowed'));
        $this->assertFalse(data_get($deprecatedLegacy, 'cleanup_recommendation.rename_allowed_without_owner_decision'));
        $this->assertContains('reachability', data_get($deprecatedLegacy, 'boundary_contract.evidence_required_before_cleanup'));
        $this->assertSame('do_not_delete_rename_or_reimplement_from_keyword_signal_alone', data_get($deprecatedLegacy, 'boundary_contract.forbidden'));
        $this->assertContains('php artisan atlas:code-reality deletion-preflight --target="'.$deprecatedLegacy['path'].'" --json', $deprecatedLegacy['next_commands']);
        $this->assertNull(
            collect($payload['code']['legacy_cleanup_queue'])->firstWhere('bucket', 'scaffold_taxonomy_or_guardrail'),
            'Scaffold taxonomy, gates, roadmap and guardrail language must not enter code cleanup.'
        );
        $compatibilityCleanup = collect($payload['code']['legacy_cleanup_queue'])->firstWhere('bucket', 'compatibility_adapter_code');
        $this->assertNull(
            $compatibilityCleanup,
            'Compatibility adapter code should not remain in cleanup once internal legacy naming is removed and public wrappers are boundary-only.'
        );
        $engineeringLegacy = collect($payload['code']['legacy_signal_inventory'])
            ->first(fn (array $item): bool => data_get($item, 'area') === 'app/Services/Engineering');
        $this->assertContains('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', data_get($engineeringLegacy, 'boundary_contract.owner_docs'));
        $duplicateRuntimeLanguage = collect($payload['code']['legacy_signal_inventory'])
            ->firstWhere('path', 'app/Services/Tools/AtlasToolFindingCorrelationService.php');
        $this->assertSame('deduplication_runtime_language', data_get($duplicateRuntimeLanguage, 'operational_classification.bucket'));
        $this->assertSame('code_is_about_detecting_duplicates_not_itself_duplicate_by_keyword', data_get($duplicateRuntimeLanguage, 'operational_classification.safe_interpretation'));
        $this->assertIsArray($payload['triage_queue']);
        $this->assertIsArray($payload['ai_confusion_cleanup_queue']);
        $this->assertSame(0, data_get($payload, 'summary.ai_confusion_cleanup_queue_count'));
        $legacyAiConfusion = collect($payload['ai_confusion_cleanup_queue'])->firstWhere('id', 'ai_confusion:legacy_bucket:compatibility_adapter_code');
        $this->assertNull(
            $legacyAiConfusion,
            'Compatibility adapter code should not remain as AI confusion cleanup after canonical wrappers and internal variable cleanup.'
        );
        $this->assertNull(
            collect($payload['ai_confusion_cleanup_queue'])->firstWhere('id', 'ai_confusion:legacy_bucket:scaffold_taxonomy_or_guardrail'),
            'Scaffold taxonomy must remain a boundary signal, not AI cleanup pressure.'
        );
        $this->assertNull(
            collect(data_get($payload, 'code.legacy_cleanup_queue'))->firstWhere('id', 'legacy_cleanup:app:Services:Ai:AiSkillStore:php:715:todo'),
            'Portuguese prose using "todo" must not be treated as a TODO marker or legacy cleanup signal.'
        );
        $this->assertNull(
            collect(data_get($payload, 'code.legacy_cleanup_queue'))->firstWhere('id', 'legacy_cleanup:app:Console:Commands:AtlasScaffoldStageCommand:php:12:scaffold'),
            'AtlasScaffoldStageCommand is the canonical Self-Construction staging command; scaffold in this name must not be treated as legacy cleanup.'
        );
        $scaffoldStageSignal = collect(data_get($payload, 'code.legacy_signal_inventory'))
            ->firstWhere('id', 'legacy_signal:scaffold:app:Console:Commands:AtlasScaffoldStageCommand:php:12');
        $this->assertSame('canonical_self_construction_scaffold_staging_runtime', data_get($scaffoldStageSignal, 'operational_classification.bucket'));
        $this->assertSame('none', data_get($scaffoldStageSignal, 'operational_classification.cleanup_pressure'));
        $this->assertSame(
            0,
            collect(data_get($payload, 'code.legacy_cleanup_queue'))
                ->where('path', 'app/Services/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorService.php')
                ->count(),
            'Self-Construction Scaffold Staging Executor is active runtime with command, tests and owner docs; scaffold payload fields are not legacy cleanup.'
        );
        $this->assertSame(
            0,
            collect(data_get($payload, 'code.legacy_cleanup_queue'))
                ->where('path', 'app/Services/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionService.php')
                ->where('signal', 'deprecated')
                ->count(),
            'ACMF schema evolution uses deprecated_fields as canonical lifecycle payload; it must not be treated as deprecated runtime code.'
        );
        $schemaEvolutionDeprecatedSignal = collect(data_get($payload, 'code.legacy_signal_inventory'))
            ->firstWhere('id', 'legacy_signal:deprecated:app:Services:Ai:Cognition:AtlasCognitiveMemoryFabricSchemaEvolutionService:php:177');
        $this->assertSame('canonical_schema_evolution_field_lifecycle', data_get($schemaEvolutionDeprecatedSignal, 'operational_classification.bucket'));
        $this->assertSame('none', data_get($schemaEvolutionDeprecatedSignal, 'operational_classification.cleanup_pressure'));
        $this->assertNull(
            collect($payload['ai_confusion_cleanup_queue'])->firstWhere('id', 'ai_confusion:duplicate_class:operationenvelope'),
            'Resolved OperationEnvelope duplicate should not remain in AI confusion cleanup.'
        );
        $this->assertNull(
            collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:operationenvelope'),
            'Resolved OperationEnvelope duplicate should not remain in duplicate triage.'
        );
        $this->assertNull(collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:frontmatterparser'));
        $this->assertTrue(class_exists('App\\Services\\Semantic\\CanonicalDocsFrontmatterParser'));
        $this->assertTrue(is_a(
            'App\\Services\\Semantic\\CanonicalDocsFrontmatterParser',
            'App\\Services\\Semantic\\FrontmatterParser',
            true
        ));
        $this->assertTrue(class_exists('App\\Services\\Vault\\VaultNoteFrontmatterParser'));
        $this->assertTrue(class_exists('App\\Services\\Vault\\FrontmatterParser'));
        $this->assertNull(
            collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:verificationcommandrunner'),
            'Resolved VerificationCommandRunner duplicate should not remain in duplicate triage.'
        );
        $this->assertTrue(class_exists('App\\Services\\AtlasCode\\AtlasCodeVerificationCommandRunner'));
        $this->assertTrue(is_a(
            'App\\Services\\AtlasCode\\VerificationCommandRunner',
            'App\\Services\\AtlasCode\\AtlasCodeVerificationCommandRunner',
            true
        ));
        $this->assertTrue(interface_exists('App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\AtlasDevVerificationCommandRunnerContract'));
        $this->assertTrue(is_a(
            'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\VerificationCommandRunner',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\AtlasDevVerificationCommandRunnerContract',
            true
        ));
        $this->assertNull(
            collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:aiexecutionplan'),
            'Resolved AiExecutionPlan duplicate should not remain in duplicate triage.'
        );
        $this->assertTrue(class_exists('App\\Models\\PersistentAiExecutionPlan'));
        $this->assertTrue(is_a(
            'App\\Models\\AiExecutionPlan',
            'App\\Models\\PersistentAiExecutionPlan',
            true
        ));
        $this->assertTrue(class_exists('App\\Services\\Ai\\ValueObjects\\AiPromptExecutionPlan'));
        $this->assertTrue(is_a(
            'App\\Services\\Ai\\ValueObjects\\AiExecutionPlan',
            'App\\Services\\Ai\\ValueObjects\\AiPromptExecutionPlan',
            true
        ));
        $this->assertNull(
            collect($payload['triage_queue'])->firstWhere('id', 'duplicate_class:smokesubject'),
            'Generated SmokeSubject workspace fixtures are documented boundaries, not production duplicate triage.'
        );
        $smokeSubject = collect(data_get($payload, 'code.generated_fixture_duplicate_class_boundary_queue'))
            ->firstWhere('short_name', 'smokesubject');
        $this->assertSame('low', $smokeSubject['severity'] ?? null);
        $this->assertSame('generated_fixture_duplicate_class_boundary', data_get($smokeSubject, 'kind'));
        $this->assertSame('documented_generated_fixture_boundary', data_get($smokeSubject, 'status'));
        $this->assertSame('generated_fixture_inside_smoke_workspace', data_get($smokeSubject, 'boundary_contract.primary_runtime'));
        $this->assertSame('generated_workspace_fixture', data_get($smokeSubject, 'boundary_contract.contract_features.fixture_generators.kind'));
        $this->assertContains('src/SmokeSubject.php', data_get($smokeSubject, 'boundary_contract.contract_features.fixture_generators.generated_files'));
        $this->assertFalse(data_get($smokeSubject, 'boundary_contract.contract_features.production_boundary.persistence'));
        $this->assertFalse(data_get($smokeSubject, 'boundary_contract.contract_features.production_boundary.route_surface'));
        $this->assertContains('never_create_repo_production_class_for_smokesubject', data_get($smokeSubject, 'boundary_contract.cleanup_sequence'));
        $this->assertFalse(data_get($smokeSubject, 'boundary_contract.generator_write_boundary.writes_repo_production_code'));
        $this->assertTrue(data_get($smokeSubject, 'boundary_contract.generator_write_boundary.writes_local_smoke_workspace_only'));
        $this->assertSame('smoke_fixture_proves_patch_apply_and_verification_flow_not_atlas_feature_runtime', data_get($smokeSubject, 'boundary_contract.generator_write_boundary.evidence_scope'));
        $this->assertSame('do_not_treat_as_production_domain_class', data_get($smokeSubject, 'boundary_contract.forbidden'));
        $this->assertSame('generated_fixture_duplicate_class_is_boundary_inventory_not_production_duplicate_triage', data_get($smokeSubject, 'claim_policy'));
        $observedSessionImport = collect(data_get($payload, 'code.runtime_documented_route_alias_boundary_queue'))
            ->firstWhere('action', 'app.http.controllers.atlascodeobservedsessioncontroller@import');
        $this->assertSame('medium', $observedSessionImport['severity'] ?? null);
        $this->assertSame('runtime_route_alias_boundary', data_get($observedSessionImport, 'kind'));
        $this->assertSame('documented_backward_compatibility_alias', data_get($observedSessionImport, 'status'));
        $this->assertSame('atlas_code_observed_session_import', data_get($observedSessionImport, 'boundary_contract.canonical_owner'));
        $this->assertSame('backward_compatibility_endpoint', data_get($observedSessionImport, 'boundary_contract.alias_family'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md', data_get($observedSessionImport, 'boundary_contract.owner_docs'));
        $this->assertSame('app/Http/Controllers/AtlasCodeObservedSessionController.php', data_get($observedSessionImport, 'boundary_contract.primary_controller'));
        $this->assertSame('post:/atlas-code/works/{project}/observed-sessions/{session}/import', data_get($observedSessionImport, 'boundary_contract.primary_route'));
        $this->assertSame('alias_must_call_same_controller_action_and_return_same_import_contract', data_get($observedSessionImport, 'boundary_contract.parity_rule'));
        $this->assertContains('keep_import_as_primary_observed_session_result_ingest_route', data_get($observedSessionImport, 'boundary_contract.cleanup_sequence'));
        $this->assertContains('never_add_third_import_ingest_route_without_owner_decision', data_get($observedSessionImport, 'boundary_contract.cleanup_sequence'));
        $this->assertSame('single_controller_action_no_branching_business_logic_by_route_path', data_get($observedSessionImport, 'boundary_contract.alias_boundary.logic_owner'));
        $this->assertSame('do_not_add_third_import_endpoint_or_choose_alias_without_owner_decision', data_get($observedSessionImport, 'boundary_contract.forbidden'));
        $voiceMobileAlias = collect(data_get($payload, 'code.runtime_documented_route_alias_boundary_queue'))
            ->where('kind', 'runtime_route_alias_boundary')
            ->first(fn (array $item): bool => data_get($item, 'boundary_contract.alias_family') === 'voice_realtime_mobile_base_alias');
        $this->assertSame('low', $voiceMobileAlias['severity'] ?? null);
        $this->assertSame('documented_mobile_transport_alias', data_get($voiceMobileAlias, 'status'));
        $this->assertSame('voice_realtime_base_api_action', data_get($voiceMobileAlias, 'boundary_contract.canonical_owner'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md', data_get($voiceMobileAlias, 'boundary_contract.owner_docs'));
        $this->assertSame('mobile_alias_must_remain_same_controller_action_same_auth_same_payload_same_response_contract', data_get($voiceMobileAlias, 'boundary_contract.parity_rule'));
        $this->assertContains('keep_base_api_route_as_business_logic_owner', data_get($voiceMobileAlias, 'boundary_contract.cleanup_sequence'));
        $this->assertSame('mobile_transport_alias', data_get($voiceMobileAlias, 'boundary_contract.alias_boundary.mobile_route_intent'));
        $this->assertSame('do_not_implement_separate_mobile_business_logic_inside_same_action_without_wrapper_or_owner_doc', data_get($voiceMobileAlias, 'boundary_contract.forbidden'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.runtime_documented_route_alias_boundary_queue_count'));
        $voiceMobileAliasCleanup = collect(data_get($payload, 'code.runtime_documented_route_alias_boundary_queue'))
            ->first(fn (array $item): bool => data_get($item, 'alias_family') === 'voice_realtime_mobile_base_alias');
        $this->assertSame('runtime_route_alias_boundary', data_get($voiceMobileAliasCleanup, 'kind'));
        $this->assertSame('documented_mobile_transport_alias', data_get($voiceMobileAliasCleanup, 'status'));
        $this->assertTrue(data_get($voiceMobileAliasCleanup, 'same_action_not_duplicate_method_uri'));
        $this->assertSame(0, data_get($voiceMobileAliasCleanup, 'runtime_duplicate_route_group_count'));
        $this->assertFalse(data_get($voiceMobileAliasCleanup, 'cleanup_policy.delete_allowed'));
        $this->assertFalse(data_get($voiceMobileAliasCleanup, 'cleanup_policy.new_route_allowed_without_owner_decision'));
        $this->assertSame('documented_alias_boundary_no_cleanup', data_get($voiceMobileAliasCleanup, 'cleanup_policy.classification'));
        $this->assertSame('documented_same_action_alias_is_boundary_inventory_not_cleanup_permission', data_get($voiceMobileAliasCleanup, 'claim_policy'));
        $telemetryMobileAlias = collect(data_get($payload, 'code.runtime_documented_route_alias_boundary_queue'))
            ->where('kind', 'runtime_route_alias_boundary')
            ->first(fn (array $item): bool => data_get($item, 'boundary_contract.alias_family') === 'telemetry_mobile_base_alias');
        $this->assertSame('telemetry_base_api_action', data_get($telemetryMobileAlias, 'boundary_contract.canonical_owner'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md', data_get($telemetryMobileAlias, 'boundary_contract.owner_docs'));
        $constelacaoMobileAlias = collect(data_get($payload, 'code.runtime_documented_route_alias_boundary_queue'))
            ->where('kind', 'runtime_route_alias_boundary')
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
        $this->assertIsArray($payload['review_items']);
        $this->assertSame(0, data_get($payload, 'summary.review_count'));
        $this->assertSame(0, data_get($payload, 'summary.owner_group_count'));
        $this->assertSame(0, data_get($payload, 'summary.doc_area_group_count'));
        $this->assertSame([], $payload['review_items']);
        $this->assertSame([], $payload['owner_groups']);
        $this->assertSame([], $payload['doc_area_groups']);
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-aaeos-l7-l10-governed-ladder-backlog'),
            'AAEOS L7-L10 is authority_class=backlog with no_runtime_proof; target class/test refs are slice inputs, not runtime proof.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-aaeos-loop-evolution-backlog'),
            'AAEOS loop backlog is backlog-only mixed readiness; code refs inside rows must not promote the document status.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-aaeos-documentation-as-law-proposal'),
            'Documentation-as-Law is an explicit proposal_no_runtime; dependency refs are analysis targets, not runtime delivery proof.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-aaeos-loop-failure-diagnosis-and-remediation'),
            'AAEOS loop failure diagnosis is an active runbook with diagnosis_and_plan_no_runtime; runtime refs are remediation targets, not proof that the plan is fixed.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-fase3-worker-repair-loop-escalation-meaningful-tests'),
            'FASE 3 worker repair loop is an explicit spec_only no-runtime-change contract; harness refs are acceptance targets, not runtime delivery proof.'
        );
        foreach ([
            'status_drift:atlas-software-company-stewardship-stack',
            'status_drift:atlas-stewardship-evolution-ladder',
            'status_drift:atlas-area-stewardship-layer',
            'status_drift:atlas-autonomous-software-company-night-shift-product-mode',
        ] as $partialRuntimeDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $partialRuntimeDocId),
                'Partial stewardship runtime docs keep future scope language, but implementation_state=partial_runtime_with_future_scope explains the boundary.'
            );
        }
        foreach ([
            'status_drift:atlas-afef-semantic-gap-finder',
            'status_drift:atlas-afef-build-plan',
            'status_drift:atlas-frontier-evolution-foundry',
        ] as $noRuntimePlannerDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $noRuntimePlannerDocId),
                'AFEF planner/proposer docs are explicit future_spec_no_runtime_yet boundaries; existing Foundry/Stewardship refs are dependencies, not delivery proof.'
            );
        }
        foreach ([
            'status_drift:atlas-decide-meta-learning-loop-closure',
            'status_drift:atlas-ai-self-construction-os',
            'status_drift:atlas-programming-governance-system-runbook',
        ] as $activeBoundaryDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $activeBoundaryDocId),
                'Active gated runtime, partial runtime, and runbook docs carry explicit frontmatter boundaries for future/backlog language.'
            );
        }
        foreach ([
            'status_drift:atlas-documentation-reality-evolution-ladder',
            'status_drift:atlas-documentation-reality-generative-leap',
            'status_drift:atlas-documentation-reality-outcome-grounded-leap',
            'status_drift:atlas-documentation-reality-reflective-self-model',
        ] as $northStarDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $northStarDocId),
                'ADRS north-star docs are explicit north_star_no_runtime_yet boundaries; docs-health/architecture commands are gates, not runtime proof.'
            );
        }
        foreach ([
            'status_drift:atlas-token-economy-runtime',
            'status_drift:atlas-context-compiler-runtime',
            'status_drift:atlas-memory-core-runbook',
        ] as $contextMemoryDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $contextMemoryDocId),
                'Context/memory docs now carry explicit partial-runtime or runbook boundaries; future/provider language is not stale status drift.'
            );
        }
        foreach ([
            'status_drift:atlas-antigravity-sdk-governed-executor-v1',
            'status_drift:atlas-code-forge-fast-path-v1',
            'status_drift:atlas-code-multi-project-claude-one-shot-prompt',
        ] as $programmingBoundaryDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $programmingBoundaryDocId),
                'Programming/Forge docs now declare fail-closed, partial-runtime, or prompt/runbook boundaries instead of looking like stale status drift.'
            );
        }
        foreach ([
            'status_drift:atlas-ai-documentation-operating-system',
            'status_drift:atlas-documentation-reality-bidirectional-reconciliation',
            'status_drift:atlas-documentation-reality-generative-self-healing',
            'status_drift:atlas-documentation-reality-outcome-grounded-truth',
            'status_drift:atlas-documentation-reality-self-immunizing-antibody',
            'status_drift:atlas-documentation-reality-code-contract-proposals',
            'status_drift:atlas-documentation-reality-implementation-blueprint',
            'status_drift:atlas-documentation-reality-block-upgrade-map',
            'status_drift:legacy-documentation-cleanup-plan',
        ] as $documentationGovernanceBoundaryDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $documentationGovernanceBoundaryDocId),
                'Documentation-governance docs now declare partial-runtime, planner, or runbook no-runtime boundaries; doc repair vocabulary is not stale status drift.'
            );
        }
        foreach ([
            'status_drift:atlas-aurg-temporal-4d',
            'status_drift:atlas-aemor-judgment-learning-guard',
            'status_drift:atlas-loop-extreme-quality-gate',
            'status_drift:atlas-self-construction-scaffold-staging-executor',
            'status_drift:atlas-ai-cognitive-multiplier-edge',
            'status_drift:atlas-cognitive-memory-fabric',
            'status_drift:atlas-retrieval-cost-latency-governor',
            'status_drift:atlas-external-memory-pattern-absorptions-v1',
            'status_drift:atlas-retrieval-privacy-trust-layer',
            'status_drift:atlas-retrieval-evaluation-benchmark-arena',
            'status_drift:atlas-context-observability-plane',
            'status_drift:atlas-knowledge-ingestion-fabric',
            'status_drift:atlas-workspace-artifact-fabric',
            'status_drift:atlas-swarm-executor',
            'status_drift:atlas-ai-cyber-security-extension',
            'status_drift:atlas-subsystem-auto-rebalance',
            'status_drift:atlas-ai-cyber-flow-profiles-proposal',
            'status_drift:atlas-continuity-intelligence-os',
            'status_drift:atlas-context-pareto-frontier-runtime',
            'status_drift:surface-domain-catalog-integration-plan',
            'status_drift:atlas-forge-rivals-intelligence-ledger-v1',
            'status_drift:atlas-cartographic-knowledge-os',
            'status_drift:atlas-vox-v4-contextual-operator-plan',
        ] as $explicitImplementationStateDocId) {
            $this->assertNull(
                collect($payload['review_items'])->firstWhere('id', $explicitImplementationStateDocId),
                'Docs with explicit implementation_state contracts should not re-enter status drift review only because their body names runtime plus future/planned scope.'
            );
        }
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-evidence-certification-runtime'),
            'Evidence runtime uses missing_requirements as a canonical field; that must not be treated as scaffold drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-teos-existing-code-map-part-01'),
            'TEOS inventory event names such as recovery_planned are canonical signals, not planned/future scaffold language.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-execution-doctrine-runtime-matrix'),
            'Execution doctrine runtime matrix intentionally mixes per-block implemented/planned states and must not be treated as document-level drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-domain-strategy-runtime'),
            'Strategy Runtime uses canonical gate identifiers such as experiment-planned; hyphenated identifiers must not be treated as planned scaffold language.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-duplication-reality-governance'),
            'Duplication governance is the owner policy for implemented/planned/scaffold vocabulary; that vocabulary must not count as doc-level drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-canonical-cleanup-inventory'),
            'Canonical cleanup inventory intentionally mixes historical status, live ACRUI queues and cleanup vocabulary; it is an inventory boundary, not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-runtime-spine-completion-audit'),
            'Runtime Spine Completion Audit is an active audit matrix with explicit readiness evidence and historical boundary; PASS/PARTIAL/blocked vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-ai-research-self-improvement-runtime'),
            'Research Self-Improvement Runtime intentionally mixes implemented Meta 8A runtime with future automation boundaries; source-gate vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-pre-benchmark-readiness-audit'),
            'Pre-Benchmark Readiness Audit intentionally mixes internal readiness with external benchmark blockers; benchmark_not_run boundary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-code-reality-usage-intelligence'),
            'ACRUI owns active/scaffold/legacy/future/planned classification vocabulary; its own taxonomy must not be treated as stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-documentation-reality-system'),
            'ADRS owns documentation lifecycle vocabulary such as active/scaffold/future/legacy/quarantine/deleted; that taxonomy must not be treated as stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-documentation-enforcement-runtime'),
            'ADER owns ready/review/blocked pre-implementation gate language and is integrated into architecture operations/session bootstrap; that contract vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-quality-preserving-efficiency-system'),
            'AQPES has active certify/shadow/resources runtime with explicit non-default rollout boundary; phase vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-persistent-context-runtime'),
            'APCR is locally certified across Hyperflow, Gateway, Dev, Forge, Control Plane and memory guard; next-action vocabulary is maintenance, not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-execution-memory-outcome-runtime'),
            'AEMOR owns outcome and memory lifecycle states such as blocked/candidate/trusted/stale/archived; that vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-workspace-intelligence-system'),
            'AWIS has active runtime evidence and graph_status must not remain planned once the runtime gate is ready.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-kernel-mission-foundation'),
            'Mission Foundation owns mission lifecycle enums such as draft/planned/running; lifecycle status vocabulary is not document-level scaffold drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-ai-follow-through-loop'),
            'Follow-Through Loop owns mission cycle states such as planned/running/blocked/simulated/handoff; lifecycle vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-ai-router-runtime-enterprise-upgrade'),
            'Router Runtime owns dispatch states such as simulated/planned/blocked; dispatch vocabulary is not document-level scaffold drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-aiworker-kernel-integration-adr'),
            'AiWorker Kernel Integration ADR is an active authority doc with explicit partial implementation boundary; phase vocabulary is not stale document status drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-autonomous-control-plane'),
            'Control Plane owns mission/control-plane runtime states such as planned/blocked/completed; state vocabulary and future UI backlog are not stale backend documentation.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-ai-programming-enterprise-implementation-plan'),
            'Programming Enterprise plan is active local runtime documentation with an explicit external Rivals completion boundary; phase/blocked vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-programming-superiority-contracts'),
            'Programming contracts owns schema/status vocabulary and gap boundary language; that catalog vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-programming-forge-flow'),
            'Programming Forge Flow is the active taxonomy/flow authority and its per-area state matrix is not document-level implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-ai-programming-agentic-rag-professional-spec'),
            'Programming Agentic RAG uses ordinary Portuguese gap language like falta owner; that must not be treated as scaffold drift without explicit scaffold/planned/future status language.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-long-horizon-intelligence-layer'),
            'Long Horizon doc is an active local TEOS-I1 runtime authority with explicit benchmark_not_run boundary; roadmap vocabulary is not stale implementation drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-self-construction-agent-dispatch-planner-runtime-v1'),
            'Agent Dispatch Planner owns status: planned as a runtime output enum; that enum is not stale future/scaffold documentation language.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-forge-live-execution-e2e-v1'),
            'Forge Live Execution owns canonical stage/plan vocabulary and has active AWIS/AUCRI/live-execute runtime coverage; those terms are not stale future/scaffold drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-universal-reality-cartography'),
            'AURC owns visual state vocabulary such as active/scaffold/future/legacy; that legend is not stale implementation status drift.'
        );
        $this->assertNull(
            collect($payload['review_items'])->firstWhere('id', 'status_drift:atlas-forge-rivals-intelligence-ledger-v1'),
            'Forge Rivals Intelligence Ledger declares proposed_next_patamar_not_promoted; provider ledger refs are dependency boundaries, not stale status drift.'
        );
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
