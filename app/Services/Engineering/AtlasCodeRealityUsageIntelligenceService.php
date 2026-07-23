<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Engineering\CodeRealityUsageIntelligence\CodeRealityDuplicateClassSection;
use App\Services\Engineering\CodeRealityUsageIntelligence\CodeRealityLegacySignalSection;
use App\Services\Engineering\CodeRealityUsageIntelligence\CodeRealityPrimitives;
use App\Services\Engineering\CodeRealityUsageIntelligence\CodeRealityRouteAliasSection;
use App\Services\Engineering\CodeRealityUsageIntelligence\CodeRealityStatusDriftSection;
use App\Services\Engineering\CodeRealityUsageIntelligence\CodeRealityTopicIntelligenceSection;

/**
 * Facade for Atlas code reality usage intelligence.
 *
 * The reporting surface (public API + envelope schema versions) lives here;
 * the analysis bodies are grouped into method-family section classes under
 * CodeRealityUsageIntelligence/ and resolved via constructor DI. Behavior is
 * identical to the pre-split monolith (GOD-DEBULK split).
 */
final class AtlasCodeRealityUsageIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.code_reality_usage_intelligence.v1';

    public const REACHABILITY_SCHEMA_VERSION = 'atlas.code_reality.reachability.v1';

    public const DELETION_PREFLIGHT_SCHEMA_VERSION = 'atlas.code_reality.deletion_preflight.v1';

    public const REALITY_AUDIT_SCHEMA_VERSION = 'atlas.code_reality.reality_audit.v1';

    public const GLOBAL_DUPLICATION_AUDIT_SCHEMA_VERSION = 'atlas.code_reality.global_duplication_audit.v1';

    public const STATUS_DRIFT_AUDIT_SCHEMA_VERSION = 'atlas.code_reality.status_drift_audit.v1';

    /**
     * @var array<int,string>
     */
    private const ADRS_RUNTIME_TARGETS = [
        'app/Services/Engineering/AtlasDocumentationRealitySystemService.php',
        'app/Console/Commands/AtlasDocumentationRealityCommand.php',
        'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
        'app/Console/Commands/AtlasCodeRealityCommand.php',
        'app/Services/Engineering/AtlasUniversalRealityCartographyService.php',
        'app/Console/Commands/AtlasUniversalRealityCartographyCommand.php',
    ];

    public function __construct(
        private readonly EngineeringDocumentationAuthorityAuditService $authorityAudit,
        private readonly CodeRealityPrimitives $primitives,
        private readonly CodeRealityLegacySignalSection $legacySignals,
        private readonly CodeRealityStatusDriftSection $statusDrift,
        private readonly CodeRealityRouteAliasSection $routeAlias,
        private readonly CodeRealityDuplicateClassSection $duplicateClass,
        private readonly CodeRealityTopicIntelligenceSection $topicIntelligence,
    ) {}


    /**
     * @return array<string,mixed>
     */
    public function classify(string $target): array
    {
        $normalizedTarget = trim($target);
        $targetPath = $this->primitives->targetPath($normalizedTarget);
        $needle = $targetPath ?? $normalizedTarget;
        $basename = $targetPath ? basename($targetPath) : class_basename($normalizedTarget);
        $references = $this->primitives->references($needle, $basename);
        $ownerDocs = $this->primitives->ownerDocs($needle, $basename);
        $tests = $this->primitives->testRefs($needle, $basename);
        $entrypoints = $this->primitives->entrypoints($needle, $basename);
        if (is_string($targetPath) && str_starts_with($targetPath, 'app/Console/Commands/')) {
            $entrypoints[] = $targetPath;
            $entrypoints = $this->primitives->uniqueStrings($entrypoints);
            sort($entrypoints);
        }
        $constructorInjectors = $this->primitives->constructorInjectors($basename, $targetPath);
        $reachability = $this->primitives->reachabilityEnvelope($targetPath, $references, $ownerDocs, $tests, $entrypoints, $constructorInjectors);
        $classification = $this->primitives->classification($targetPath, $reachability);
        $blockers = $this->primitives->blockers($classification, $targetPath, $ownerDocs);

        return $this->primitives->envelope([
            'action' => 'classify',
            'target' => $normalizedTarget,
            'classification' => $classification,
            'target_path' => $targetPath,
            'exists' => $targetPath !== null,
            'evidence' => [
                'reference_count' => count($references),
                'references' => array_slice($references, 0, 20),
                'owner_docs' => $ownerDocs,
                'tests' => $tests,
                'entrypoints' => $entrypoints,
                'reachability' => $reachability,
            ],
            'blockers' => $blockers,
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function usageMap(string $target): array
    {
        $classification = $this->classify($target);
        $reachability = data_get($classification, 'evidence.reachability', []);

        return $this->primitives->envelope([
            'action' => 'usage-map',
            'target' => $target,
            'classification' => $classification['classification'],
            'usage_map' => [
                'target_path' => $classification['target_path'],
                'entrypoints' => data_get($classification, 'evidence.entrypoints', []),
                'owner_docs' => data_get($classification, 'evidence.owner_docs', []),
                'tests' => data_get($classification, 'evidence.tests', []),
                'references' => data_get($classification, 'evidence.references', []),
                'reachability' => $reachability,
                'edges' => data_get($reachability, 'edges', []),
            ],
            'blockers' => $classification['blockers'],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function reachability(string $target): array
    {
        $classification = $this->classify($target);

        return $this->primitives->envelope([
            'action' => 'reachability',
            'target' => $target,
            'classification' => $classification['classification'],
            'target_path' => $classification['target_path'],
            'reachability' => data_get($classification, 'evidence.reachability', []),
            'blockers' => $classification['blockers'],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function antiDuplicate(string $feature): array
    {
        $feature = trim($feature);
        $tokens = $this->primitives->tokens($feature);
        $authority = $this->authorityAudit->report();
        $matches = [];

        foreach ((array) ($authority['capability_overlap_clusters'] ?? []) as $cluster) {
            $capability = (string) ($cluster['capability'] ?? '');
            if ($capability === '') {
                continue;
            }

            foreach ($tokens as $token) {
                if (str_contains($capability, $token)) {
                    $matches[] = [
                        'capability' => $capability,
                        'risk' => $cluster['risk'] ?? 'review',
                        'paths' => array_slice((array) ($cluster['paths'] ?? []), 0, 8),
                    ];
                    break;
                }
            }
        }

        $decision = $matches === [] ? 'proceed_with_owner_lookup' : 'reuse_or_extend_before_new_runtime';

        return $this->primitives->envelope([
            'action' => 'anti-duplicate',
            'feature' => $feature,
            'decision' => $decision,
            'match_count' => count($matches),
            'matches' => array_slice($matches, 0, 20),
            'required_next_step' => 'run_feature_placement_and_read_owner_docs_before_implementation',
            'blockers' => [],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function deadCodeCandidates(): array
    {
        return $this->primitives->envelope([
            'action' => 'dead-code-candidates',
            'classification_policy' => 'conservative_no_dead_code_confirmation',
            'dead_code_confirmed' => [],
            'candidates' => [],
            'required_quarantine_sequence' => [
                'unused_candidate',
                'reference_scan',
                'reachability_scan',
                'focused_tests',
                'owner_doc_review',
                'human_approval',
                'separate_delete_change',
            ],
            'blockers' => [],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function deletionPreflight(string $target): array
    {
        $classification = $this->classify($target);
        $reachability = data_get($classification, 'evidence.reachability', []);
        $decision = $this->primitives->deletionDecision((string) ($classification['classification'] ?? 'unknown_requires_audit'), (string) ($reachability['status'] ?? 'unproven'));

        return $this->primitives->envelope([
            'schema_version' => self::DELETION_PREFLIGHT_SCHEMA_VERSION,
            'action' => 'deletion-preflight',
            'target' => trim($target),
            'target_path' => $classification['target_path'],
            'classification' => $classification['classification'],
            'reachability_status' => data_get($reachability, 'status'),
            'reachability_confidence' => data_get($reachability, 'confidence'),
            'decision' => $decision,
            'allowed_to_delete' => false,
            'required_before_delete' => [
                'owner_doc_review',
                'global_reference_scan',
                'reachability_scan',
                'focused_tests',
                'quarantine_plan',
                'human_approval',
                'separate_delete_change',
            ],
            'evidence' => [
                'owner_docs' => data_get($classification, 'evidence.owner_docs', []),
                'tests' => data_get($classification, 'evidence.tests', []),
                'entrypoints' => data_get($classification, 'evidence.entrypoints', []),
                'reachability_edges' => data_get($reachability, 'edges', []),
            ],
            'blockers' => [],
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'deletes_files' => false,
                'dead_code_confirmation_allowed' => false,
                'delete_authorization_allowed' => false,
            ],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function realityAudit(): array
    {
        $targets = array_map(fn (string $target): array => $this->classify($target), self::ADRS_RUNTIME_TARGETS);
        $unknown = array_values(array_filter($targets, static fn (array $target): bool => ! in_array((string) ($target['classification'] ?? ''), ['active_runtime', 'active_read_only'], true)));
        $weakReachability = array_values(array_filter($targets, static fn (array $target): bool => ! in_array((string) data_get($target, 'evidence.reachability.confidence'), ['high', 'medium'], true)));

        return $this->primitives->envelope([
            'schema_version' => self::REALITY_AUDIT_SCHEMA_VERSION,
            'action' => 'reality-audit',
            'scope' => 'adrs_acrui_aurc_runtime_cluster',
            'target_count' => count($targets),
            'active_runtime_count' => count(array_filter($targets, static fn (array $target): bool => ($target['classification'] ?? null) === 'active_runtime')),
            'active_read_only_count' => count(array_filter($targets, static fn (array $target): bool => ($target['classification'] ?? null) === 'active_read_only')),
            'unknown_or_unused_count' => count($unknown),
            'weak_reachability_count' => count($weakReachability),
            'targets' => array_map(static fn (array $target): array => [
                'target_path' => $target['target_path'],
                'classification' => $target['classification'],
                'reachability_status' => data_get($target, 'evidence.reachability.status'),
                'reachability_confidence' => data_get($target, 'evidence.reachability.confidence'),
                'owner_doc_count' => count((array) data_get($target, 'evidence.owner_docs', [])),
                'test_count' => count((array) data_get($target, 'evidence.tests', [])),
                'entrypoint_count' => count((array) data_get($target, 'evidence.entrypoints', [])),
            ], $targets),
            'risk_register' => [
                'unknown_targets' => array_map(static fn (array $target): ?string => $target['target_path'], $unknown),
                'weak_reachability_targets' => array_map(static fn (array $target): ?string => $target['target_path'], $weakReachability),
                'delete_claim_blocked' => true,
            ],
            'blockers' => [],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function globalDuplicationAudit(): array
    {
        $docs = $this->primitives->documentationInventory();
        $code = $this->legacySignals->codeInventory();
        $runtimeRoutes = $this->routeAlias->runtimeRouteInventory();
        $statusDrift = $this->statusDrift->statusDriftInventory($docs, $code);
        $topicClusters = $this->topicIntelligence->topicClusters($docs, $code);
        $criticalTopicPressure = $this->topicIntelligence->criticalTopicPressure($topicClusters);
        $sourceMaterialShadowQueue = $this->topicIntelligence->sourceMaterialShadowQueue($topicClusters);
        $ragRetrievalResolvedBoundaryQueue = $this->topicIntelligence->ragRetrievalResolvedBoundaryQueue($topicClusters);
        $ragRetrievalCleanupQueue = $this->topicIntelligence->ragRetrievalCleanupQueue($topicClusters);
        $frontendProgrammingCleanupQueue = $this->topicIntelligence->frontendProgrammingCleanupQueue($topicClusters);
        $docIdDuplicateGroups = $this->primitives->duplicateGroups($docs['canonical_active_items'], 'id');
        $docGraphDuplicateGroups = $this->primitives->duplicateGroups($docs['canonical_active_items'], 'graph_id');
        $docTitleDuplicateGroups = $this->primitives->duplicateGroups($docs['canonical_active_items'], 'title_key');
        $docPathStemDuplicateGroups = $this->primitives->duplicateGroups($docs['active_non_archive_items'], 'path_stem');
        $docPathStemBoundaryQueue = $this->topicIntelligence->docPathStemBoundaryQueue($docPathStemDuplicateGroups);
        $documentedDocPathStemBoundaryQueue = $this->topicIntelligence->documentedDocPathStemBoundaryQueue($docPathStemBoundaryQueue);
        $reviewDocPathStemBoundaryQueue = $this->topicIntelligence->reviewDocPathStemBoundaryQueue($docPathStemBoundaryQueue);
        $classDuplicateGroups = $this->primitives->duplicateGroups($code['classes'], 'name');
        $commandDuplicateGroups = $this->primitives->duplicateGroups($code['artisan_commands'], 'signature');
        $routeDuplicateGroups = $this->primitives->duplicateGroups($code['routes'], 'method_uri');
        $routeNameDuplicateGroups = $this->primitives->duplicateGroups($code['named_routes'], 'name');
        $routeActionAliasGroups = $this->primitives->duplicateGroups($code['route_actions'], 'action');
        $runtimeRouteDuplicateGroups = $this->primitives->duplicateGroups($runtimeRoutes['routes'], 'method_uri');
        $confirmedRouteDuplicateGroups = $this->routeAlias->confirmedStaticRouteDuplicateGroups($routeDuplicateGroups, $runtimeRouteDuplicateGroups);
        $prefixBlindStaticRouteDuplicateGroups = $this->routeAlias->prefixBlindStaticRouteDuplicateGroups($routeDuplicateGroups, $confirmedRouteDuplicateGroups);
        $staticRouteActionAliasGroups = $this->routeAlias->annotatedStaticRouteActionAliasGroups($routeActionAliasGroups, $confirmedRouteDuplicateGroups);
        $documentedStaticRouteActionAliasGroups = $this->routeAlias->documentedStaticRouteActionAliasGroups($staticRouteActionAliasGroups);
        $reviewStaticRouteActionAliasGroups = $this->routeAlias->reviewStaticRouteActionAliasGroups($staticRouteActionAliasGroups);
        $runtimeRouteNameDuplicateGroups = $this->primitives->duplicateGroups($runtimeRoutes['named_routes'], 'name');
        $runtimeRouteActionAliasGroups = $this->primitives->duplicateGroups($runtimeRoutes['route_actions'], 'action');
        $runtimeRootApiCompatibilityAliasGroups = $this->routeAlias->runtimeRootApiCompatibilityAliasGroups($runtimeRouteActionAliasGroups);
        $runtimeDocumentedRouteActionAliasGroups = $this->routeAlias->runtimeDocumentedRouteActionAliasGroups($runtimeRouteActionAliasGroups);
        $runtimeReviewRouteActionAliasGroups = $this->routeAlias->runtimeReviewRouteActionAliasGroups($runtimeRouteActionAliasGroups);
        $generatedFixtureClassDuplicateGroups = $this->duplicateClass->generatedFixtureClassDuplicateGroups($classDuplicateGroups);
        $generatedFixtureClassBoundaryQueue = $this->duplicateClass->generatedFixtureClassBoundaryQueue($generatedFixtureClassDuplicateGroups);
        $blockingClassDuplicateGroups = $this->duplicateClass->blockingClassDuplicateGroups($classDuplicateGroups);
        $duplicateClassCleanupQueue = $this->duplicateClass->duplicateClassCleanupQueue($classDuplicateGroups);
        $triageQueue = $this->duplicateClass->duplicationTriageQueue($blockingClassDuplicateGroups, $runtimeReviewRouteActionAliasGroups);
        $runtimeDocumentedRouteAliasBoundaryQueue = $this->routeAlias->runtimeRouteAliasCleanupQueue($runtimeDocumentedRouteActionAliasGroups, true);
        $runtimeRouteAliasCleanupQueue = $this->routeAlias->runtimeRouteAliasCleanupQueue($runtimeReviewRouteActionAliasGroups);
        $unresolvedSourceMaterialShadowCount = count(array_filter(
            $sourceMaterialShadowQueue,
            static fn (array $item): bool => in_array(
                (string) ($item['canonical_owner'] ?? ''),
                ['', 'owner_review_required'],
                true
            )
        ));
        $aiConfusionCleanupQueue = $this->topicIntelligence->aiConfusionCleanupQueue(
            $triageQueue,
            $criticalTopicPressure,
            $statusDrift,
            $code,
            $sourceMaterialShadowQueue,
            $ragRetrievalCleanupQueue,
            $frontendProgrammingCleanupQueue,
            $reviewDocPathStemBoundaryQueue
        );
        $blockers = array_values(array_filter([
            $docIdDuplicateGroups === [] ? null : [
                'reason' => 'duplicate_canonical_doc_ids',
                'count' => count($docIdDuplicateGroups),
                'policy' => 'canonical_docs_must_have_unique_ids',
            ],
            $docGraphDuplicateGroups === [] ? null : [
                'reason' => 'duplicate_canonical_doc_graph_ids',
                'count' => count($docGraphDuplicateGroups),
                'policy' => 'canonical_docs_must_have_unique_graph_ids',
            ],
            $blockingClassDuplicateGroups === [] ? null : [
                'reason' => 'duplicate_php_class_names',
                'count' => count($blockingClassDuplicateGroups),
                'policy' => 'requires_owner_review_before_new_code',
            ],
            $commandDuplicateGroups === [] ? null : [
                'reason' => 'duplicate_artisan_command_signatures',
                'count' => count($commandDuplicateGroups),
                'policy' => 'commands_must_have_single_owner',
            ],
            $routeNameDuplicateGroups === [] ? null : [
                'reason' => 'duplicate_route_names',
                'count' => count($routeNameDuplicateGroups),
                'policy' => 'named_routes_must_have_single_owner',
            ],
            $runtimeRouteDuplicateGroups === [] ? null : [
                'reason' => 'runtime_duplicate_route_method_uri',
                'count' => count($runtimeRouteDuplicateGroups),
                'policy' => 'registered_routes_must_have_single_owner',
            ],
            $runtimeRouteNameDuplicateGroups === [] ? null : [
                'reason' => 'runtime_duplicate_route_names',
                'count' => count($runtimeRouteNameDuplicateGroups),
                'policy' => 'registered_named_routes_must_have_single_owner',
            ],
        ]));
        $reviewItems = array_values(array_filter([
            $docTitleDuplicateGroups === [] ? null : [
                'reason' => 'canonical_doc_title_overlap',
                'count' => count($docTitleDuplicateGroups),
                'policy' => 'same_title_requires_owner_or_family_boundary_review',
            ],
            $reviewDocPathStemBoundaryQueue === [] ? null : [
                'reason' => 'active_doc_path_stem_overlap',
                'count' => count($reviewDocPathStemBoundaryQueue),
                'policy' => 'same_filename_stem_requires_review_before_ai_uses_as_unique_owner',
            ],
            $docs['non_canonical_active_count'] === 0 ? null : [
                'reason' => 'non_canonical_active_docs_present',
                'count' => $docs['non_canonical_active_count'],
                'policy' => 'review_before_using_as_ai_authority',
            ],
            $unresolvedSourceMaterialShadowCount === 0 ? null : [
                'reason' => 'archived_source_material_can_look_duplicate',
                'count' => $unresolvedSourceMaterialShadowCount,
                'policy' => 'archive_without_resolved_owner_requires_review_before_provider_context_use',
            ],
            (int) data_get($code, 'legacy_operational_summary.cleanup_review_count', 0) === 0 ? null : [
                'reason' => 'legacy_compatibility_adapter_cleanup_candidates_present',
                'count' => (int) data_get($code, 'legacy_operational_summary.cleanup_review_count', 0),
                'policy' => 'compatibility_adapters_require_reachability_and_alias_boundary_before_cleanup',
            ],
            (int) data_get($code, 'legacy_operational_summary.documentation_review_count', 0) === 0 ? null : [
                'reason' => 'legacy_documentation_review_signals_present',
                'count' => (int) data_get($code, 'legacy_operational_summary.documentation_review_count', 0),
                'policy' => 'comments_docblocks_and_config_banners_are_doc_review_not_code_delete_authority',
            ],
            $reviewStaticRouteActionAliasGroups === [] ? null : [
                'reason' => 'route_action_aliases_present',
                'count' => count($reviewStaticRouteActionAliasGroups),
                'policy' => 'same_controller_action_on_multiple_routes_requires_boundary_review',
            ],
            $runtimeReviewRouteActionAliasGroups === [] ? null : [
                'reason' => 'runtime_route_action_aliases_present',
                'count' => count($runtimeReviewRouteActionAliasGroups),
                'policy' => 'registered_aliases_require_intentional_boundary_or_cleanup_decision',
            ],
            $confirmedRouteDuplicateGroups === [] ? null : [
                'reason' => 'confirmed_literal_route_method_uri_overlap',
                'count' => count($confirmedRouteDuplicateGroups),
                'policy' => 'registered_route_list_confirms_literal_method_uri_overlap_before_owner_review',
            ],
        ]));

        return $this->primitives->envelope([
            'schema_version' => self::GLOBAL_DUPLICATION_AUDIT_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'action' => 'global-duplication-audit',
            'scope' => 'docs_code_feature_flow_duplication_candidates',
            'summary' => [
                'doc_count' => $docs['doc_count'],
                'canonical_doc_count' => $docs['canonical_doc_count'],
                'non_canonical_active_count' => $docs['non_canonical_active_count'],
                'archived_source_material_count' => $docs['archived_source_material_count'],
                'duplicate_canonical_doc_id_group_count' => count($docIdDuplicateGroups),
                'duplicate_canonical_doc_graph_id_group_count' => count($docGraphDuplicateGroups),
                'duplicate_canonical_doc_title_group_count' => count($docTitleDuplicateGroups),
                'duplicate_active_doc_path_stem_group_count' => count($docPathStemDuplicateGroups),
                'doc_path_stem_boundary_queue_count' => count($docPathStemBoundaryQueue),
                'documented_doc_path_stem_boundary_queue_count' => count($documentedDocPathStemBoundaryQueue),
                'review_doc_path_stem_boundary_queue_count' => count($reviewDocPathStemBoundaryQueue),
                'doc_path_stem_retrieval_risk_count' => count(array_filter(
                    $docPathStemBoundaryQueue,
                    static fn (array $item): bool => (bool) ($item['retrieval_confusion_risk'] ?? false)
                )),
                'php_class_count' => count($code['classes']),
                'artisan_command_count' => count($code['artisan_commands']),
                'route_count' => count($code['routes']),
                'named_route_count' => count($code['named_routes']),
                'runtime_route_count' => count($runtimeRoutes['routes']),
                'runtime_named_route_count' => count($runtimeRoutes['named_routes']),
                'duplicate_class_group_count' => count($classDuplicateGroups),
                'generated_fixture_duplicate_class_group_count' => count($generatedFixtureClassDuplicateGroups),
                'generated_fixture_duplicate_class_boundary_queue_count' => count($generatedFixtureClassBoundaryQueue),
                'blocking_duplicate_class_group_count' => count($blockingClassDuplicateGroups),
                'duplicate_command_group_count' => count($commandDuplicateGroups),
                'duplicate_route_group_count' => count($routeDuplicateGroups),
                'confirmed_duplicate_route_group_count' => count($confirmedRouteDuplicateGroups),
                'prefix_blind_static_route_duplicate_group_count' => count($prefixBlindStaticRouteDuplicateGroups),
                'duplicate_route_name_group_count' => count($routeNameDuplicateGroups),
                'route_action_alias_group_count' => count($routeActionAliasGroups),
                'documented_static_route_action_alias_group_count' => count($documentedStaticRouteActionAliasGroups),
                'review_static_route_action_alias_group_count' => count($reviewStaticRouteActionAliasGroups),
                'runtime_duplicate_route_group_count' => count($runtimeRouteDuplicateGroups),
                'runtime_duplicate_route_name_group_count' => count($runtimeRouteNameDuplicateGroups),
                'runtime_route_action_alias_group_count' => count($runtimeRouteActionAliasGroups),
                'runtime_root_api_compatibility_alias_group_count' => count($runtimeRootApiCompatibilityAliasGroups),
                'runtime_documented_route_action_alias_group_count' => count($runtimeDocumentedRouteActionAliasGroups),
                'runtime_documented_route_alias_boundary_queue_count' => count($runtimeDocumentedRouteAliasBoundaryQueue),
                'runtime_review_route_action_alias_group_count' => count($runtimeReviewRouteActionAliasGroups),
                'runtime_route_alias_cleanup_queue_count' => count($runtimeRouteAliasCleanupQueue),
                'duplicate_class_cleanup_queue_count' => count($duplicateClassCleanupQueue),
                'status_drift_review_count' => count($statusDrift['review_items']),
                'status_drift_owner_group_count' => count($statusDrift['owner_groups']),
                'status_drift_doc_area_group_count' => count($statusDrift['doc_area_groups']),
                'planned_or_future_with_existing_code_count' => $statusDrift['summary']['planned_or_future_with_existing_code_count'],
                'scaffold_language_with_existing_code_count' => $statusDrift['summary']['scaffold_language_with_existing_code_count'],
                'triage_queue_count' => count($triageQueue),
                'legacy_signal_count' => $code['legacy_signal_count'],
                'legacy_signal_type_count' => count($code['legacy_signal_groups']),
                'legacy_signal_area_count' => count($code['legacy_area_groups']),
                'legacy_signal_inventory_count' => count($code['legacy_signal_inventory']),
                'legacy_triage_queue_count' => count($code['legacy_triage_queue']),
                'legacy_cleanup_queue_count' => count($code['legacy_cleanup_queue']),
                'legacy_cleanup_high_risk_count' => count(array_filter(
                    $code['legacy_cleanup_queue'],
                    static fn (array $item): bool => ($item['ia_confusion_risk'] ?? null) === 'high'
                )),
                'legacy_operational_group_count' => count($code['legacy_operational_groups']),
                'legacy_cleanup_review_count' => (int) data_get($code, 'legacy_operational_summary.cleanup_review_count', 0),
                'legacy_documentation_review_count' => (int) data_get($code, 'legacy_operational_summary.documentation_review_count', 0),
                'legacy_documentation_inventory_count' => (int) data_get($code, 'legacy_operational_summary.documentation_inventory_count', 0),
                'legacy_keyword_inventory_count' => (int) data_get($code, 'legacy_operational_summary.keyword_inventory_count', 0),
                'legacy_false_positive_or_taxonomy_count' => (int) data_get($code, 'legacy_operational_summary.false_positive_or_taxonomy_count', 0),
                'ai_service_file_count' => $code['ai_service_hotspots']['file_count'],
                'ai_service_hotspot_subarea_count' => count($code['ai_service_hotspots']['subareas']),
                'ai_service_hotspot_topic_count' => count($code['ai_service_hotspots']['topics']),
                'critical_topic_cluster_count' => count($topicClusters),
                'critical_topic_source_material_count' => $criticalTopicPressure['source_material_count'],
                'critical_topic_noncanonical_active_count' => $criticalTopicPressure['noncanonical_active_count'],
                'source_material_shadow_queue_count' => count($sourceMaterialShadowQueue),
                'rag_retrieval_source_material_count' => (int) data_get($topicClusters, 'rag_retrieval.source_material_doc_count', 0),
                'rag_retrieval_code_match_count' => (int) data_get($topicClusters, 'rag_retrieval.code_match_count', 0),
                'rag_retrieval_flow_family_count' => count((array) data_get($topicClusters, 'rag_retrieval.flow_families', [])),
                'rag_retrieval_flow_family_review_count' => count($ragRetrievalCleanupQueue),
                'rag_retrieval_flow_family_boundary_inventory_count' => count($ragRetrievalResolvedBoundaryQueue),
                'rag_retrieval_resolved_boundary_queue_count' => count($ragRetrievalResolvedBoundaryQueue),
                'rag_retrieval_cleanup_queue_count' => count($ragRetrievalCleanupQueue),
                'rag_retrieval_code_role_count' => count((array) data_get($topicClusters, 'rag_retrieval.code_roles', [])),
                'frontend_programming_code_match_count' => (int) data_get($topicClusters, 'frontend_programming.code_match_count', 0),
                'frontend_programming_flow_family_count' => count((array) data_get($topicClusters, 'frontend_programming.flow_families', [])),
                'frontend_programming_cleanup_queue_count' => count($frontendProgrammingCleanupQueue),
                'ai_confusion_cleanup_queue_count' => count($aiConfusionCleanupQueue),
            ],
            'documentation' => [
                'duplicate_canonical_doc_id_groups' => $docIdDuplicateGroups,
                'duplicate_canonical_doc_graph_id_groups' => $docGraphDuplicateGroups,
                'duplicate_canonical_doc_title_groups' => $docTitleDuplicateGroups,
                'duplicate_active_doc_path_stem_groups' => $docPathStemDuplicateGroups,
                'doc_path_stem_boundary_queue' => $docPathStemBoundaryQueue,
                'documented_doc_path_stem_boundary_queue' => $documentedDocPathStemBoundaryQueue,
                'review_doc_path_stem_boundary_queue' => $reviewDocPathStemBoundaryQueue,
                'non_canonical_active_samples' => array_slice($docs['non_canonical_active'], 0, 20),
                'archived_source_material_samples' => array_slice($docs['archived_source_material'], 0, 20),
                'critical_topic_noncanonical_samples' => $docs['critical_topic_noncanonical_samples'],
                'source_material_shadow_queue' => $sourceMaterialShadowQueue,
            ],
            'code' => [
                'duplicate_class_groups' => $classDuplicateGroups,
                'duplicate_artisan_command_groups' => $commandDuplicateGroups,
                'duplicate_route_groups' => $routeDuplicateGroups,
                'confirmed_duplicate_route_groups' => $confirmedRouteDuplicateGroups,
                'prefix_blind_static_route_duplicate_groups' => $prefixBlindStaticRouteDuplicateGroups,
                'duplicate_route_name_groups' => $routeNameDuplicateGroups,
                'route_action_alias_groups' => array_slice($staticRouteActionAliasGroups, 0, 20),
                'documented_static_route_action_alias_groups' => array_slice($documentedStaticRouteActionAliasGroups, 0, 20),
                'review_static_route_action_alias_groups' => array_slice($reviewStaticRouteActionAliasGroups, 0, 20),
                'runtime_duplicate_route_groups' => $runtimeRouteDuplicateGroups,
                'runtime_duplicate_route_name_groups' => $runtimeRouteNameDuplicateGroups,
                'runtime_route_action_alias_groups' => array_slice($runtimeRouteActionAliasGroups, 0, 30),
                'runtime_root_api_compatibility_alias_groups' => array_slice($runtimeRootApiCompatibilityAliasGroups, 0, 30),
                'runtime_documented_route_action_alias_groups' => array_slice($runtimeDocumentedRouteActionAliasGroups, 0, 30),
                'runtime_documented_route_alias_boundary_queue' => $runtimeDocumentedRouteAliasBoundaryQueue,
                'runtime_review_route_action_alias_groups' => array_slice($runtimeReviewRouteActionAliasGroups, 0, 30),
                'runtime_route_alias_cleanup_queue' => $runtimeRouteAliasCleanupQueue,
                'generated_fixture_duplicate_class_groups' => $generatedFixtureClassDuplicateGroups,
                'generated_fixture_duplicate_class_boundary_queue' => $generatedFixtureClassBoundaryQueue,
                'blocking_duplicate_class_groups' => $blockingClassDuplicateGroups,
                'duplicate_class_cleanup_queue' => $duplicateClassCleanupQueue,
                'legacy_signal_groups' => $code['legacy_signal_groups'],
                'legacy_area_groups' => array_slice($code['legacy_area_groups'], 0, 20),
                'legacy_signal_inventory' => $code['legacy_signal_inventory'],
                'legacy_triage_queue' => $code['legacy_triage_queue'],
                'legacy_cleanup_queue' => $code['legacy_cleanup_queue'],
                'legacy_operational_groups' => $code['legacy_operational_groups'],
                'legacy_operational_summary' => $code['legacy_operational_summary'],
                'ai_service_hotspots' => $code['ai_service_hotspots'],
                'legacy_signal_samples' => array_slice($code['legacy_signals'], 0, 30),
            ],
            'topic_clusters' => $topicClusters,
            'critical_topic_pressure' => $criticalTopicPressure,
            'rag_retrieval_resolved_boundary_queue' => $ragRetrievalResolvedBoundaryQueue,
            'rag_retrieval_cleanup_queue' => $ragRetrievalCleanupQueue,
            'frontend_programming_cleanup_queue' => $frontendProgrammingCleanupQueue,
            'status_drift' => $statusDrift,
            'triage_queue' => $triageQueue,
            'ai_confusion_cleanup_queue' => $aiConfusionCleanupQueue,
            'review_items' => $reviewItems,
            'blockers' => $blockers,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'deletes_files' => false,
                'dead_code_confirmation_allowed' => false,
                'global_no_duplication_claim_allowed' => false,
            ],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function statusDriftAudit(): array
    {
        $docs = $this->primitives->documentationInventory();
        $code = $this->legacySignals->codeInventory();
        $statusDrift = $this->statusDrift->statusDriftInventory($docs, $code);
        $reviewItems = $statusDrift['review_items'];

        return $this->primitives->envelope([
            'schema_version' => self::STATUS_DRIFT_AUDIT_SCHEMA_VERSION,
            'status' => $reviewItems === [] ? 'ready' : 'review',
            'action' => 'status-drift-audit',
            'scope' => 'canonical_doc_status_vs_existing_code_evidence',
            'summary' => $statusDrift['summary'],
            'owner_groups' => $statusDrift['owner_groups'],
            'doc_area_groups' => $statusDrift['doc_area_groups'],
            'review_items' => $reviewItems,
            'samples' => array_slice($reviewItems, 0, 30),
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'deletes_files' => false,
                'declares_doc_wrong_automatically' => false,
                'requires_owner_review_before_status_change' => true,
            ],
        ]);
    }


    /**
     * @return array<string,mixed>
     */
    public function contextPack(string $task): array
    {
        $antiDuplicate = $this->antiDuplicate($task);

        return $this->primitives->envelope([
            'action' => 'context-pack',
            'task' => trim($task),
            'provider_safe' => true,
            'minimal_sources' => [
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                'docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md',
            ],
            'anti_duplicate_decision' => $antiDuplicate['decision'],
            'do_not_claim' => [
                'dead_code_confirmed_without_quarantine',
                'child_system_complete_without_dedicated_certification',
                'safe_to_delete_without_human_approval',
            ],
            'required_commands' => [
                'php artisan atlas:ai:place-feature "<feature>" --json',
                'php artisan atlas:code-reality reality-audit --json',
                'php artisan atlas:code-reality global-duplication-audit --json',
                'php artisan atlas:code-reality status-drift-audit --json',
                'php artisan atlas:code-reality reachability --target="<target>" --json',
                'php artisan atlas:code-reality deletion-preflight --target="<target>" --json',
                'php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
            ],
            'blockers' => [],
        ]);
    }
}
