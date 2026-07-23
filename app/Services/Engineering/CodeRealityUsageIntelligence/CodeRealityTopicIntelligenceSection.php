<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;

use Illuminate\Support\Facades\File;

final class CodeRealityTopicIntelligenceSection
{
    public function __construct(
        private readonly CodeRealityPrimitives $primitives,
        private readonly CodeRealityFlowFamilySection $flowFamily,
    ) {}


    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $code
     * @return array<string,array<string,mixed>>
     */
    public function topicClusters(array $docs, array $code): array
    {
        $clusters = [];
        foreach (CodeRealityPrimitives::CRITICAL_TOPIC_CLUSTERS as $id => $definition) {
            $docMatches = array_values(array_filter(
                (array) $docs['items'],
                static fn (array $doc): bool => in_array($id, (array) ($doc['critical_topics'] ?? []), true)
            ));
            $codeMatches = $this->primitives->codeMatchesForTerms((array) $definition['terms']);
            $canonicalDocs = array_values(array_filter($docMatches, static fn (array $doc): bool => $doc['canonical'] === true && $doc['archive'] === false));
            $sourceMaterialDocs = array_values(array_filter($docMatches, static fn (array $doc): bool => $doc['archive'] === true));
            $nonCanonicalActiveDocs = array_values(array_filter(
                $docMatches,
                static fn (array $doc): bool => $doc['canonical'] === false
                    && $doc['archive'] === false
                    && ! in_array($doc['status'], ['archived', 'source_material'], true)
            ));
            $flowFamilies = $this->flowFamily->flowFamilyGroups($codeMatches, $id);
            $flowFamilyBoundaryQueue = $this->flowFamily->flowFamilyReviewQueue($id, $flowFamilies);
            $sourceMaterialPaths = array_map(static fn (array $doc): string => (string) $doc['path'], $sourceMaterialDocs);
            $clusters[$id] = [
                'canonical_owner' => $definition['canonical_owner'],
                'canonical_owner_exists' => File::exists(base_path($definition['canonical_owner'])),
                'doc_count' => count($docMatches),
                'canonical_doc_count' => count($canonicalDocs),
                'source_material_doc_count' => count($sourceMaterialDocs),
                'non_canonical_doc_count' => count($nonCanonicalActiveDocs),
                'code_match_count' => count($codeMatches),
                'doc_samples' => array_slice(array_map(static fn (array $doc): string => (string) $doc['path'], $docMatches), 0, 12),
                'canonical_doc_samples' => array_slice(array_map(static fn (array $doc): string => (string) $doc['path'], $canonicalDocs), 0, 12),
                'source_material_paths' => $sourceMaterialPaths,
                'source_material_samples' => array_slice($sourceMaterialPaths, 0, 12),
                'noncanonical_active_samples' => array_slice(array_map(static fn (array $doc): string => (string) $doc['path'], $nonCanonicalActiveDocs), 0, 12),
                'code_samples' => array_slice($codeMatches, 0, 12),
                'code_subareas' => $this->codeMatchSubareas($codeMatches),
                'code_roles' => $this->codeRoleGroups($codeMatches),
                'flow_families' => $flowFamilies,
                'flow_family_boundary_queue' => $flowFamilyBoundaryQueue,
                'flow_family_review_queue' => $flowFamilyBoundaryQueue,
                'policy' => 'use_canonical_owner_before_creating_parallel_flow',
            ];
        }

        return $clusters;
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<string,mixed>
     */
    public function criticalTopicPressure(array $topicClusters): array
    {
        $items = [];
        foreach ($topicClusters as $topic => $cluster) {
            $sourceMaterial = (int) ($cluster['source_material_doc_count'] ?? 0);
            $nonCanonical = (int) ($cluster['non_canonical_doc_count'] ?? 0);
            $codeMatches = (int) ($cluster['code_match_count'] ?? 0);
            $items[] = [
                'topic' => $topic,
                'canonical_owner' => (string) ($cluster['canonical_owner'] ?? ''),
                'canonical_owner_exists' => (bool) ($cluster['canonical_owner_exists'] ?? false),
                'source_material_doc_count' => $sourceMaterial,
                'noncanonical_active_doc_count' => $nonCanonical,
                'code_match_count' => $codeMatches,
                'pressure_score' => ($sourceMaterial * 3) + ($nonCanonical * 5) + min($codeMatches, 100),
                'risk' => match (true) {
                    $nonCanonical > 0 => 'noncanonical_active_docs_can_compete_with_owner',
                    $sourceMaterial > 0 => 'archived_source_material_can_confuse_retrieval_or_ai_context',
                    $codeMatches > 40 => 'broad_code_surface_requires_owner_lookup_before_new_flow',
                    default => 'normal_owner_lookup_required',
                },
                'required_decision' => 'read_canonical_owner_then_mark_source_material_as_context_only_or_promote_by_owner_decision',
                'source_material_samples' => array_slice((array) ($cluster['source_material_samples'] ?? []), 0, 8),
                'code_subareas' => array_slice((array) ($cluster['code_subareas'] ?? []), 0, 8),
                'flow_families' => array_slice((array) ($cluster['flow_families'] ?? []), 0, 10),
                'flow_family_review_queue' => array_slice((array) ($cluster['flow_family_review_queue'] ?? []), 0, 8),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ((int) $b['pressure_score']) <=> ((int) $a['pressure_score']));

        return [
            'source_material_count' => array_sum(array_map(static fn (array $item): int => (int) $item['source_material_doc_count'], $items)),
            'noncanonical_active_count' => array_sum(array_map(static fn (array $item): int => (int) $item['noncanonical_active_doc_count'], $items)),
            'items' => $items,
            'policy' => 'source_material_and_code_matches_are_triage_pressure_not_authority_or_delete_permission',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<int,array<string,mixed>>
     */
    public function ragRetrievalCleanupQueue(array $topicClusters): array
    {
        return $this->ragRetrievalBoundaryQueue($topicClusters, false);
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<int,array<string,mixed>>
     */
    public function ragRetrievalResolvedBoundaryQueue(array $topicClusters): array
    {
        return $this->ragRetrievalBoundaryQueue($topicClusters, true);
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<int,array<string,mixed>>
     */
    private function ragRetrievalBoundaryQueue(array $topicClusters, bool $resolved): array
    {
        $reviewQueue = (array) data_get($topicClusters, 'rag_retrieval.flow_family_review_queue', []);
        $items = [];

        foreach ($reviewQueue as $item) {
            $family = (string) ($item['family'] ?? 'unknown');
            $cleanupClassification = (array) ($item['cleanup_classification'] ?? []);
            $contract = (array) ($item['boundary_contract'] ?? []);
            $isResolvedBoundary = $this->ragRetrievalResolvedBoundary($cleanupClassification, $contract);
            if ($resolved !== $isResolvedBoundary) {
                continue;
            }

            $items[] = $this->ragRetrievalBoundaryQueueItem($item, $contract, $cleanupClassification, $resolved);
        }

        usort($items, static function (array $a, array $b): int {
            return ((int) $a['priority']) <=> ((int) $b['priority'])
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $items;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $cleanupClassification
     * @return array<string,mixed>
     */
    private function ragRetrievalBoundaryQueueItem(array $item, array $contract, array $cleanupClassification, bool $resolved): array
    {
        $family = (string) ($item['family'] ?? 'unknown');
        $ownerRuntime = (string) ($contract['owner_runtime'] ?? '');
        $adapter = (string) ($contract['adapter_or_consumer'] ?? '');
        $nextCommands = [
            'php artisan atlas:code-reality global-duplication-audit --json',
            'php artisan atlas:ai:runtime-boundary --json',
        ];

        foreach ([$ownerRuntime, $adapter] as $target) {
            if (str_starts_with($target, 'app/')) {
                $nextCommands[] = 'php artisan atlas:code-reality reachability --target="'.$target.'" --json';
            }
        }

        return [
            'id' => ($resolved ? 'rag_retrieval_boundary:' : 'rag_retrieval_cleanup:').$family,
            'kind' => $resolved ? 'rag_retrieval_resolved_boundary' : 'rag_retrieval_flow_boundary_cleanup',
            'family' => $family,
            'severity' => (string) ($item['severity'] ?? 'review'),
            'priority' => $this->ragRetrievalCleanupPriority($family),
            'status' => $resolved ? 'resolved_boundary_inventory' : 'owner_boundary_review_required',
            'count' => (int) ($item['count'] ?? 0),
            'canonical_owner' => (string) ($contract['canonical_owner'] ?? 'owner_review_required'),
            'owner_runtime' => $ownerRuntime,
            'adapter_or_consumer' => $adapter,
            'current_evidence' => (array) ($item['current_evidence'] ?? []),
            'boundary_contract' => $contract,
            'reachability_snapshots' => [
                'owner_runtime' => $this->targetReachabilitySnapshot($ownerRuntime),
                'adapter_or_consumer' => $this->targetReachabilitySnapshot($adapter),
            ],
            'cleanup_classification' => $cleanupClassification,
            'cleanup_sequence' => (array) ($item['cleanup_sequence'] ?? []),
            'safe_next_action' => $resolved
                ? 'reuse_documented_boundary_before_new_retrieval_runtime'
                : (string) ($item['required_decision'] ?? 'document_owner_boundary_before_code_cleanup'),
            'delete_allowed' => false,
            'new_runtime_allowed_without_owner_decision' => false,
            'claim_policy' => $resolved
                ? 'resolved_boundary_inventory_prevents_parallel_runtime_creation_without_counting_as_cleanup'
                : 'cleanup_queue_prioritizes_boundary_or_rename_review_not_dead_code_claims',
            'next_commands' => $this->primitives->uniqueStrings($nextCommands),
        ];
    }

    /**
     * @param  array<string,mixed>  $cleanupClassification
     * @param  array<string,mixed>  $contract
     */
    private function ragRetrievalResolvedBoundary(array $cleanupClassification, array $contract): bool
    {
        $bucket = (string) ($cleanupClassification['bucket'] ?? '');
        $canonicalOwner = (string) ($contract['canonical_owner'] ?? '');
        $ownerRuntime = (string) ($contract['owner_runtime'] ?? '');

        return in_array($bucket, [
            'context_owner_with_programming_adapter',
            'benchmark_or_readiness_surface',
            'feedback_owner_handoff',
            'context_pack_contract_vs_storage',
            'runtime_language_boundary',
            'provider_projection_surface',
            'orchestration_or_surface_not_owner',
        ], true)
            && str_starts_with($canonicalOwner, 'docs/')
            && $ownerRuntime !== ''
            && $ownerRuntime !== 'owner_review_required';
    }

    private function ragRetrievalCleanupPriority(string $family): int
    {
        return match ($family) {
            'graph_retrieval' => 1,
            'semantic_embedding' => 2,
            'retrieval_feedback' => 3,
            'context_pack' => 4,
            'local_rag' => 5,
            'context_ranking_rerank' => 6,
            'python_data_retrieval' => 7,
            'open_brain' => 8,
            default => 50,
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<int,array<string,mixed>>
     */
    public function frontendProgrammingCleanupQueue(array $topicClusters): array
    {
        $reviewQueue = (array) data_get($topicClusters, 'frontend_programming.flow_family_review_queue', []);
        $items = [];

        foreach ($reviewQueue as $item) {
            $family = (string) ($item['family'] ?? 'unknown');
            if (! str_starts_with($family, 'frontend_')) {
                continue;
            }

            $contract = (array) ($item['boundary_contract'] ?? []);
            $cleanupClassification = (array) ($item['cleanup_classification'] ?? []);
            if ($this->frontendProgrammingDocumentedPipelineBoundary($cleanupClassification, $contract)) {
                continue;
            }

            $targets = $this->primitives->uniqueStrings([
                (string) ($contract['owner_runtime'] ?? ''),
                (string) ($contract['adapter_or_consumer'] ?? ''),
                (string) ($contract['supporting_runtime'] ?? ''),
                (string) ($contract['handoff_runtime'] ?? ''),
                (string) ($contract['control_plane_runtime'] ?? ''),
                (string) ($contract['selection_runtime'] ?? ''),
                (string) ($contract['review_runtime'] ?? ''),
            ], filterEmpty: true);
            $nextCommands = [
                'php artisan atlas:code-reality global-duplication-audit --json',
                'php artisan atlas:documentation:enforce --task="<task>" --feature="programming.frontend" --strict --json',
            ];

            foreach ($targets as $target) {
                if (str_starts_with($target, 'app/')) {
                    $nextCommands[] = 'php artisan atlas:code-reality reachability --target="'.$target.'" --json';
                }
            }

            $items[] = [
                'id' => 'frontend_programming_cleanup:'.$family,
                'kind' => 'frontend_programming_flow_boundary_cleanup',
                'family' => $family,
                'severity' => (string) ($item['severity'] ?? 'review'),
                'priority' => $this->frontendProgrammingCleanupPriority($family),
                'status' => 'owner_boundary_review_required',
                'count' => (int) ($item['count'] ?? 0),
                'canonical_owner' => (string) ($contract['canonical_owner'] ?? 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md'),
                'owner_runtime' => (string) ($contract['owner_runtime'] ?? ''),
                'adapter_or_consumer' => (string) ($contract['adapter_or_consumer'] ?? ''),
                'current_evidence' => (array) ($item['current_evidence'] ?? []),
                'boundary_contract' => $contract,
                'reachability_snapshots' => array_map(
                    fn (string $target): array => $this->targetReachabilitySnapshot($target),
                    array_values(array_filter($targets, static fn (string $target): bool => str_starts_with($target, 'app/')))
                ),
                'cleanup_classification' => $cleanupClassification,
                'cleanup_sequence' => (array) ($item['cleanup_sequence'] ?? []),
                'safe_next_action' => (string) ($item['required_decision'] ?? 'document_frontend_flow_boundary_before_cleanup'),
                'delete_allowed' => false,
                'new_flow_allowed_without_owner_decision' => false,
                'claim_policy' => 'frontend_cleanup_queue_is_boundary_review_not_dead_code_or_delivery_proof',
                'next_commands' => $this->primitives->uniqueStrings($nextCommands),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return ((int) $a['priority']) <=> ((int) $b['priority'])
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $items;
    }

    /**
     * @param  array<string,mixed>  $cleanupClassification
     * @param  array<string,mixed>  $contract
     */
    private function frontendProgrammingDocumentedPipelineBoundary(array $cleanupClassification, array $contract): bool
    {
        $bucket = (string) ($cleanupClassification['bucket'] ?? '');

        return in_array($bucket, [
            'frontend_competitive_proof_pipeline',
            'frontend_evidence_certification_pipeline',
            'frontend_workspace_selection_pipeline',
            'frontend_live_mode_pipeline',
            'frontend_quality_gate_pipeline',
        ], true)
            && (string) ($contract['canonical_owner'] ?? '') === 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md'
            && (string) ($contract['owner_runtime'] ?? '') !== '';
    }

    private function frontendProgrammingCleanupPriority(string $family): int
    {
        return match ($family) {
            'frontend_benchmark_proof' => 1,
            'frontend_evidence_certification' => 2,
            'frontend_workspace_control' => 3,
            'frontend_live_mode' => 4,
            'frontend_design_quality' => 5,
            default => 50,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $groups
     * @return array<int,array<string,mixed>>
     */
    public function docPathStemBoundaryQueue(array $groups): array
    {
        $items = [];

        foreach ($groups as $group) {
            $stem = (string) ($group['value'] ?? 'unknown');
            $sampleItems = (array) ($group['sample_items'] ?? []);
            $paths = array_values(array_map('strval', (array) ($group['paths'] ?? [])));
            $owners = $this->primitives->itemStringColumn($sampleItems, 'owner', filterEmpty: true);
            sort($owners);
            $criticalTopics = $this->primitives->itemStringListColumn($sampleItems, 'critical_topics');
            sort($criticalTopics);
            $retrievalRisk = in_array('rag_retrieval', $criticalTopics, true);
            $classification = $this->docPathStemClassification($stem, $owners);

            $items[] = [
                'id' => 'doc_path_stem_boundary:'.$stem,
                'kind' => 'active_doc_path_stem_boundary',
                'stem' => $stem,
                'severity' => $retrievalRisk ? 'medium' : 'low',
                'count' => (int) ($group['count'] ?? count($paths)),
                'paths' => $paths,
                'owners' => $owners,
                'critical_topics' => $criticalTopics,
                'retrieval_confusion_risk' => $retrievalRisk,
                'classification' => $classification,
                'boundary_contract' => [
                    'classification' => $classification['bucket'],
                    'allowed_use' => 'area_scoped_navigation_after_owner_and_path_match',
                    'required_key' => 'path_plus_frontmatter_id_plus_owner_not_filename_stem',
                    'forbidden' => 'do_not_select_canonical_owner_by_filename_stem_alone',
                ],
                'cleanup_sequence' => [
                    'keep_area_scoped_generic_names_when_frontmatter_ids_are_unique',
                    'use_owner_path_and_id_for_retrieval_ranking',
                    'rename_only_if_owner_decides_filename_stem_confuses_humans_or_agents',
                    'never_delete_doc_because_readme_contracts_or_runbook_stem_repeats',
                ],
                'delete_allowed' => false,
                'rename_allowed_without_owner_decision' => false,
                'claim_policy' => 'path_stem_overlap_is_navigation_pressure_not_canonical_doc_duplication',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['medium' => 0, 'low' => 1];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docPathStemBoundaryQueue
     * @return array<int,array<string,mixed>>
     */
    public function documentedDocPathStemBoundaryQueue(array $docPathStemBoundaryQueue): array
    {
        return array_values(array_filter(
            $docPathStemBoundaryQueue,
            fn (array $item): bool => ! $this->docPathStemRequiresReview($item)
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $docPathStemBoundaryQueue
     * @return array<int,array<string,mixed>>
     */
    public function reviewDocPathStemBoundaryQueue(array $docPathStemBoundaryQueue): array
    {
        return array_values(array_filter(
            $docPathStemBoundaryQueue,
            fn (array $item): bool => $this->docPathStemRequiresReview($item)
        ));
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function docPathStemRequiresReview(array $item): bool
    {
        return in_array(
            (string) data_get($item, 'classification.cleanup_pressure'),
            ['owner_review', 'status_boundary_review'],
            true
        );
    }

    /**
     * @param  array<int,string>  $owners
     * @return array<string,string>
     */
    private function docPathStemClassification(string $stem, array $owners): array
    {
        if (preg_match('/^\d+-visao-geral$/', $stem) === 1) {
            return [
                'bucket' => 'area_overview_family',
                'safe_interpretation' => 'numbered_overview_files_are_area_scoped_indexes_not_global_authority_docs',
                'cleanup_pressure' => 'owner_scope_label',
            ];
        }

        return match ($stem) {
            'readme' => [
                'bucket' => 'area_index_family',
                'safe_interpretation' => 'readme_files_are_area_indexes_not_duplicate_authority_docs',
                'cleanup_pressure' => 'none',
            ],
            'contracts' => [
                'bucket' => 'area_contract_family',
                'safe_interpretation' => 'contracts_files_are_owner_scoped_contracts_not_single_global_contract',
                'cleanup_pressure' => 'owner_scope_label',
            ],
            'runbook' => [
                'bucket' => 'area_runbook_family',
                'safe_interpretation' => 'runbooks_are_operator_guides_scoped_by_directory_owner',
                'cleanup_pressure' => 'owner_scope_label',
            ],
            'implementation-roadmap' => [
                'bucket' => 'roadmap_family',
                'safe_interpretation' => 'roadmaps_are_owner_scoped_and_must_not_be_used_as_global_status',
                'cleanup_pressure' => 'owner_scope_label',
            ],
            'failure-modes' => [
                'bucket' => 'failure_modes_family',
                'safe_interpretation' => 'failure_modes_are_runtime_or_area_scoped_not_global_failure_policy',
                'cleanup_pressure' => 'owner_scope_label',
            ],
            'schemas-and-packets' => [
                'bucket' => 'schema_packet_family',
                'safe_interpretation' => 'schemas_and_packets_are_area_scoped_contract_docs',
                'cleanup_pressure' => 'owner_scope_label',
            ],
            default => [
                'bucket' => count($owners) > 1 ? 'multi_owner_same_stem' : 'same_owner_same_stem',
                'safe_interpretation' => 'same_filename_stem_requires_path_owner_and_id_before_use',
                'cleanup_pressure' => 'owner_review',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function targetReachabilitySnapshot(string $target): array
    {
        if ($target === '' || ! str_starts_with($target, 'app/')) {
            return [
                'target' => $target,
                'status' => $target === '' ? 'missing_target' : 'not_app_runtime_target',
                'confidence' => 'none',
                'target_path' => null,
                'source_breakdown' => [],
            ];
        }

        $targetPath = $this->primitives->targetPath($target);
        $basename = basename($target);
        $needle = $targetPath ?? $target;
        $reachability = $this->primitives->reachabilityEnvelope(
            $targetPath,
            $this->primitives->references($needle, $basename),
            $this->primitives->ownerDocs($needle, $basename),
            $this->primitives->testRefs($needle, $basename),
            $this->primitives->entrypoints($needle, $basename),
            $this->primitives->constructorInjectors($basename, $targetPath),
        );

        return [
            'target' => $target,
            'status' => $reachability['status'],
            'confidence' => $reachability['confidence'],
            'target_path' => $reachability['target_path'],
            'signals' => $reachability['signals'],
            'source_breakdown' => $reachability['source_breakdown'],
            'delete_allowed' => false,
            'claim_policy' => 'reachability_snapshot_guides_owner_review_but_never_authorizes_delete',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $triageQueue
     * @param  array<string,mixed>  $criticalTopicPressure
     * @param  array<string,mixed>  $statusDrift
     * @param  array<string,mixed>  $code
     * @param  array<int,array<string,mixed>>  $sourceMaterialShadowQueue
     * @param  array<int,array<string,mixed>>  $ragRetrievalCleanupQueue
     * @param  array<int,array<string,mixed>>  $frontendProgrammingCleanupQueue
     * @param  array<int,array<string,mixed>>  $docPathStemBoundaryQueue
     * @return array<int,array<string,mixed>>
     */
    public function aiConfusionCleanupQueue(array $triageQueue, array $criticalTopicPressure, array $statusDrift, array $code, array $sourceMaterialShadowQueue, array $ragRetrievalCleanupQueue, array $frontendProgrammingCleanupQueue, array $docPathStemBoundaryQueue): array
    {
        $items = [];

        foreach (array_slice($triageQueue, 0, 10) as $item) {
            $items[] = [
                'id' => 'ai_confusion:'.(string) ($item['id'] ?? md5(json_encode($item))),
                'kind' => (string) ($item['kind'] ?? 'triage_item'),
                'severity' => (string) ($item['severity'] ?? 'review'),
                'source' => 'duplication_triage_queue',
                'summary' => (string) ($item['why_it_can_confuse_ai'] ?? 'candidate_can_confuse_ai_routing_or_owner_selection'),
                'required_decision' => (string) ($item['required_decision'] ?? 'owner_review_required'),
                'current_evidence' => (array) ($item['current_evidence'] ?? []),
                'boundary_contract' => (array) ($item['boundary_contract'] ?? []),
                'cleanup_recommendation' => (array) ($item['cleanup_recommendation'] ?? []),
                'next_commands' => (array) ($item['next_commands'] ?? []),
            ];
        }

        foreach (array_slice((array) ($criticalTopicPressure['items'] ?? []), 0, 5) as $item) {
            $topic = (string) ($item['topic'] ?? 'unknown');
            $cleanupQueue = match ($topic) {
                'rag_retrieval' => array_slice($ragRetrievalCleanupQueue, 0, 8),
                'frontend_programming' => array_slice($frontendProgrammingCleanupQueue, 0, 8),
                default => [],
            };
            if ($cleanupQueue === []) {
                continue;
            }

            $items[] = [
                'id' => 'ai_confusion:critical_topic:'.$topic,
                'kind' => 'critical_topic_pressure',
                'severity' => $topic === 'rag_retrieval' ? 'high' : 'medium',
                'source' => 'critical_topic_pressure',
                'summary' => 'critical_topic_has_broad_docs_or_code_surface_and_requires_canonical_owner_lookup',
                'canonical_owner' => (string) ($item['canonical_owner'] ?? ''),
                'pressure_score' => (int) ($item['pressure_score'] ?? 0),
                'cleanup_queue' => $cleanupQueue,
                'boundary_contract' => $topic === 'rag_retrieval' ? [
                    'canonical_owner' => (string) ($item['canonical_owner'] ?? ''),
                    'required_owner_lookup' => true,
                    'allowed_direction' => 'reuse_existing_context_memory_retrieval_owners_or_record_explicit_supersede_decision',
                    'forbidden' => 'do_not_create_parallel_rag_embedding_vector_context_pack_or_memory_owner_from_topic_pressure',
                    'cleanup_sequence' => [
                        'read_atlas_ai_local_performance_memory_strategy_first',
                        'check_rag_retrieval_cleanup_queue_before_new_runtime',
                        'run_runtime_language_boundary_for_python_embedding_vector_or_ml_terms',
                        'reuse_context_memory_owner_or_record_explicit_supersede_decision',
                        'never_promote_archive_source_material_to_current_owner',
                    ],
                    'required_runtime_gate' => 'php artisan atlas:ai:runtime-boundary --json',
                ] : ($topic === 'frontend_programming' ? [
                    'canonical_owner' => (string) ($item['canonical_owner'] ?? ''),
                    'required_owner_lookup' => true,
                    'allowed_direction' => 'reuse_programming_frontend_pipeline_stages_or_record_explicit_supersede_decision',
                    'forbidden' => 'do_not_create_parallel_frontend_proof_benchmark_workspace_live_or_quality_flow_from_topic_pressure',
                    'cleanup_sequence' => [
                        'read_programming_frontend_superpower_first',
                        'check_frontend_programming_cleanup_queue_before_new_frontend_command',
                        'reuse_existing_stage_or_record_explicit_supersede_decision',
                        'never_claim_delivery_or_world_best_from_templates_or_aliases',
                    ],
                ] : []),
                'required_decision' => (string) ($item['required_decision'] ?? 'read_canonical_owner_before_new_flow'),
                'next_commands' => [
                    'php artisan atlas:ai:place-feature "'.$topic.'" --json',
                    'php artisan atlas:code-reality global-duplication-audit --json',
                ],
            ];
        }

        foreach (array_slice((array) ($statusDrift['review_items'] ?? []), 0, 10) as $item) {
            $items[] = [
                'id' => 'ai_confusion:'.(string) ($item['id'] ?? 'status_drift:'.md5(json_encode($item))),
                'kind' => 'status_drift_pressure',
                'severity' => (string) ($item['severity'] ?? 'review'),
                'source' => 'status_drift',
                'summary' => 'doc_status_or_body_language_may_not_match_code_evidence',
                'path' => (string) ($item['path'] ?? ''),
                'required_decision' => 'owner_updates_doc_status_or_records_scaffold_boundary',
                'next_commands' => [
                    'php artisan atlas:code-reality status-drift-audit --json',
                    'php artisan atlas:engineering:knowledge docs-health --json',
                ],
            ];
        }

        foreach (array_slice($docPathStemBoundaryQueue, 0, 8) as $item) {
            if (! (bool) ($item['retrieval_confusion_risk'] ?? false)) {
                continue;
            }

            $items[] = [
                'id' => 'ai_confusion:'.(string) ($item['id'] ?? 'doc_path_stem:'.md5(json_encode($item))),
                'kind' => 'doc_path_stem_boundary_pressure',
                'severity' => (string) ($item['severity'] ?? 'review'),
                'source' => 'doc_path_stem_boundary_queue',
                'summary' => 'same_filename_stem_is_area_scoped_not_unique_owner_and_can_confuse_retrieval',
                'stem' => (string) ($item['stem'] ?? ''),
                'owners' => (array) ($item['owners'] ?? []),
                'boundary_contract' => (array) ($item['boundary_contract'] ?? []),
                'required_decision' => 'use_owner_and_path_not_filename_stem_when_selecting_docs',
                'next_commands' => [
                    'php artisan atlas:engineering:knowledge docs-health --json',
                    'php artisan atlas:code-reality global-duplication-audit --json',
                ],
                'claim_policy' => 'stem_overlap_is_navigation_pressure_not_duplicate_doc_id_or_delete_permission',
            ];
        }

        foreach ((array) ($code['legacy_operational_groups'] ?? []) as $group) {
            $cleanupPressure = (string) ($group['cleanup_pressure'] ?? 'unknown');
            $risk = (string) ($group['ia_confusion_risk'] ?? 'unknown');
            if ($cleanupPressure !== 'review' && $risk !== 'high') {
                continue;
            }

            $items[] = [
                'id' => 'ai_confusion:legacy_bucket:'.(string) ($group['bucket'] ?? 'unknown'),
                'kind' => 'legacy_operational_cleanup_pressure',
                'severity' => $risk === 'high' ? 'high' : 'review',
                'source' => 'legacy_operational_groups',
                'summary' => (string) ($group['safe_interpretation'] ?? 'legacy_bucket_requires_review'),
                'count' => (int) ($group['count'] ?? 0),
                'evidence_samples' => array_slice((array) ($group['samples'] ?? []), 0, 8),
                'required_decision' => 'classify_bucket_then_keep_boundary_rename_quarantine_or_delete_with_owner_decision',
                'next_commands' => [
                    'php artisan atlas:code-reality global-duplication-audit --json',
                    'php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json',
                ],
                'claim_policy' => 'legacy_bucket_review_is_not_dead_code_proof',
            ];
        }

        foreach (array_slice($sourceMaterialShadowQueue, 0, 5) as $item) {
            if ((bool) ($item['canonical_owner_exists'] ?? false)) {
                continue;
            }

            $items[] = [
                'id' => 'ai_confusion:'.(string) ($item['id'] ?? 'source_material_shadow:'.md5(json_encode($item))),
                'kind' => 'source_material_shadow',
                'severity' => (string) ($item['severity'] ?? 'review'),
                'source' => 'source_material_shadow_queue',
                'summary' => 'archived_source_material_mentions_critical_topic_but_is_not_current_authority',
                'path' => (string) ($item['path'] ?? ''),
                'required_decision' => 'treat_as_context_only_or_promote_through_owner_decision',
                'next_commands' => ['php artisan atlas:ai:docs-authority-audit --json'],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'review' => 3, 'low' => 4];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        return array_slice($items, 0, 40);
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<int,array<string,mixed>>
     */
    public function sourceMaterialShadowQueue(array $topicClusters): array
    {
        $items = [];
        foreach ($topicClusters as $topic => $cluster) {
            foreach ((array) ($cluster['source_material_paths'] ?? []) as $path) {
                $path = (string) $path;
                $items[] = [
                    'id' => 'source_material_shadow:'.$topic.':'.str_replace(['/', '.', '\\'], ':', $path),
                    'kind' => 'archived_source_material_shadow',
                    'topic' => $topic,
                    'severity' => $topic === 'rag_retrieval' ? 'high' : 'review',
                    'path' => $path,
                    'canonical_owner' => (string) ($cluster['canonical_owner'] ?? ''),
                    'canonical_owner_exists' => (bool) ($cluster['canonical_owner_exists'] ?? false),
                    'shadow_reason' => 'archived_doc_mentions_critical_topic_and_can_look_like_current_owner_to_ai_retrieval',
                    'boundary_contract' => [
                        'classification' => 'source_material_shadow_not_authority',
                        'allowed_use' => 'historical_context_after_reading_canonical_owner',
                        'canonical_owner_required' => (bool) ($cluster['canonical_owner_exists'] ?? false),
                        'retrieval_rank' => 'below_canonical_owner_and_current_runtime_docs',
                        'required_owner_decision' => 'keep_archived_context_only|promote_specific_content_into_canonical_owner|delete_or_quarantine_by_separate_cleanup',
                        'promotion_preflight' => [
                            'quote_or_summarize_specific_historical_claim',
                            'patch_canonical_owner_doc_with_current_status_and_evidence',
                            'run_docs_health_and_documentation_enforcement',
                            'never_change_runtime_based_on_archive_alone',
                        ],
                        'forbidden' => 'do_not_use_archived_source_material_as_current_runtime_feature_or_flow_owner',
                    ],
                    'cleanup_sequence' => [
                        'read_canonical_owner_first',
                        'treat_source_material_as_historical_context_only',
                        'promote_specific_content_only_by_owner_doc_patch',
                        'rank_archive_below_current_owner_in_retrieval',
                        'never_use_archive_doc_as_runtime_owner_or_current_feature_status',
                    ],
                    'next_commands' => [
                        'test -f "'.(string) ($cluster['canonical_owner'] ?? '').'"',
                        'php artisan atlas:code-reality global-duplication-audit --json',
                        'php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json',
                    ],
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'review' => 1, 'low' => 2];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        return $items;
    }

    /**
     * @param  array<int,string>  $matches
     * @return array<int,array<string,mixed>>
     */
    private function codeMatchSubareas(array $matches): array
    {
        $groups = [];
        foreach ($matches as $path) {
            $parts = explode('/', $path);
            $key = match (true) {
                ($parts[0] ?? '') === 'app' && ($parts[1] ?? '') === 'Services' && isset($parts[2], $parts[3]) => 'app/Services/'.$parts[2].'/'.$parts[3],
                ($parts[0] ?? '') === 'app' && isset($parts[1], $parts[2]) => 'app/'.$parts[1].'/'.$parts[2],
                default => implode('/', array_slice($parts, 0, min(3, count($parts)))),
            };
            $groups[$key][] = $path;
        }

        return $this->primitives->formatPathCountGroups($groups, 6);
    }

    /**
     * @param  array<int,string>  $matches
     * @return array<int,array<string,mixed>>
     */
    private function codeRoleGroups(array $matches): array
    {
        $groups = [];
        foreach ($matches as $path) {
            $role = match (true) {
                str_starts_with($path, 'app/Services/Ai/Context/') => 'canonical_context_retrieval_runtime',
                str_starts_with($path, 'app/Services/Ai/Memory/') || str_contains(basename($path), 'Memory') => 'memory_core_or_memory_policy',
                str_starts_with($path, 'app/Services/Ai/Programming/') => 'programming_rag_consumer_or_parallel_helper',
                str_starts_with($path, 'app/Services/Ai/Kernel/') => 'kernel_governance_or_bootstrap',
                str_starts_with($path, 'app/Console/Commands/') => 'cli_surface',
                str_starts_with($path, 'app/Http/Controllers/') => 'api_surface',
                str_starts_with($path, 'database/') => 'persistence_schema',
                str_starts_with($path, 'config/') => 'configuration',
                default => 'other_runtime_or_consumer',
            };
            $groups[$role][] = $path;
        }

        return $this->primitives->formatPathCountGroups($groups);
    }
}
