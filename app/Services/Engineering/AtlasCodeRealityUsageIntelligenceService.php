<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
    private const SEARCH_ROOTS = ['app', 'routes', 'config', 'database', 'tests', 'docs/engineering-knowledge-base'];

    // The repo outgrew the old 10k/2.5s budget (~14.5k scannable files), so the
    // reference scan silently truncated and 'evidence.references' lied for any
    // target reached late in iteration order (tests/, docs/). Keep the guard as
    // a runaway brake, but size it above the real corpus: wrong evidence is
    // worse than a slower diagnostic scan.
    private const MAX_SCAN_FILES = 40000;

    private const MAX_SCAN_SECONDS = 15.0;

    private const MAX_FILE_SCAN_BYTES = 768_000;

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

    /**
     * @var array<string,array{canonical_owner:string,terms:array<int,string>}>
     */
    private const CRITICAL_TOPIC_CLUSTERS = [
        'rag_retrieval' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
            'terms' => ['rag', 'retrieval', 'embedding', 'embeddings', 'vector', 'faiss', 'chroma', 'graph rag', 'local rag'],
        ],
        'voice_realtime' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            'terms' => ['voice realtime', 'livekit', 'agents sdk', 'tts', 'stt'],
        ],
        'documentation_reality' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
            'terms' => ['documentation reality', 'docs-health', 'docs-authority', 'acrui', 'aurc'],
        ],
        'frontend_programming' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
            'terms' => ['frontend', 'browser bridge', 'anti slop', 'design runtime', 'run certify'],
        ],
        'forge_programming' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
            'terms' => ['forge', 'obra', 'long horizon', 'work packet'],
        ],
    ];

    public function __construct(
        private readonly EngineeringDocumentationAuthorityAuditService $authorityAudit,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function classify(string $target): array
    {
        $normalizedTarget = trim($target);
        $targetPath = $this->targetPath($normalizedTarget);
        $needle = $targetPath ?? $normalizedTarget;
        $basename = $targetPath ? basename($targetPath) : class_basename($normalizedTarget);
        $references = $this->references($needle, $basename);
        $ownerDocs = $this->ownerDocs($needle, $basename);
        $tests = $this->testRefs($needle, $basename);
        $entrypoints = $this->entrypoints($needle, $basename);
        if (is_string($targetPath) && str_starts_with($targetPath, 'app/Console/Commands/')) {
            $entrypoints[] = $targetPath;
            $entrypoints = $this->uniqueStrings($entrypoints);
            sort($entrypoints);
        }
        $constructorInjectors = $this->constructorInjectors($basename, $targetPath);
        $reachability = $this->reachabilityEnvelope($targetPath, $references, $ownerDocs, $tests, $entrypoints, $constructorInjectors);
        $classification = $this->classification($targetPath, $reachability);
        $blockers = $this->blockers($classification, $targetPath, $ownerDocs);

        return $this->envelope([
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

        return $this->envelope([
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

        return $this->envelope([
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
        $tokens = $this->tokens($feature);
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

        return $this->envelope([
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
        return $this->envelope([
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
        $decision = $this->deletionDecision((string) ($classification['classification'] ?? 'unknown_requires_audit'), (string) ($reachability['status'] ?? 'unproven'));

        return $this->envelope([
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

        return $this->envelope([
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
        $docs = $this->documentationInventory();
        $code = $this->codeInventory();
        $runtimeRoutes = $this->runtimeRouteInventory();
        $statusDrift = $this->statusDriftInventory($docs, $code);
        $topicClusters = $this->topicClusters($docs, $code);
        $criticalTopicPressure = $this->criticalTopicPressure($topicClusters);
        $sourceMaterialShadowQueue = $this->sourceMaterialShadowQueue($topicClusters);
        $ragRetrievalResolvedBoundaryQueue = $this->ragRetrievalResolvedBoundaryQueue($topicClusters);
        $ragRetrievalCleanupQueue = $this->ragRetrievalCleanupQueue($topicClusters);
        $frontendProgrammingCleanupQueue = $this->frontendProgrammingCleanupQueue($topicClusters);
        $docIdDuplicateGroups = $this->duplicateGroups($docs['canonical_active_items'], 'id');
        $docGraphDuplicateGroups = $this->duplicateGroups($docs['canonical_active_items'], 'graph_id');
        $docTitleDuplicateGroups = $this->duplicateGroups($docs['canonical_active_items'], 'title_key');
        $docPathStemDuplicateGroups = $this->duplicateGroups($docs['active_non_archive_items'], 'path_stem');
        $docPathStemBoundaryQueue = $this->docPathStemBoundaryQueue($docPathStemDuplicateGroups);
        $documentedDocPathStemBoundaryQueue = $this->documentedDocPathStemBoundaryQueue($docPathStemBoundaryQueue);
        $reviewDocPathStemBoundaryQueue = $this->reviewDocPathStemBoundaryQueue($docPathStemBoundaryQueue);
        $classDuplicateGroups = $this->duplicateGroups($code['classes'], 'name');
        $commandDuplicateGroups = $this->duplicateGroups($code['artisan_commands'], 'signature');
        $routeDuplicateGroups = $this->duplicateGroups($code['routes'], 'method_uri');
        $routeNameDuplicateGroups = $this->duplicateGroups($code['named_routes'], 'name');
        $routeActionAliasGroups = $this->duplicateGroups($code['route_actions'], 'action');
        $runtimeRouteDuplicateGroups = $this->duplicateGroups($runtimeRoutes['routes'], 'method_uri');
        $confirmedRouteDuplicateGroups = $this->confirmedStaticRouteDuplicateGroups($routeDuplicateGroups, $runtimeRouteDuplicateGroups);
        $prefixBlindStaticRouteDuplicateGroups = $this->prefixBlindStaticRouteDuplicateGroups($routeDuplicateGroups, $confirmedRouteDuplicateGroups);
        $staticRouteActionAliasGroups = $this->annotatedStaticRouteActionAliasGroups($routeActionAliasGroups, $confirmedRouteDuplicateGroups);
        $documentedStaticRouteActionAliasGroups = $this->documentedStaticRouteActionAliasGroups($staticRouteActionAliasGroups);
        $reviewStaticRouteActionAliasGroups = $this->reviewStaticRouteActionAliasGroups($staticRouteActionAliasGroups);
        $runtimeRouteNameDuplicateGroups = $this->duplicateGroups($runtimeRoutes['named_routes'], 'name');
        $runtimeRouteActionAliasGroups = $this->duplicateGroups($runtimeRoutes['route_actions'], 'action');
        $runtimeRootApiCompatibilityAliasGroups = $this->runtimeRootApiCompatibilityAliasGroups($runtimeRouteActionAliasGroups);
        $runtimeDocumentedRouteActionAliasGroups = $this->runtimeDocumentedRouteActionAliasGroups($runtimeRouteActionAliasGroups);
        $runtimeReviewRouteActionAliasGroups = $this->runtimeReviewRouteActionAliasGroups($runtimeRouteActionAliasGroups);
        $generatedFixtureClassDuplicateGroups = $this->generatedFixtureClassDuplicateGroups($classDuplicateGroups);
        $generatedFixtureClassBoundaryQueue = $this->generatedFixtureClassBoundaryQueue($generatedFixtureClassDuplicateGroups);
        $blockingClassDuplicateGroups = $this->blockingClassDuplicateGroups($classDuplicateGroups);
        $duplicateClassCleanupQueue = $this->duplicateClassCleanupQueue($classDuplicateGroups);
        $triageQueue = $this->duplicationTriageQueue($blockingClassDuplicateGroups, $runtimeReviewRouteActionAliasGroups);
        $runtimeDocumentedRouteAliasBoundaryQueue = $this->runtimeRouteAliasCleanupQueue($runtimeDocumentedRouteActionAliasGroups, true);
        $runtimeRouteAliasCleanupQueue = $this->runtimeRouteAliasCleanupQueue($runtimeReviewRouteActionAliasGroups);
        $unresolvedSourceMaterialShadowCount = count(array_filter(
            $sourceMaterialShadowQueue,
            static fn (array $item): bool => in_array(
                (string) ($item['canonical_owner'] ?? ''),
                ['', 'owner_review_required'],
                true
            )
        ));
        $aiConfusionCleanupQueue = $this->aiConfusionCleanupQueue(
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

        return $this->envelope([
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
        $docs = $this->documentationInventory();
        $code = $this->codeInventory();
        $statusDrift = $this->statusDriftInventory($docs, $code);
        $reviewItems = $statusDrift['review_items'];

        return $this->envelope([
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

        return $this->envelope([
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

    /**
     * @return array<string,mixed>
     */
    private function documentationInventory(): array
    {
        $docs = [];
        foreach ($this->allFiles(['docs/engineering-knowledge-base']) as $file) {
            $path = $this->relativePath($file->getPathname());
            if (! str_ends_with($path, '.md')) {
                continue;
            }

            $frontmatter = $this->frontmatter($file->getPathname());
            $isCanonical = ($frontmatter['doc_schema'] ?? null) === 'atlas_canonical_module_doc.v1';
            $status = (string) ($frontmatter['status'] ?? 'missing');
            $isArchive = str_contains($path, '/archive/') || str_contains($path, 'archive/source-material/');
            $title = (string) ($frontmatter['title'] ?? basename($path));
            $docs[] = [
                'path' => $path,
                'path_stem' => strtolower(pathinfo($path, PATHINFO_FILENAME)),
                'title' => $title,
                'title_key' => strtolower($title),
                'id' => (string) ($frontmatter['id'] ?? ''),
                'graph_id' => (string) ($frontmatter['graph_id'] ?? ''),
                'owner' => (string) ($frontmatter['owner'] ?? 'unknown'),
                'status' => $status,
                'authority_class' => (string) ($frontmatter['authority_class'] ?? ''),
                'implementation_state' => (string) ($frontmatter['implementation_state'] ?? ''),
                'canonical' => $isCanonical,
                'archive' => $isArchive,
                'critical_topics' => $this->criticalTopicsForFile($file->getPathname()),
            ];
        }

        $nonCanonicalActive = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['canonical'] === false
                && $doc['archive'] === false
                && ! in_array($doc['status'], ['archived', 'source_material'], true)
        ));
        $archivedSourceMaterial = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['archive'] === true
        ));
        $canonicalActive = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['canonical'] === true
                && $doc['archive'] === false
                && in_array($doc['status'], ['active', 'building', 'planned', 'future', 'implemented', 'implemented_ready', 'scaffold'], true)
        ));
        $activeNonArchive = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['archive'] === false
                && ! in_array($doc['status'], ['archived', 'source_material'], true)
        ));

        return [
            'doc_count' => count($docs),
            'canonical_doc_count' => count(array_filter($docs, static fn (array $doc): bool => $doc['canonical'] === true)),
            'canonical_active_items' => $canonicalActive,
            'active_non_archive_items' => $activeNonArchive,
            'non_canonical_active_count' => count($nonCanonicalActive),
            'non_canonical_active' => $nonCanonicalActive,
            'archived_source_material_count' => count($archivedSourceMaterial),
            'archived_source_material' => $archivedSourceMaterial,
            'critical_topic_noncanonical_samples' => array_slice(array_values(array_filter(
                $nonCanonicalActive,
                static fn (array $doc): bool => $doc['critical_topics'] !== []
            )), 0, 30),
            'items' => $docs,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function codeInventory(): array
    {
        $classes = [];
        $commands = [];
        $routes = [];
        $namedRoutes = [];
        $routeActions = [];
        $legacySignals = [];
        $aiServiceRecords = [];
        foreach ($this->allFiles(['app', 'routes', 'config', 'database']) as $file) {
            $path = $this->relativePath($file->getPathname());
            if (! $this->isTextFile($file->getPathname())) {
                continue;
            }

            $content = $this->readSmallFile($file->getPathname());
            if ($content === null) {
                continue;
            }

            if (str_ends_with($path, '.php')) {
                foreach ($this->declaredPhpTypeNames($content) as $name) {
                    $classes[] = ['name' => $name, 'path' => $path];
                }
            }

            if (preg_match('/protected\s+\$signature\s*=\s*[\'"]([^\'"]+)/', $content, $signatureMatch)) {
                $signature = trim((string) $signatureMatch[1]);
                $commands[] = [
                    'signature' => preg_split('/\s+/', $signature)[0] ?? $signature,
                    'full_signature' => $signature,
                    'path' => $path,
                ];
            }

            if (str_starts_with($path, 'routes/')) {
                $routeInventory = $this->routeInventoryForContent($content, $path);
                $routes = array_merge($routes, $routeInventory['routes']);
                $namedRoutes = array_merge($namedRoutes, $routeInventory['named_routes']);
                $routeActions = array_merge($routeActions, $routeInventory['route_actions']);
            }

            if (str_starts_with($path, 'app/Services/Ai/')) {
                $aiServiceRecords[] = [
                    'path' => $path,
                    'subarea' => $this->aiServiceSubarea($path),
                    'topics' => $this->aiServiceTopics($content),
                ];
            }

            foreach ($this->legacySignalContexts($content) as $context) {
                $legacySignals[] = [
                    'path' => $path,
                    'signal' => $context['signal'],
                    'line' => $context['line'],
                    'excerpt' => $context['excerpt'],
                    'source_type' => $context['source_type'],
                ];
            }
        }

        $legacySignalInventory = $this->legacySignalTriageQueue($legacySignals);
        $legacyTriageQueue = $this->legacyOperationalTriageQueue($legacySignalInventory);
        $legacyCleanupQueue = $this->legacyCleanupQueue($legacySignalInventory);

        return [
            'classes' => $classes,
            'artisan_commands' => $commands,
            'routes' => $routes,
            'named_routes' => $namedRoutes,
            'route_actions' => $routeActions,
            'legacy_signal_count' => count($legacySignals),
            'legacy_signals' => $legacySignals,
            'legacy_signal_groups' => $this->legacySignalGroups($legacySignals, 'signal'),
            'legacy_area_groups' => $this->legacySignalGroups($legacySignals, 'area'),
            'legacy_signal_inventory' => $legacySignalInventory,
            'legacy_triage_queue' => $legacyTriageQueue,
            'legacy_cleanup_queue' => $legacyCleanupQueue,
            'legacy_operational_groups' => $this->legacyOperationalGroups($legacySignalInventory),
            'legacy_operational_summary' => $this->legacyOperationalSummary($legacySignalInventory),
            'ai_service_hotspots' => $this->aiServiceHotspots($aiServiceRecords, $legacySignals),
        ];
    }

    /**
     * @return array<int,array{signal:string,line:int,excerpt:string,source_type:string}>
     */
    private function legacySignalContexts(string $content): array
    {
        $items = [];
        $seen = [];
        $lines = preg_split('/\R/', $content) ?: [];
        foreach ($lines as $index => $line) {
            $signals = [];
            if (preg_match_all('/\b(legacy|deprecated|scaffold|planned_scaffold|executed_scaffold|duplicate)\b/i', (string) $line, $legacyMatches)) {
                $signals = array_merge($signals, $legacyMatches[1] ?? []);
            }
            if (preg_match_all('/(?<![A-Za-z0-9_])(TODO|FIXME)(?![A-Za-z0-9_])/', (string) $line, $todoMatches)) {
                $signals = array_merge($signals, $todoMatches[1] ?? []);
            }
            if ($signals === []) {
                continue;
            }

            $excerpt = trim(preg_replace('/\s+/', ' ', (string) $line) ?? '');
            if (strlen($excerpt) > 180) {
                $excerpt = substr($excerpt, 0, 177).'...';
            }
            foreach ($signals as $match) {
                $signal = strtolower((string) $match);
                $dedupeKey = ($index + 1).':'.$signal.':'.$excerpt;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $items[] = [
                    'signal' => $signal,
                    'line' => $index + 1,
                    'excerpt' => $excerpt,
                    'source_type' => $this->legacySignalSourceType((string) $line),
                ];
            }
        }

        return $items;
    }

    private function legacySignalSourceType(string $line): string
    {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '|')) {
            return 'docblock';
        }
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
            return 'comment';
        }
        if (preg_match('/[\'"][^\'"]*(legacy|deprecated|scaffold|planned_scaffold|executed_scaffold|duplicate)[^\'"]*[\'"]/i', $line)
            || preg_match('/[\'"][^\'"]*(TODO|FIXME)[^\'"]*[\'"]/', $line)) {
            return 'string_literal';
        }

        return 'code';
    }

    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $code
     * @return array<string,mixed>
     */
    private function statusDriftInventory(array $docs, array $code): array
    {
        $commandSignatures = $this->uniqueStrings(array_map(
            static fn (array $command): string => (string) ($command['signature'] ?? ''),
            (array) ($code['artisan_commands'] ?? [])
        ));
        $reviewItems = [];
        $statusCounts = [];
        $plannedOrFutureWithExistingCode = 0;
        $scaffoldLanguageWithExistingCode = 0;
        $plannedFutureBoundaryExplained = 0;

        foreach ((array) ($docs['canonical_active_items'] ?? []) as $doc) {
            $path = (string) ($doc['path'] ?? '');
            $docId = strtolower((string) ($doc['id'] ?: $doc['path_stem'] ?? md5($path)));
            $status = strtolower((string) ($doc['status'] ?? 'missing'));
            $statusCounts[$status] = ((int) ($statusCounts[$status] ?? 0)) + 1;
            $content = $this->readSmallFile(base_path($path));
            if ($content === null) {
                continue;
            }

            $evidence = $this->docImplementationEvidence($content, $commandSignatures);
            $bodyState = $this->docBodyImplementationLanguage($content);
            $evidenceScore = (int) $evidence['evidence_score'];
            $isPlannedLikeStatus = in_array($status, ['planned', 'future', 'scaffold'], true);
            $hasExistingCode = $evidenceScore >= 3;
            $hasScaffoldLanguage = $bodyState['has_scaffold_language'] === true;
            $hasImplementedLanguage = $bodyState['has_implemented_language'] === true;

            if ($this->allowsMixedStatusMatrixLanguage($docId) && ($hasScaffoldLanguage || $isPlannedLikeStatus)) {
                continue;
            }

            if ($isPlannedLikeStatus && $hasExistingCode) {
                $plannedOrFutureWithExistingCode++;
            }
            if ($hasScaffoldLanguage && $hasExistingCode) {
                $scaffoldLanguageWithExistingCode++;
            }

            $reason = null;
            $severity = 'review';
            $boundaryContract = $this->explicitBacklogStatusBoundaryContract($doc)
                ?? $this->explicitNoRuntimeStatusBoundaryContract($doc)
                ?? $this->explicitPartialRuntimeStatusBoundaryContract($doc)
                ?? $this->explicitGatedRuntimeStatusBoundaryContract($doc)
                ?? $this->explicitNorthStarStatusBoundaryContract($doc)
                ?? $this->explicitImplementationStateStatusBoundaryContract($doc)
                ?? $this->statusDriftBoundaryContract($docId);
            if ($isPlannedLikeStatus && $hasExistingCode) {
                if ($boundaryContract !== null) {
                    $plannedFutureBoundaryExplained++;
                    if (($boundaryContract['review_policy'] ?? null) === 'suppress_status_drift_review_item') {
                        continue;
                    }
                    $reason = 'planned_or_future_status_references_existing_dependency_boundary';
                    $severity = 'low';
                } else {
                    $reason = 'frontmatter_status_may_understate_existing_code';
                    $severity = $evidenceScore >= 8 ? 'high' : 'medium';
                }
            } elseif ($hasScaffoldLanguage && $hasExistingCode && $hasImplementedLanguage) {
                if (($boundaryContract['review_policy'] ?? null) === 'suppress_status_drift_review_item') {
                    continue;
                }
                $reason = 'body_mixes_scaffold_and_implemented_language_with_code_evidence';
                $severity = $evidenceScore >= 8 ? 'medium' : 'review';
            } elseif ($hasScaffoldLanguage && $evidenceScore >= 8) {
                if (($boundaryContract['review_policy'] ?? null) === 'suppress_status_drift_review_item') {
                    continue;
                }
                $reason = 'scaffold_language_has_strong_code_evidence';
                $severity = 'medium';
            }

            if ($reason === null) {
                continue;
            }

            $boundaryContract ??= $this->defaultStatusDriftBoundaryContract($status, $reason, $evidenceScore);

            $reviewItems[] = [
                'id' => 'status_drift:'.$docId,
                'path' => $path,
                'title' => (string) ($doc['title'] ?? ''),
                'owner' => (string) ($doc['owner'] ?? 'unknown'),
                'doc_area' => $this->statusDriftDocArea($path),
                'frontmatter_status' => $status,
                'severity' => $severity,
                'reason' => $reason,
                'boundary_contract' => $boundaryContract,
                'implementation_evidence' => $evidence,
                'body_language' => $bodyState,
                'required_decision' => 'confirm_planned|mark_partial|mark_implemented|split_future_work_from_implemented_runtime',
                'policy' => 'candidate_only_owner_doc_must_decide_before_status_change',
            ];
        }

        usort($reviewItems, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                ?: ((int) data_get($b, 'implementation_evidence.evidence_score', 0)) <=> ((int) data_get($a, 'implementation_evidence.evidence_score', 0));
        });

        return [
            'summary' => [
                'canonical_doc_count' => count((array) ($docs['canonical_active_items'] ?? [])),
                'status_counts' => $statusCounts,
                'review_count' => count($reviewItems),
                'owner_group_count' => count($this->statusDriftGroups($reviewItems, 'owner')),
                'doc_area_group_count' => count($this->statusDriftGroups($reviewItems, 'doc_area')),
                'planned_or_future_with_existing_code_count' => $plannedOrFutureWithExistingCode,
                'planned_future_boundary_explained_count' => $plannedFutureBoundaryExplained,
                'scaffold_language_with_existing_code_count' => $scaffoldLanguageWithExistingCode,
                'policy' => 'review_count_is_drift_pressure_not_automatic_status_mutation',
            ],
            'owner_groups' => $this->statusDriftGroups($reviewItems, 'owner'),
            'doc_area_groups' => $this->statusDriftGroups($reviewItems, 'doc_area'),
            'review_items' => $reviewItems,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function statusDriftGroups(array $items, string $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $value = (string) ($item[$key] ?? 'unknown');
            $groups[$value][] = $item;
        }

        $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];
        $result = [];
        foreach ($groups as $value => $groupItems) {
            usort($groupItems, static function (array $a, array $b) use ($rank): int {
                return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                    ?: ((int) data_get($b, 'implementation_evidence.evidence_score', 0)) <=> ((int) data_get($a, 'implementation_evidence.evidence_score', 0));
            });

            $severityCounts = [];
            foreach ($groupItems as $item) {
                $severity = (string) ($item['severity'] ?? 'review');
                $severityCounts[$severity] = ((int) ($severityCounts[$severity] ?? 0)) + 1;
            }

            $result[] = [
                'value' => $value,
                'count' => count($groupItems),
                'top_severity' => (string) ($groupItems[0]['severity'] ?? 'review'),
                'severity_counts' => $severityCounts,
                'samples' => array_slice(array_map(static fn (array $item): array => [
                    'id' => (string) ($item['id'] ?? ''),
                    'path' => (string) ($item['path'] ?? ''),
                    'frontmatter_status' => (string) ($item['frontmatter_status'] ?? ''),
                    'severity' => (string) ($item['severity'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                    'evidence_score' => (int) data_get($item, 'implementation_evidence.evidence_score', 0),
                ], $groupItems), 0, 8),
                'policy' => $key === 'owner'
                    ? 'owner_should_triage_highest_evidence_docs_first'
                    : 'area_group_is_cleanup_batch_not_authority_boundary',
            ];
        }

        usort($result, static function (array $a, array $b) use ($rank): int {
            return ($rank[$a['top_severity']] ?? 9) <=> ($rank[$b['top_severity']] ?? 9)
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $result;
    }

    private function statusDriftDocArea(string $path): string
    {
        if (str_contains($path, '/self-construction/')) {
            return 'self-construction';
        }
        if (str_contains($path, '/domains/')) {
            return 'domains';
        }
        if (str_contains($path, '/architecture-audit/')) {
            return 'architecture-audit';
        }
        if (str_contains($path, '/vault/')) {
            return 'vault';
        }
        if (str_contains($path, '/system-graph/')) {
            return 'system-graph';
        }
        if (str_contains($path, '/research-self-improvement/')) {
            return 'research-self-improvement';
        }

        return 'root';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitBacklogStatusBoundaryContract(array $doc): ?array
    {
        $authorityClass = strtolower((string) ($doc['authority_class'] ?? ''));
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if ($authorityClass !== 'backlog') {
            return null;
        }
        if (! str_starts_with($implementationState, 'backlog_only')
            && ! (str_contains($implementationState, 'backlog') && str_contains($implementationState, 'no_runtime'))) {
            return null;
        }

        return [
            'classification' => 'planned_backlog_doc_with_existing_code_refs',
            'authority_class' => $authorityClass,
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'backlog_docs_may_cite_target_code_tests_and_commands_as_slice_inputs_without_being_runtime_proof',
            'existing_runtime_refs_are' => 'planned_slice_targets_or_execution_inputs_not_doc_runtime_completion',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_mark_backlog_doc_implemented_from_target_refs_or_ready_rows',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitNoRuntimeStatusBoundaryContract(array $doc): ?array
    {
        $authorityClass = strtolower((string) ($doc['authority_class'] ?? ''));
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_contains($implementationState, 'no_runtime')) {
            return null;
        }
        if (! in_array($authorityClass, ['proposal', 'runbook', 'planner', 'proposer'], true)) {
            return null;
        }

        return [
            'classification' => 'explicit_no_runtime_doc_with_existing_code_refs',
            'authority_class' => $authorityClass,
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'proposal_runbook_planner_and_proposer_docs_may_cite_existing_code_as_analysis_dependencies_or_targets_without_claiming_runtime_delivery',
            'existing_runtime_refs_are' => 'analysis_dependencies_or_remediation_targets_not_proof_that_the_doc_runtime_is_implemented',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_mark_no_runtime_proposal_runbook_planner_or_proposer_implemented_from_dependency_refs',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitPartialRuntimeStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_starts_with($implementationState, 'partial_runtime_with_future')) {
            return null;
        }

        return [
            'classification' => 'partial_runtime_doc_with_future_scope',
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'the_doc_owns_a_live_partial_runtime_or_read_model_while_explicitly_preserving_future_scope',
            'existing_runtime_refs_are' => 'implemented_slice_evidence_not_full_completion_or_permission_to_skip_remaining_gates',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_treat_partial_runtime_docs_as_future_only_or_complete_runtime',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitGatedRuntimeStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_contains($implementationState, 'shadow_gated')
            && ! str_contains($implementationState, 'fail_closed')) {
            return null;
        }

        return [
            'classification' => str_contains($implementationState, 'fail_closed')
                ? 'experimental_fail_closed_runtime_with_blocked_promotion_scope'
                : 'active_shadow_gated_runtime_with_blocked_promotion_scope',
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'runtime_exists_but_promotion_or_topology_changes_remain_operator_gated',
            'existing_runtime_refs_are' => 'shadow_runtime_evidence_not_permission_for_automatic_activation_or_external_claims',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_treat_shadow_gated_runtime_as_auto_promoted_or_unimplemented',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitNorthStarStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_contains($implementationState, 'north_star_no_runtime')) {
            return null;
        }

        return [
            'classification' => 'north_star_doc_with_existing_governance_refs',
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'north_star_docs_may_cite_existing_governance_commands_and_owner_docs_without_claiming_runtime_delivery',
            'existing_runtime_refs_are' => 'sequencing_dependencies_or_governance_gates_not_proof_that_the_north_star_runtime_exists',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_mark_north_star_docs_implemented_from_docs_health_architecture_or_owner_doc_refs',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitImplementationStateStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if ($implementationState === '') {
            return null;
        }

        if ($this->containsAny($implementationState, [
            'no_code_yet',
            'not_current_runtime',
            'not_runtime_complete',
            'not_runtime_promoted',
            'not_promoted',
            'paper_only',
            'spec_only',
            'no_runtime_change',
        ])) {
            return [
                'classification' => 'explicit_not_promoted_or_no_runtime_implementation_state',
                'implementation_state' => $implementationState,
                'current_runtime_boundary' => 'the_doc_frontmatter_explicitly_says_refs_are_future_targets_plans_or_unpromoted_boundaries_not_current_runtime_completion',
                'existing_runtime_refs_are' => 'dependencies_targets_or_forbidden_scope_not_proof_that_this_doc_runtime_is_promoted',
                'review_policy' => 'suppress_status_drift_review_item',
                'forbidden' => 'do_not_promote_docs_from_existing_refs_when_implementation_state_says_not_promoted_or_no_current_runtime',
            ];
        }

        if ($this->containsAny($implementationState, [
            'runtime_surface_',
            'runtime_available',
            'implemented_',
            'read_only_',
            'scorecard_ready',
            'partial_php_baseline',
            'shadow_available',
            '_present',
            '_ready',
            'staging_only',
        ])) {
            return [
                'classification' => 'explicit_runtime_slice_implementation_state',
                'implementation_state' => $implementationState,
                'current_runtime_boundary' => 'the_doc_frontmatter_declares_a_specific_live_or_read_only_runtime_slice_while_body_language_may_still_name_future_or_unfinished_scope',
                'existing_runtime_refs_are' => 'slice_evidence_not_full_completion_or_permission_to_ignore_remaining_gates',
                'review_policy' => 'suppress_status_drift_review_item',
                'forbidden' => 'do_not_treat_explicit_runtime_slice_docs_as_either_future_only_or_complete_runtime',
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function statusDriftBoundaryContract(string $docId): ?array
    {
        return match ($docId) {
            'atlas-forge-rivals-intelligence-ledger-v1' => [
                'classification' => 'planned_next_layer_over_existing_provider_performance_ledger',
                'current_runtime_boundary' => 'Provider Performance Ledger and Decide signal projection exist; Intelligence Ledger v1 remains proposed next patamar until historical segmented ledger tests and two real batteries exist.',
                'existing_runtime_refs_are' => 'dependencies_not_proof_that_this_planned_layer_is_implemented',
                'forbidden' => 'do_not_treat_existing_forge_rivals_ledger_services_as_intelligence_ledger_v1_complete',
            ],
            'atlas-cartographic-knowledge-os' => [
                'classification' => 'future_visual_os_over_existing_vault_graph_readers',
                'current_runtime_boundary' => 'Vault graph readers and Universal Reality Cartography are existing inputs/surfaces; Cartographic Knowledge OS remains future target for visual semantic zoom OS.',
                'existing_runtime_refs_are' => 'predecessor_or_input_runtime_not_future_visual_os_completion',
                'forbidden' => 'do_not_claim_cartographic_knowledge_os_active_because_vault_graph_reader_exists',
            ],
            'atlas-vox-v4-contextual-operator-plan' => [
                'classification' => 'paper_only_v4_plan_with_forbidden_runtime_boundary',
                'current_runtime_boundary' => 'Voice/Vox paths are cited as boundary and forbidden scope; no Vox V4 runtime is authorized before V3 gate and ADR 0004.',
                'existing_runtime_refs_are' => 'boundary_and_do_not_touch_refs_not_implementation_evidence',
                'forbidden' => 'do_not_create_vox_v4_runtime_or_touch_voice_runtime_from_this_plan',
            ],
            default => null,
        };
    }

    private function allowsMixedStatusMatrixLanguage(string $docId): bool
    {
        return in_array($docId, [
            'atlas-execution-doctrine-runtime-matrix',
            'atlas-duplication-reality-governance',
            'atlas-canonical-cleanup-inventory',
            'atlas-runtime-spine-completion-audit',
            'atlas-ai-research-self-improvement-runtime',
            'atlas-pre-benchmark-readiness-audit',
            'atlas-code-reality-usage-intelligence',
            'atlas-documentation-reality-system',
            'atlas-documentation-enforcement-runtime',
            'atlas-quality-preserving-efficiency-system',
            'atlas-persistent-context-runtime',
            'atlas-execution-memory-outcome-runtime',
            'atlas-kernel-mission-foundation',
            'atlas-ai-follow-through-loop',
            'atlas-ai-router-runtime-enterprise-upgrade',
            'atlas-aiworker-kernel-integration-adr',
            'atlas-autonomous-control-plane',
            'atlas-ai-programming-enterprise-implementation-plan',
            'atlas-programming-superiority-contracts',
            'atlas-programming-forge-flow',
            'atlas-long-horizon-intelligence-layer',
            'atlas-self-construction-agent-dispatch-planner-runtime-v1',
            'atlas-forge-live-execution-e2e-v1',
            'atlas-universal-reality-cartography',
        ], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultStatusDriftBoundaryContract(string $frontmatterStatus, string $reason, int $evidenceScore): array
    {
        return [
            'classification' => 'status_language_review_over_existing_evidence',
            'frontmatter_status' => $frontmatterStatus,
            'reason' => $reason,
            'evidence_score' => $evidenceScore,
            'current_runtime_boundary' => 'code_test_command_references_are_review_pressure_not_automatic_status_truth',
            'existing_runtime_refs_are' => 'candidate_evidence_requiring_owner_doc_reachability_tests_and_operator_decision',
            'required_owner_decision' => 'confirm_planned|mark_partial|mark_implemented|split_future_work_from_implemented_runtime',
            'safe_next_commands' => [
                'php artisan atlas:code-reality reachability --target="<runtime-or-doc-target>" --json',
                'php artisan atlas:code-reality status-drift-audit --json',
                'php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json',
            ],
            'forbidden' => 'do_not_auto_change_frontmatter_or_claim_doc_wrong_from_heuristic_evidence',
        ];
    }

    /**
     * @param  array<int,string>  $commandSignatures
     * @return array<string,mixed>
     */
    private function docImplementationEvidence(string $content, array $commandSignatures): array
    {
        preg_match_all('/\b(?:app|routes|config|database|tests)\/[A-Za-z0-9_\/.\-]+/', $content, $pathMatches);
        $pathRefs = $this->uniqueStrings($pathMatches[0] ?? []);
        $existingPathRefs = array_values(array_filter(
            $pathRefs,
            static fn (string $path): bool => File::exists(base_path($path))
        ));
        preg_match_all('/php artisan\s+([a-z0-9:._-]+)/i', $content, $commandMatches);
        $commandRefs = $this->uniqueStrings($commandMatches[1] ?? []);
        $existingCommandRefs = array_values(array_intersect($commandRefs, $commandSignatures));
        $testRefs = array_values(array_filter(
            $existingPathRefs,
            static fn (string $path): bool => str_starts_with($path, 'tests/')
        ));
        $runtimeRefs = array_values(array_filter(
            $existingPathRefs,
            static fn (string $path): bool => str_starts_with($path, 'app/') || str_starts_with($path, 'routes/') || str_starts_with($path, 'database/')
        ));

        return [
            'evidence_score' => count($runtimeRefs) + count($testRefs) + count($existingCommandRefs),
            'existing_path_ref_count' => count($existingPathRefs),
            'runtime_ref_count' => count($runtimeRefs),
            'test_ref_count' => count($testRefs),
            'existing_command_ref_count' => count($existingCommandRefs),
            'runtime_ref_samples' => array_slice($runtimeRefs, 0, 8),
            'test_ref_samples' => array_slice($testRefs, 0, 8),
            'command_ref_samples' => array_slice($existingCommandRefs, 0, 8),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docBodyImplementationLanguage(string $content): array
    {
        $lower = strtolower($content);
        $scaffoldTerms = ['scaffold', 'planned', 'future', 'not implemented', 'nao implementado', 'falta runtime', 'falta implementacao', 'missing implementation', 'missing runtime'];
        $implementedTerms = ['implemented_ready', 'implemented_partial', 'active_runtime', 'codigo/teste', 'tests existem', 'rotas', 'comando', 'runtime'];

        return [
            'has_scaffold_language' => $this->containsStatusLanguage($lower, $scaffoldTerms),
            'has_implemented_language' => $this->containsAny($lower, $implementedTerms),
            'policy' => 'language_is_heuristic_confirm_with_owner_doc_and_reachability',
        ];
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function containsStatusLanguage(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            $quoted = preg_quote($term, '/');
            $pattern = '/(?<![a-z0-9_-])'.$quoted.'(?![a-z0-9_-])/';

            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array{path:string,subarea:string,topics:array<int,string>}>  $records
     * @param  array<int,array<string,string>>  $legacySignals
     * @return array<string,mixed>
     */
    private function aiServiceHotspots(array $records, array $legacySignals): array
    {
        $legacyByPath = [];
        foreach ($legacySignals as $signal) {
            $path = (string) ($signal['path'] ?? '');
            if (str_starts_with($path, 'app/Services/Ai/')) {
                $legacyByPath[$path][] = (string) ($signal['signal'] ?? 'unknown');
            }
        }

        $subareas = [];
        $topics = [];
        foreach ($records as $record) {
            $subarea = $record['subarea'];
            $path = $record['path'];
            $subareas[$subarea] ??= [
                'value' => $subarea,
                'file_count' => 0,
                'legacy_signal_count' => 0,
                'topic_counts' => [],
                'samples' => [],
            ];
            $subareas[$subarea]['file_count']++;
            if (isset($legacyByPath[$path])) {
                $subareas[$subarea]['legacy_signal_count'] += count($legacyByPath[$path]);
                if (count($subareas[$subarea]['samples']) < 8) {
                    $subareas[$subarea]['samples'][] = [
                        'path' => $path,
                        'signals' => $this->uniqueStrings($legacyByPath[$path]),
                    ];
                }
            }

            foreach ($record['topics'] as $topic) {
                $topics[$topic] ??= ['value' => $topic, 'file_count' => 0, 'legacy_signal_count' => 0, 'samples' => []];
                $topics[$topic]['file_count']++;
                $subareas[$subarea]['topic_counts'][$topic] = ((int) ($subareas[$subarea]['topic_counts'][$topic] ?? 0)) + 1;
                if (isset($legacyByPath[$path])) {
                    $topics[$topic]['legacy_signal_count'] += count($legacyByPath[$path]);
                    if (count($topics[$topic]['samples']) < 8) {
                        $topics[$topic]['samples'][] = [
                            'path' => $path,
                            'signals' => $this->uniqueStrings($legacyByPath[$path]),
                        ];
                    }
                }
            }
        }

        $subareas = array_values($subareas);
        usort($subareas, static fn (array $a, array $b): int => ((int) $b['legacy_signal_count']) <=> ((int) $a['legacy_signal_count'])
            ?: ((int) $b['file_count']) <=> ((int) $a['file_count']));
        $topics = array_values($topics);
        usort($topics, static fn (array $a, array $b): int => ((int) $b['legacy_signal_count']) <=> ((int) $a['legacy_signal_count'])
            ?: ((int) $b['file_count']) <=> ((int) $a['file_count']));

        return [
            'file_count' => count($records),
            'legacy_signal_count' => array_sum(array_map('count', $legacyByPath)),
            'subareas' => array_slice($subareas, 0, 20),
            'topics' => array_slice($topics, 0, 20),
            'policy' => 'hotspot_rank_is_triage_priority_not_delete_authority',
        ];
    }

    private function aiServiceSubarea(string $path): string
    {
        $parts = explode('/', $path);
        $segment = $parts[3] ?? '_root';

        return 'app/Services/Ai/'.$segment;
    }

    /**
     * @return array<int,string>
     */
    private function aiServiceTopics(string $content): array
    {
        $lower = strtolower($content);
        $topics = [];
        $definitions = [
            'memory_rag_retrieval' => ['memory', 'rag', 'retrieval', 'embedding', 'context pack', 'open brain'],
            'self_construction' => ['self construction', 'selfconstruction', 'agent control plane', 'completion audit'],
            'runtime_orchestration' => ['runtime', 'execution', 'worker', 'dispatch', 'orchestration'],
            'domain_runtime' => ['domain runtime', 'domain manifest', 'domain maturity'],
            'voice_vox' => ['voice', 'vox', 'livekit', 'tts', 'stt'],
            'programming_forge' => ['programming', 'forge', 'atlasdev', 'work packet'],
        ];
        foreach ($definitions as $topic => $terms) {
            foreach ($terms as $term) {
                if (str_contains($lower, $term)) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * @param  array<int,array<string,string>>  $legacySignals
     * @return array<int,array<string,mixed>>
     */
    private function legacySignalGroups(array $legacySignals, string $groupBy): array
    {
        $groups = [];
        foreach ($legacySignals as $signal) {
            $path = (string) ($signal['path'] ?? '');
            $key = $groupBy === 'area'
                ? $this->legacySignalArea($path)
                : (string) ($signal['signal'] ?? 'unknown');
            $groups[$key][] = $signal + ['area' => $this->legacySignalArea($path)];
        }

        $items = [];
        foreach ($groups as $key => $signals) {
            $items[] = [
                'value' => $key,
                'count' => count($signals),
                'samples' => array_slice(array_values($signals), 0, 10),
                'policy' => $groupBy === 'area'
                    ? 'triage_area_with_reachability_before_cleanup'
                    : 'signal_is_keyword_not_deletion_authority',
            ];
        }
        usort($items, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return $items;
    }

    private function legacySignalArea(string $path): string
    {
        $parts = explode('/', $path);
        if (($parts[0] ?? '') !== 'app') {
            return $parts[0] ?? 'unknown';
        }
        if (($parts[1] ?? '') === 'Services' && isset($parts[2])) {
            return 'app/Services/'.$parts[2];
        }
        if (($parts[1] ?? '') === 'Http' && isset($parts[2])) {
            return 'app/Http/'.$parts[2];
        }
        if (($parts[1] ?? '') === 'Console' && isset($parts[2])) {
            return 'app/Console/'.$parts[2];
        }
        if (in_array(($parts[1] ?? ''), ['Models', 'Enums'], true)) {
            return 'app/'.$parts[1];
        }

        return implode('/', array_slice($parts, 0, min(3, count($parts))));
    }

    /**
     * @param  array<int,array<string,string>>  $legacySignals
     * @return array<int,array<string,mixed>>
     */
    private function legacySignalTriageQueue(array $legacySignals): array
    {
        $items = [];
        foreach ($legacySignals as $signal) {
            $path = (string) ($signal['path'] ?? '');
            $type = (string) ($signal['signal'] ?? 'unknown');
            $area = $this->legacySignalArea($path);
            $items[] = [
                'id' => 'legacy_signal:'.$type.':'.str_replace(['/', '.', '\\'], ':', $path).':'.((int) ($signal['line'] ?? 0)),
                'kind' => 'legacy_or_scaffold_signal',
                'signal' => $type,
                'severity' => $this->legacySignalSeverity($type, $area),
                'path' => $path,
                'area' => $area,
                'line' => $signal['line'] ?? null,
                'excerpt' => $signal['excerpt'] ?? null,
                'source_type' => $signal['source_type'] ?? 'unknown',
                'operational_classification' => $this->legacySignalOperationalClassification($type, $signal),
                'boundary_contract' => $this->legacySignalBoundaryContract($type, $path, $area),
                'cleanup_recommendation' => $this->legacySignalCleanupRecommendation($type),
                'claim_policy' => 'keyword_signal_is_triage_pressure_not_dead_code_or_delete_authority',
                'next_commands' => [
                    'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                    'php artisan atlas:code-reality deletion-preflight --target="'.$path.'" --json',
                ],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $legacySignalInventory
     * @return array<int,array<string,mixed>>
     */
    private function legacyOperationalTriageQueue(array $legacySignalInventory): array
    {
        return array_values(array_filter($legacySignalInventory, static function (array $item): bool {
            $classification = (array) ($item['operational_classification'] ?? []);

            return in_array((string) ($classification['cleanup_pressure'] ?? 'unknown'), ['review', 'documentation_review'], true)
                || (string) ($classification['ia_confusion_risk'] ?? 'unknown') === 'high';
        }));
    }

    /**
     * @param  array<int,array<string,mixed>>  $legacyTriageQueue
     * @return array<int,array<string,mixed>>
     */
    private function legacyCleanupQueue(array $legacyTriageQueue): array
    {
        $items = [];
        foreach ($legacyTriageQueue as $item) {
            $classification = (array) ($item['operational_classification'] ?? []);
            $bucket = (string) ($classification['bucket'] ?? 'unknown');
            $cleanupPressure = (string) ($classification['cleanup_pressure'] ?? 'unknown');
            if ($cleanupPressure !== 'review') {
                continue;
            }

            $path = (string) ($item['path'] ?? '');
            $items[] = [
                'id' => 'legacy_cleanup:'.str_replace(['/', '.', '\\'], ':', $path).':'.((int) ($item['line'] ?? 0)).':'.((string) ($item['signal'] ?? 'unknown')),
                'kind' => 'legacy_cleanup_candidate',
                'path' => $path,
                'line' => $item['line'] ?? null,
                'signal' => (string) ($item['signal'] ?? 'unknown'),
                'excerpt' => $item['excerpt'] ?? null,
                'source_type' => (string) ($item['source_type'] ?? 'unknown'),
                'area' => (string) ($item['area'] ?? $this->legacySignalArea($path)),
                'bucket' => $bucket,
                'subtype' => (string) ($classification['subtype'] ?? 'unknown'),
                'ia_confusion_risk' => (string) ($classification['ia_confusion_risk'] ?? 'unknown'),
                'safe_interpretation' => (string) ($classification['safe_interpretation'] ?? 'requires_owner_review'),
                'delete_allowed' => false,
                'rename_allowed_without_owner_decision' => false,
                'recommended_cleanup_direction' => $this->legacyCleanupDirection($bucket),
                'boundary_contract' => $item['boundary_contract'] ?? [],
                'next_commands' => $item['next_commands'] ?? [],
                'claim_policy' => 'cleanup_queue_is_review_order_not_delete_authority',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $riskRank = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

            return ($riskRank[$a['ia_confusion_risk']] ?? 9) <=> ($riskRank[$b['ia_confusion_risk']] ?? 9)
                ?: strcmp((string) ($a['area'] ?? ''), (string) ($b['area'] ?? ''))
                ?: strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
        });

        return array_slice($items, 0, 50);
    }

    private function legacyCleanupDirection(string $bucket): string
    {
        return match ($bucket) {
            'scaffold_status_or_filter' => 'verify_if_runtime_taxonomy_or_partial_flow_then_split_status_language_from_active_runtime_contract',
            'compatibility_adapter_code' => 'prove_replacement_and_callers_then_write_deprecation_or_alias_boundary_before_rename',
            'compatibility_wrapper_doc' => 'prove_replacement_and_callers_then_write_deprecation_or_alias_boundary_before_rename',
            'commentary_or_documentation_language' => 'convert_comment_or_docblock_into_owner_doc_decision_or_remove_stale_comment_after_tests',
            'runtime_keyword_pressure' => 'run_reachability_and_owner_doc_review_before_keep_rename_quarantine_or_delete_decision',
            default => 'owner_review_before_any_cleanup',
        };
    }

    /**
     * @param  array<string,mixed>  $signal
     * @return array<string,string>
     */
    private function legacySignalOperationalClassification(string $type, array $signal): array
    {
        $path = (string) ($signal['path'] ?? '');
        $sourceType = (string) ($signal['source_type'] ?? 'unknown');
        $excerpt = strtolower((string) ($signal['excerpt'] ?? ''));

        if ($type === 'scaffold'
            && ($path === 'app/Console/Commands/AtlasScaffoldStageCommand.php'
                || $path === 'app/Services/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorService.php'
                || str_contains($excerpt, 'self-construction scaffold staging executor'))) {
            return [
                'bucket' => 'canonical_self_construction_scaffold_staging_runtime',
                'subtype' => 'approved_proposal_staging_runtime',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'scaffold_is_canonical_self_construction_staging_term_not_legacy_code',
            ];
        }

        if ($type === 'deprecated'
            && $path === 'app/Services/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionService.php'
            && (str_contains($excerpt, 'deprecatedfields')
                || str_contains($excerpt, 'deprecated fields')
                || str_contains($excerpt, '$deprecated')
                || str_contains($excerpt, 'array $deprecated'))) {
            return [
                'bucket' => 'canonical_schema_evolution_field_lifecycle',
                'subtype' => 'schema_evolution_deprecated_fields_contract',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'deprecated_fields_are_schema_lifecycle_payload_not_deprecated_runtime_code',
            ];
        }

        $lifecycleTaxonomy = $this->legacyLifecycleTaxonomyClassification($type, $excerpt);
        if ($lifecycleTaxonomy !== null) {
            return $lifecycleTaxonomy;
        }

        if (in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true)
            && $sourceType === 'string_literal'
            && str_contains($excerpt, 'implemented-vs-scaffold-matrix')) {
            return [
                'bucket' => 'diagnostic_architecture_matrix_reference',
                'subtype' => 'implemented_vs_scaffold_read_model_reference',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'implemented_vs_scaffold_matrix_is_diagnostic_read_model_not_legacy_runtime',
            ];
        }

        $scaffoldBoundary = $this->legacyScaffoldBoundaryClassification($type, $path, $excerpt);
        if ($scaffoldBoundary !== null) {
            return $scaffoldBoundary;
        }

        if ($sourceType === 'string_literal' && preg_match('/(^[\'"]deprecated[\'"],?$|status|statuses|maturity|rule::in|--status|onboarding-status|scaffold_domains|planned_scaffold|executed_scaffold|attempted_scaffold|repair_scaffold|status_deprecated|tool_status)/', $excerpt)) {
            return [
                'bucket' => 'status_taxonomy_value',
                'subtype' => 'status_or_filter_value',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'allowed_status_or_filter_value_not_dead_code',
            ];
        }

        if ($sourceType === 'code' && preg_match('/^\{--[a-z0-9-]+(?:=|\s|:)/i', $excerpt)) {
            return [
                'bucket' => 'status_taxonomy_value',
                'subtype' => 'cli_filter_or_compatibility_option',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'cli_option_contract_value_not_dead_code',
            ];
        }

        if ($type === 'legacy' && $sourceType === 'code' && preg_match('/fromlegacy|\$legacy|legacy:|legacy key|legacy alias|legacy-pure|legacy single-line|legacy path|legacy worker|legacy pipeline|older dashboards|older clients|backward compatibility|compatibility|pre-freshness receipt/', $excerpt)) {
            return [
                'bucket' => 'compatibility_adapter_code',
                'subtype' => 'legacy_input_adapter',
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'legacy_adapter_may_be_intentional_until_external_callers_are_migrated',
            ];
        }

        if ($sourceType === 'docblock' && str_contains($excerpt, 'deprecated wrapper')) {
            return [
                'bucket' => 'compatibility_wrapper_doc',
                'subtype' => 'compatibility_docblock',
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'high',
                'safe_interpretation' => 'compatibility_path_may_be_intentional_until_owner_deprecation_plan_exists',
            ];
        }

        if ($type === 'duplicate' && in_array($sourceType, ['code', 'string_literal'], true)) {
            return [
                'bucket' => 'deduplication_runtime_language',
                'subtype' => 'deduplication_algorithm_or_status',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'code_is_about_detecting_duplicates_not_itself_duplicate_by_keyword',
            ];
        }

        if (in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true)
            && $sourceType === 'string_literal'
            && str_contains($excerpt, 'implemented-vs-scaffold-matrix')) {
            return [
                'bucket' => 'diagnostic_architecture_matrix_reference',
                'subtype' => 'implemented_vs_scaffold_read_model_reference',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'implemented_vs_scaffold_matrix_is_diagnostic_read_model_not_legacy_runtime',
            ];
        }

        if (in_array($sourceType, ['comment', 'docblock'], true)) {
            return [
                'bucket' => 'commentary_or_documentation_language',
                'subtype' => $sourceType,
                'cleanup_pressure' => 'inventory',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'comment_or_doc_text_is_audit_inventory_not_cleanup_review',
            ];
        }

        if (in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true) && $sourceType === 'string_literal') {
            return [
                'bucket' => 'scaffold_status_or_filter',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'high',
                'safe_interpretation' => 'may_be_runtime_state_taxonomy_or_partial_flow_not_delete_signal',
            ];
        }

        if ($sourceType === 'code') {
            return [
                'bucket' => 'runtime_keyword_pressure',
                'subtype' => 'runtime_keyword',
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'high',
                'safe_interpretation' => 'requires_reachability_owner_doc_and_tests_before_cleanup',
            ];
        }

        return [
            'bucket' => 'runtime_keyword_inventory',
            'subtype' => 'string_literal_keyword',
            'cleanup_pressure' => 'inventory',
            'ia_confusion_risk' => 'medium',
            'safe_interpretation' => 'string_literal_keyword_is_audit_inventory_until_code_reachability_proves_cleanup_pressure',
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function legacyLifecycleTaxonomyClassification(string $type, string $excerpt): ?array
    {
        if ($type !== 'deprecated') {
            return null;
        }

        if (! preg_match('/deprecated(_at|fields?)|state_deprecated|deprecated state|terminal deprecated|deprecation|timestamp|schema|entry|entries|lifecycle|blocked_private|tombstoned|\$deprecated|bool \$deprecated|if \(\$deprecated|status_deprecated|tool_status|maturity|rule::in/', $excerpt)) {
            return null;
        }

        return [
            'bucket' => 'lifecycle_taxonomy_value',
            'subtype' => 'deprecated_status_or_schema_lifecycle',
            'cleanup_pressure' => 'none',
            'ia_confusion_risk' => 'medium',
            'safe_interpretation' => 'deprecated_is_lifecycle_taxonomy_or_schema_contract_not_deprecated_runtime_code',
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function legacyScaffoldBoundaryClassification(string $type, string $path, string $excerpt): ?array
    {
        if (! in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true)) {
            return null;
        }

        if (str_contains($path, '/Kernel/Pipeline/')
            || str_contains($path, 'ScaffoldPromoteCommand.php')
            || str_contains($path, 'AtlasScaffoldStageCommand.php')) {
            return [
                'bucket' => 'parked_scaffold_contract',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_is_explicit_pipeline_or_promotion_boundary_not_delete_signal',
            ];
        }

        if (preg_match('/implemented-vs-scaffold|implementation_state|status|maturity|domain|domains|roadmap|queue|gate|gates|guardrail|fake_runtime|no-op|scaffold-only|no-scaffold|incompleteness marker|evidence kit|placeholder|marker|signed_by|scaffold\/planned|planned until|voice realtime surface congelada/', $excerpt)) {
            return [
                'bucket' => 'scaffold_taxonomy_or_guardrail',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_is_status_taxonomy_or_guardrail_language_not_legacy_cleanup',
            ];
        }

        if (preg_match('/\$scaffold|->generate\(|scaffoldissafe|stagingpath|staging_dir|skillscaffoldgenerator|operator skill proposal|slug|title|description|markdown/', $excerpt)
            || str_contains($path, '/OperatorIntelligence/')) {
            return [
                'bucket' => 'scaffold_staging_payload',
                'subtype' => 'staged_skill_or_proposal_payload',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_is_staged_payload_name_with_sandbox_boundary_not_legacy_runtime',
            ];
        }

        if (preg_match('/(^[\'"]scaffold[\'"],?$|=> [\'"]scaffold[\'"]|return [\'"]scaffold[\'"]|kind[\'"]?\s*=>\s*[\'"]scaffold[\'"]|\bscaffold\b)/', $excerpt)) {
            return [
                'bucket' => 'scaffold_taxonomy_or_guardrail',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_keyword_is_runtime_taxonomy_until_reachability_proves_stale_code',
            ];
        }

        return null;
    }

    private function scaffoldSignalSubtype(string $excerpt): string
    {
        return match (true) {
            str_contains($excerpt, 'signed_by') => 'runtime_provenance_signature',
            str_contains($excerpt, 'placeholder') || str_contains($excerpt, 'evidence kit') => 'evidence_placeholder',
            str_contains($excerpt, 'pipeline.scaffold') || str_contains($excerpt, 'scaffold pipeline') => 'kernel_pipeline_scaffold',
            str_contains($excerpt, 'implemented-vs-scaffold-matrix') => 'architecture_matrix_reference',
            str_contains($excerpt, '_scaffold') || str_contains($excerpt, 'needs ') => 'qualitative_gate_requirement',
            default => 'scaffold_status_or_literal',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function legacyOperationalGroups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $classification = (array) ($item['operational_classification'] ?? []);
            $bucket = (string) ($classification['bucket'] ?? 'unknown');
            $groups[$bucket] ??= [
                'bucket' => $bucket,
                'count' => 0,
                'cleanup_pressure' => (string) ($classification['cleanup_pressure'] ?? 'unknown'),
                'ia_confusion_risk' => (string) ($classification['ia_confusion_risk'] ?? 'unknown'),
                'safe_interpretation' => (string) ($classification['safe_interpretation'] ?? 'unknown'),
                'subtypes' => [],
                'samples' => [],
            ];
            $groups[$bucket]['cleanup_pressure'] = $this->dominantCleanupPressure(
                (string) ($groups[$bucket]['cleanup_pressure'] ?? 'unknown'),
                (string) ($classification['cleanup_pressure'] ?? 'unknown')
            );
            $groups[$bucket]['ia_confusion_risk'] = $this->dominantIaConfusionRisk(
                (string) ($groups[$bucket]['ia_confusion_risk'] ?? 'unknown'),
                (string) ($classification['ia_confusion_risk'] ?? 'unknown')
            );
            $groups[$bucket]['count']++;
            $subtype = (string) ($classification['subtype'] ?? 'unknown');
            $groups[$bucket]['subtypes'][$subtype] ??= [
                'value' => $subtype,
                'count' => 0,
                'samples' => [],
            ];
            $groups[$bucket]['subtypes'][$subtype]['count']++;
            if (count($groups[$bucket]['subtypes'][$subtype]['samples']) < 4) {
                $groups[$bucket]['subtypes'][$subtype]['samples'][] = (string) ($item['path'] ?? '');
            }
            if (count($groups[$bucket]['samples']) < 8) {
                $groups[$bucket]['samples'][] = [
                    'path' => (string) ($item['path'] ?? ''),
                    'signal' => (string) ($item['signal'] ?? 'unknown'),
                    'subtype' => $subtype,
                    'source_type' => (string) ($item['source_type'] ?? 'unknown'),
                    'line' => $item['line'] ?? null,
                    'excerpt' => $item['excerpt'] ?? null,
                ];
            }
        }

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $subtypes = array_values((array) ($group['subtypes'] ?? []));
            usort($subtypes, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));
            $group['subtypes'] = $subtypes;
        }
        unset($group);
        usort($groups, static function (array $a, array $b): int {
            $pressureRank = ['review' => 0, 'low' => 1, 'inventory' => 2, 'documentation_review' => 3, 'none' => 4, 'unknown' => 5];
            $riskRank = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

            return ($pressureRank[$a['cleanup_pressure']] ?? 9) <=> ($pressureRank[$b['cleanup_pressure']] ?? 9)
                ?: ($riskRank[$a['ia_confusion_risk']] ?? 9) <=> ($riskRank[$b['ia_confusion_risk']] ?? 9)
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $groups;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function legacyOperationalSummary(array $items): array
    {
        $review = 0;
        $documentationReview = 0;
        $documentationInventory = 0;
        $keywordInventory = 0;
        $falsePositiveOrTaxonomy = 0;
        $highRisk = 0;
        $taxonomyBuckets = [
            'canonical_schema_evolution_field_lifecycle',
            'canonical_self_construction_scaffold_staging_runtime',
            'deduplication_runtime_language',
            'diagnostic_architecture_matrix_reference',
            'lifecycle_taxonomy_value',
            'parked_scaffold_contract',
            'scaffold_staging_payload',
            'scaffold_taxonomy_or_guardrail',
            'status_taxonomy_value',
        ];

        foreach ($items as $item) {
            $classification = (array) ($item['operational_classification'] ?? []);
            $bucket = (string) ($classification['bucket'] ?? 'unknown');
            if (($classification['cleanup_pressure'] ?? null) === 'review') {
                $review++;
            }
            if (($classification['cleanup_pressure'] ?? null) === 'documentation_review') {
                $documentationReview++;
            }
            if (($classification['cleanup_pressure'] ?? null) === 'inventory' && $bucket === 'commentary_or_documentation_language') {
                $documentationInventory++;
            }
            if (($classification['cleanup_pressure'] ?? null) === 'inventory' && $bucket !== 'commentary_or_documentation_language') {
                $keywordInventory++;
            }
            if (in_array($bucket, $taxonomyBuckets, true)) {
                $falsePositiveOrTaxonomy++;
            }
            if (($classification['ia_confusion_risk'] ?? null) === 'high') {
                $highRisk++;
            }
        }

        return [
            'total' => count($items),
            'cleanup_review_count' => $review,
            'documentation_review_count' => $documentationReview,
            'documentation_inventory_count' => $documentationInventory,
            'keyword_inventory_count' => $keywordInventory,
            'false_positive_or_taxonomy_count' => $falsePositiveOrTaxonomy,
            'high_ia_confusion_risk_count' => $highRisk,
            'claim_policy' => 'summary_prioritizes_cleanup_review_and_preserves_inventory_but_never_authorizes_delete_without_preflight',
        ];
    }

    private function dominantCleanupPressure(string $current, string $candidate): string
    {
        $rank = ['review' => 0, 'low' => 1, 'inventory' => 2, 'documentation_review' => 3, 'none' => 4, 'unknown' => 5];

        return ($rank[$candidate] ?? 9) < ($rank[$current] ?? 9) ? $candidate : $current;
    }

    private function dominantIaConfusionRisk(string $current, string $candidate): string
    {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

        return ($rank[$candidate] ?? 9) < ($rank[$current] ?? 9) ? $candidate : $current;
    }

    private function legacySignalSeverity(string $type, string $area): string
    {
        return match ($type) {
            'deprecated' => 'high',
            'duplicate', 'executed_scaffold', 'planned_scaffold' => 'medium',
            'scaffold' => str_starts_with($area, 'app/Services/Ai') ? 'medium' : 'review',
            'legacy', 'todo', 'fixme' => 'review',
            default => 'low',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function legacySignalBoundaryContract(string $type, string $path, string $area): array
    {
        return [
            'classification' => 'legacy_scaffold_keyword_triage',
            'signal' => $type,
            'area' => $area,
            'target' => $path,
            'owner_docs' => $this->legacySignalOwnerDocs($path, $area),
            'evidence_required_before_cleanup' => [
                'reachability',
                'deletion_preflight',
                'owner_doc_decision',
                'focused_tests',
            ],
            'allowed_direction' => 'classify_reachability_then_choose_keep_boundary_rename_quarantine_or_delete_with_owner_decision',
            'forbidden' => 'do_not_delete_rename_or_reimplement_from_keyword_signal_alone',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function legacySignalOwnerDocs(string $path, string $area): array
    {
        if (str_starts_with($path, 'app/Services/Ai/Programming') || str_starts_with($path, 'app/Services/AtlasCode')) {
            return [
                'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }
        if (str_starts_with($path, 'app/Services/Ai/SelfConstruction')) {
            return [
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-01.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }
        if (str_contains($path, 'Voice') || str_contains($path, '/Vox/')) {
            return [
                'docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md',
                'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }
        if ($area === 'app/Services/Engineering') {
            return [
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }

        return ['docs/engineering-knowledge-base/atlas-duplication-reality-governance.md'];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacySignalCleanupRecommendation(string $type): array
    {
        return [
            'delete_allowed' => false,
            'rename_allowed_without_owner_decision' => false,
            'safe_next_action' => match ($type) {
                'deprecated' => 'prove_replacement_and_callers_then_plan_deprecation_or_quarantine',
                'duplicate' => 'compare_owner_contracts_and_reachability_before_merge_or_rename',
                'scaffold', 'planned_scaffold', 'executed_scaffold' => 'split_scaffold_language_from_active_runtime_or_mark_partial_with_owner_doc',
                'todo', 'fixme' => 'convert_comment_to_tracked_owner_decision_or_test_gap',
                default => 'run_reachability_and_owner_review_before_cleanup',
            },
        ];
    }

    /**
     * @return array{routes:array<int,array<string,string>>,named_routes:array<int,array<string,string>>,route_actions:array<int,array<string,string>>}
     */
    private function routeInventoryForContent(string $content, string $path): array
    {
        $routes = [];
        $namedRoutes = [];
        $routeActions = [];
        if (preg_match_all('/Route::(get|post|put|patch|delete|options|match|any)\s*\((.*?);/is', $content, $matches, PREG_SET_ORDER) === false) {
            return ['routes' => [], 'named_routes' => [], 'route_actions' => []];
        }

        foreach ($matches as $match) {
            $method = strtolower((string) $match[1]);
            $statement = (string) $match[0];
            $firstArgument = '';
            if (preg_match('/Route::'.$method.'\s*\(\s*(?:\[[^\]]+\]|[\'"]([^\'"]+)[\'"])/is', $statement, $pathMatch)) {
                $firstArgument = $this->normalizeRoutePath((string) ($pathMatch[1] ?? ''));
            }
            if ($firstArgument === '') {
                continue;
            }

            $methodUri = $method.':'.$firstArgument;
            $routes[] = [
                'method_uri' => $methodUri,
                'method' => $method,
                'uri' => $firstArgument,
                'path' => $path,
            ];

            if (preg_match('/->name\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $statement, $nameMatch)) {
                $namedRoutes[] = [
                    'name' => (string) $nameMatch[1],
                    'method_uri' => $methodUri,
                    'path' => $path,
                ];
            }

            if (preg_match('/\[\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/', $statement, $actionMatch)) {
                $routeActions[] = [
                    'action' => class_basename((string) $actionMatch[1]).'@'.((string) $actionMatch[2]),
                    'method_uri' => $methodUri,
                    'path' => $path,
                ];
            }
        }

        return [
            'routes' => $routes,
            'named_routes' => $namedRoutes,
            'route_actions' => $routeActions,
        ];
    }

    /**
     * @return array{routes:array<int,array<string,string>>,named_routes:array<int,array<string,string>>,route_actions:array<int,array<string,string>>}
     */
    private function runtimeRouteInventory(): array
    {
        $routes = [];
        $namedRoutes = [];
        $routeActions = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $this->normalizeRoutePath($route->uri());
            $methods = array_values(array_filter(
                $route->methods(),
                static fn (string $method): bool => strtoupper($method) !== 'HEAD'
            ));
            $routeMethodUri = strtolower(implode('|', $methods)).':'.$uri;

            foreach ($methods as $method) {
                $methodUri = strtolower($method).':'.$uri;
                $routes[] = [
                    'method_uri' => $methodUri,
                    'method' => strtolower($method),
                    'uri' => $uri,
                    'path' => 'runtime:route-list',
                ];
            }

            $name = $route->getName();
            if ($this->isMeaningfulRuntimeRouteName($name)) {
                $namedRoutes[] = [
                    'name' => (string) $name,
                    'method_uri' => $routeMethodUri,
                    'path' => 'runtime:route-list',
                ];
            }

            $action = $route->getActionName();
            if ($action !== 'Closure' && $action !== '') {
                $routeActions[] = [
                    'action' => strtolower(str_replace('\\', '.', $action)),
                    'method_uri' => $routeMethodUri,
                    'path' => 'runtime:route-list',
                ];
            }
        }

        return [
            'routes' => $routes,
            'named_routes' => $namedRoutes,
            'route_actions' => $routeActions,
        ];
    }

    private function isMeaningfulRuntimeRouteName(?string $name): bool
    {
        if (! is_string($name)) {
            return false;
        }

        $name = trim($name);

        return $name !== '' && ! str_ends_with($name, '.');
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function duplicationTriageQueue(array $classDuplicateGroups, array $runtimeRouteActionAliasGroups): array
    {
        $items = [];
        foreach ($classDuplicateGroups as $group) {
            $value = (string) ($group['value'] ?? '');
            $paths = (array) ($group['paths'] ?? []);
            $severity = match ($value) {
                'operationenvelope' => 'critical',
                'verificationcommandrunner', 'frontmatterparser' => 'high',
                'aiexecutionplan' => 'medium',
                'smokesubject' => 'low',
                default => 'review',
            };
            $items[] = [
                'id' => 'duplicate_class:'.$value,
                'kind' => 'duplicate_class_name',
                'severity' => $severity,
                'status' => 'owner_review_required',
                'paths' => $paths,
                'current_evidence' => $this->duplicateClassEvidenceHint($value),
                'boundary_contract' => $this->duplicateClassBoundaryContract($value),
                'cleanup_recommendation' => $this->duplicateClassCleanupRecommendation($value),
                'why_it_can_confuse_ai' => 'same_short_class_name_can_make_agents_pick_wrong_namespace_or_runtime_boundary',
                'required_decision' => 'reuse_existing|namespace_boundary_intentional|supersede|merge|quarantine',
                'next_commands' => array_values(array_map(
                    static fn (string $path): string => 'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                    $paths
                )),
            ];
        }

        foreach ($runtimeRouteActionAliasGroups as $group) {
            $action = (string) ($group['value'] ?? '');
            $methodUris = $this->duplicateGroupMethodUris($group);
            $mobileAlias = $this->mobileRouteActionAlias($methodUris);
            $rootApiAlias = $this->rootApiCompatibilityAlias($methodUris);
            $items[] = [
                'id' => 'route_action_alias:'.$action,
                'kind' => 'runtime_route_action_alias',
                'severity' => ($mobileAlias || $rootApiAlias) ? 'low' : 'medium',
                'status' => $rootApiAlias ? 'documented_transport_compatibility_alias' : ($mobileAlias ? 'document_intentional_alias_or_wrapper_boundary' : 'owner_review_required'),
                'action' => $action,
                'method_uris' => $methodUris,
                'boundary_contract' => $this->routeActionAliasBoundaryContract($action, $methodUris, $mobileAlias, $rootApiAlias),
                'why_it_can_confuse_ai' => $rootApiAlias ? 'same_controller_action_is_intentionally_exposed_at_root_and_api_prefix' : 'same_controller_action_is_exposed_through_multiple_urls',
                'required_decision' => $rootApiAlias ? 'keep_as_root_api_transport_compatibility_alias|document_wrapper_contract' : ($mobileAlias ? 'document_as_mobile_alias|split_mobile_wrapper|deprecate_one_path' : 'document_alias_or_merge_paths'),
                'next_commands' => ['php artisan route:list --json'],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'review' => 3, 'low' => 4];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function runtimeRouteAliasCleanupQueue(array $runtimeRouteActionAliasGroups, bool $documentedBoundary = false): array
    {
        $items = [];

        foreach ($runtimeRouteActionAliasGroups as $group) {
            $action = (string) ($group['value'] ?? '');
            $methodUris = $this->duplicateGroupMethodUris($group);
            $mobileAlias = $this->mobileRouteActionAlias($methodUris);
            $rootApiAlias = $this->rootApiCompatibilityAlias($methodUris);
            $boundaryContract = $this->routeActionAliasBoundaryContract($action, $methodUris, $mobileAlias, $rootApiAlias);

            $items[] = [
                'id' => 'runtime_route_alias:'.$action,
                'kind' => $documentedBoundary ? 'runtime_route_alias_boundary' : 'runtime_route_alias_cleanup',
                'severity' => ($mobileAlias || $rootApiAlias) ? 'low' : 'medium',
                'status' => $documentedBoundary
                    ? $this->documentedRuntimeRouteAliasStatus($boundaryContract)
                    : ($rootApiAlias ? 'documented_transport_compatibility_alias' : ($mobileAlias ? 'intentional_alias_boundary_required' : 'owner_review_required')),
                'alias_family' => (string) ($boundaryContract['alias_family'] ?? 'unclassified_same_action_alias'),
                'canonical_owner' => (string) ($boundaryContract['canonical_owner'] ?? 'route_owner_review_required'),
                'action' => $action,
                'method_uris' => $methodUris,
                'same_action_not_duplicate_method_uri' => true,
                'runtime_duplicate_route_group_count' => 0,
                'route_list_proof_required' => true,
                'boundary_contract' => $boundaryContract,
                'cleanup_policy' => [
                    'delete_allowed' => false,
                    'merge_allowed_without_owner_decision' => false,
                    'new_route_allowed_without_owner_decision' => false,
                    'classification' => $documentedBoundary
                        ? 'documented_alias_boundary_no_cleanup'
                        : ($rootApiAlias ? 'documented_root_api_transport_alias' : ($mobileAlias ? 'transport_alias_or_wrapper_candidate' : 'compatibility_or_merge_review_candidate')),
                ],
                'required_decision' => $documentedBoundary
                    ? 'keep_documented_alias_or_open_owner_deprecation_plan'
                    : ($rootApiAlias ? 'keep_as_root_api_transport_compatibility_alias|document_wrapper_contract' : ($mobileAlias ? 'document_as_mobile_alias|split_mobile_wrapper|deprecate_one_path' : 'document_alias_or_merge_paths')),
                'next_commands' => [
                    'php artisan route:list --json',
                    'php artisan atlas:code-reality global-duplication-audit --json',
                ],
                'claim_policy' => $documentedBoundary
                    ? 'documented_same_action_alias_is_boundary_inventory_not_cleanup_permission'
                    : 'same_controller_action_on_multiple_routes_is_alias_pressure_not_method_uri_duplication',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'review' => 3, 'low' => 4];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                ?: strcmp((string) ($a['alias_family'] ?? ''), (string) ($b['alias_family'] ?? ''));
        });

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $routeDuplicateGroups
     * @param  array<int,array<string,mixed>>  $runtimeRouteDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function confirmedStaticRouteDuplicateGroups(array $routeDuplicateGroups, array $runtimeRouteDuplicateGroups): array
    {
        $runtimeDuplicateValues = array_fill_keys(array_map(
            static fn (array $group): string => (string) ($group['value'] ?? ''),
            $runtimeRouteDuplicateGroups
        ), true);

        return array_values(array_filter(
            $routeDuplicateGroups,
            static fn (array $group): bool => isset($runtimeDuplicateValues[(string) ($group['value'] ?? '')])
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $routeDuplicateGroups
     * @param  array<int,array<string,mixed>>  $confirmedRouteDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function prefixBlindStaticRouteDuplicateGroups(array $routeDuplicateGroups, array $confirmedRouteDuplicateGroups): array
    {
        $confirmedValues = array_fill_keys(array_map(
            static fn (array $group): string => (string) ($group['value'] ?? ''),
            $confirmedRouteDuplicateGroups
        ), true);

        return array_values(array_filter(
            $routeDuplicateGroups,
            static fn (array $group): bool => ! isset($confirmedValues[(string) ($group['value'] ?? '')])
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $routeActionAliasGroups
     * @param  array<int,array<string,mixed>>  $confirmedRouteDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function annotatedStaticRouteActionAliasGroups(array $routeActionAliasGroups, array $confirmedRouteDuplicateGroups): array
    {
        return array_values(array_map(function (array $group) use ($confirmedRouteDuplicateGroups): array {
            $action = (string) ($group['value'] ?? '');
            $methodUris = $this->duplicateGroupMethodUris($group);

            return [
                ...$group,
                'method_uris' => $methodUris,
                'static_alias_classification' => $this->staticRouteActionAliasClassification(
                    $action,
                    $methodUris,
                    $confirmedRouteDuplicateGroups
                ),
            ];
        }, $routeActionAliasGroups));
    }

    /**
     * @param  array<int,array<string,mixed>>  $staticRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function documentedStaticRouteActionAliasGroups(array $staticRouteActionAliasGroups): array
    {
        return array_values(array_filter(
            $staticRouteActionAliasGroups,
            static fn (array $group): bool => (string) data_get($group, 'static_alias_classification.cleanup_pressure') !== 'owner_review'
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $staticRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function reviewStaticRouteActionAliasGroups(array $staticRouteActionAliasGroups): array
    {
        return array_values(array_filter(
            $staticRouteActionAliasGroups,
            static fn (array $group): bool => (string) data_get($group, 'static_alias_classification.cleanup_pressure') === 'owner_review'
        ));
    }

    /**
     * @param  array<int,string>  $methodUris
     * @param  array<int,array<string,mixed>>  $confirmedRouteDuplicateGroups
     * @return array<string,mixed>
     */
    private function staticRouteActionAliasClassification(string $action, array $methodUris, array $confirmedRouteDuplicateGroups): array
    {
        $confirmedValues = array_fill_keys(array_map(
            static fn (array $group): string => (string) ($group['value'] ?? ''),
            $confirmedRouteDuplicateGroups
        ), true);

        if (count($methodUris) === 1 && ! isset($confirmedValues[$methodUris[0] ?? ''])) {
            return [
                'bucket' => 'prefix_blind_static_route_action_alias',
                'cleanup_pressure' => 'none',
                'safe_interpretation' => 'static_scan_ignores_route_group_prefixes_and_runtime_route_list_has_no_duplicate',
                'runtime_proof' => 'php artisan route:list --json',
                'claim_policy' => 'do_not_promote_prefix_blind_static_route_action_alias_to_owner_review_without_runtime_duplicate',
            ];
        }

        if ($action === 'atlascodeobservedsessioncontroller@import') {
            return [
                'bucket' => 'backward_compatibility_endpoint',
                'cleanup_pressure' => 'runtime_queue_only',
                'safe_interpretation' => 'import_result_is_known_backward_compatibility_alias_for_observed_session_import',
                'runtime_proof' => 'runtime_route_alias_cleanup_queue',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'claim_policy' => 'static_alias_is_documented_here_but_owner_decision_lives_in_runtime_alias_cleanup_queue',
            ];
        }

        if (str_contains($action, 'aitelemetrycontroller@') || str_contains($action, 'atlasconstelacaocontroller@')) {
            return [
                'bucket' => str_contains($action, 'aitelemetrycontroller@') ? 'telemetry_mobile_base_alias' : 'constelacao_mobile_base_alias',
                'cleanup_pressure' => 'runtime_queue_only',
                'safe_interpretation' => 'static_alias_is_mobile_base_transport_alias_already_classified_by_runtime_route_list',
                'runtime_proof' => 'runtime_route_alias_cleanup_queue',
                'owner_docs' => str_contains($action, 'aitelemetrycontroller@') ? [
                    'docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ] : [
                    'docs/engineering-knowledge-base/atlas-constelacao-surface.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'claim_policy' => 'static_alias_is_not_a_second_review_queue_when_runtime_route_alias_cleanup_queue_has_the_contract',
            ];
        }

        return [
            'bucket' => 'unclassified_static_same_action_alias',
            'cleanup_pressure' => 'owner_review',
            'safe_interpretation' => 'same_controller_action_on_multiple_static_routes_requires_owner_boundary_review',
            'runtime_proof' => 'php artisan route:list --json',
            'claim_policy' => 'static_alias_requires_review_when_not_prefix_blind_or_known_runtime_contract',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function runtimeRootApiCompatibilityAliasGroups(array $runtimeRouteActionAliasGroups): array
    {
        return array_values(array_filter(
            $runtimeRouteActionAliasGroups,
            fn (array $group): bool => $this->rootApiCompatibilityAlias($this->duplicateGroupMethodUris($group))
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function runtimeDocumentedRouteActionAliasGroups(array $runtimeRouteActionAliasGroups): array
    {
        return array_values(array_filter(
            $runtimeRouteActionAliasGroups,
            fn (array $group): bool => $this->documentedRuntimeRouteActionAlias(
                (string) ($group['value'] ?? ''),
                $this->duplicateGroupMethodUris($group)
            )
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    private function runtimeReviewRouteActionAliasGroups(array $runtimeRouteActionAliasGroups): array
    {
        return array_values(array_filter(
            $runtimeRouteActionAliasGroups,
            fn (array $group): bool => ! $this->rootApiCompatibilityAlias($this->duplicateGroupMethodUris($group))
                && ! $this->documentedRuntimeRouteActionAlias(
                    (string) ($group['value'] ?? ''),
                    $this->duplicateGroupMethodUris($group)
                )
        ));
    }

    /**
     * @param  array<int,string>  $methodUris
     */
    private function documentedRuntimeRouteActionAlias(string $action, array $methodUris): bool
    {
        if ($this->rootApiCompatibilityAlias($methodUris)) {
            return false;
        }

        return $this->mobileRouteActionAlias($methodUris)
            || $action === 'app.http.controllers.atlascodeobservedsessioncontroller@import';
    }

    /**
     * @param  array<string,mixed>  $boundaryContract
     */
    private function documentedRuntimeRouteAliasStatus(array $boundaryContract): string
    {
        return match ((string) ($boundaryContract['alias_family'] ?? '')) {
            'backward_compatibility_endpoint' => 'documented_backward_compatibility_alias',
            'voice_realtime_mobile_base_alias', 'telemetry_mobile_base_alias', 'constelacao_mobile_base_alias', 'mobile_base_alias' => 'documented_mobile_transport_alias',
            default => 'documented_alias_boundary',
        };
    }

    /**
     * @param  array<string,mixed>  $group
     * @return array<int,string>
     */
    private function duplicateGroupMethodUris(array $group): array
    {
        $methodUris = (array) ($group['method_uris'] ?? []);
        if ($methodUris !== []) {
            return $this->uniqueStrings($methodUris, filterEmpty: true);
        }

        return $this->itemStringColumn((array) ($group['sample_items'] ?? []), 'method_uri', filterEmpty: true);
    }

    /**
     * @param  array<int,string>  $methodUris
     */
    private function mobileRouteActionAlias(array $methodUris): bool
    {
        return count($methodUris) > 1 && collect($methodUris)->contains(
            static fn (string $uri): bool => str_contains($uri, '/v1/mobile/')
        );
    }

    /**
     * @param  array<int,string>  $methodUris
     */
    private function rootApiCompatibilityAlias(array $methodUris): bool
    {
        if (count($methodUris) < 2) {
            return false;
        }

        $normalized = [];
        $hasRootRoute = false;
        $hasApiRoute = false;

        foreach ($methodUris as $methodUri) {
            [$method, $uri] = array_pad(explode(':', $methodUri, 2), 2, '');
            if ($method === '' || $uri === '') {
                return false;
            }

            $path = $this->normalizeRoutePath($uri);
            if ($path === '/api' || str_starts_with($path, '/api/')) {
                $hasApiRoute = true;
                $path = $this->normalizeRoutePath(substr($path, 4));
            } else {
                $hasRootRoute = true;
            }

            $normalized[] = strtolower($method).':'.$path;
        }

        return $hasRootRoute
            && $hasApiRoute
            && count(array_unique($normalized)) === 1;
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function generatedFixtureClassDuplicateGroups(array $classDuplicateGroups): array
    {
        return array_values(array_filter(
            $classDuplicateGroups,
            fn (array $group): bool => $this->duplicateClassGeneratedFixtureEvidence(
                (string) ($group['value'] ?? ''),
                array_values(array_map('strval', (array) ($group['paths'] ?? [])))
            ) !== null
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $generatedFixtureClassDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function generatedFixtureClassBoundaryQueue(array $generatedFixtureClassDuplicateGroups): array
    {
        return array_values(array_map(function (array $group): array {
            $value = (string) ($group['value'] ?? '');
            $paths = array_values(array_map('strval', (array) ($group['paths'] ?? [])));

            return [
                'id' => 'generated_fixture_class_boundary:'.$value,
                'kind' => 'generated_fixture_duplicate_class_boundary',
                'severity' => 'low',
                'status' => 'documented_generated_fixture_boundary',
                'short_name' => $value,
                'paths' => $paths,
                'current_evidence' => $this->duplicateClassEvidenceHint($value),
                'boundary_contract' => $this->duplicateClassBoundaryContract($value),
                'cleanup_recommendation' => $this->duplicateClassCleanupRecommendation($value),
                'required_decision' => 'keep_as_generated_fixture_boundary|change_generator_contract_before_any_rename',
                'next_commands' => array_values(array_map(
                    static fn (string $path): string => 'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                    $paths
                )),
                'claim_policy' => 'generated_fixture_duplicate_class_is_boundary_inventory_not_production_duplicate_triage',
            ];
        }, $generatedFixtureClassDuplicateGroups));
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function blockingClassDuplicateGroups(array $classDuplicateGroups): array
    {
        return array_values(array_filter(
            $classDuplicateGroups,
            fn (array $group): bool => $this->duplicateClassGeneratedFixtureEvidence(
                (string) ($group['value'] ?? ''),
                array_values(array_map('strval', (array) ($group['paths'] ?? [])))
            ) === null
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $classDuplicateGroups
     * @return array<int,array<string,mixed>>
     */
    private function duplicateClassCleanupQueue(array $classDuplicateGroups): array
    {
        $items = [];
        foreach ($classDuplicateGroups as $group) {
            $value = (string) ($group['value'] ?? '');
            $paths = array_values(array_map('strval', (array) ($group['paths'] ?? [])));
            if ($this->duplicateClassGeneratedFixtureEvidence($value, $paths) !== null) {
                continue;
            }
            $recommendation = $this->duplicateClassCleanupRecommendation($value);
            $items[] = [
                'id' => 'duplicate_class_cleanup:'.$value,
                'kind' => 'duplicate_class_cleanup_decision',
                'short_name' => $value,
                'priority' => (int) ($recommendation['priority'] ?? 99),
                'severity' => $this->duplicateClassSeverity($value),
                'paths' => $paths,
                'exact_references' => $this->duplicateClassExactReferences($paths),
                'generated_fixture_evidence' => $this->duplicateClassGeneratedFixtureEvidence($value, $paths),
                'cleanup_type' => $this->duplicateClassCleanupType($value),
                'cleanup_recommendation' => $recommendation,
                'boundary_contract' => $this->duplicateClassBoundaryContract($value),
                'required_before_change' => [
                    'reachability_for_every_path',
                    'deletion_preflight_for_every_path',
                    'owner_doc_decision',
                    'focused_tests',
                    'separate_cleanup_change',
                ],
                'proof_commands' => array_values(array_merge(...array_map(
                    static fn (string $path): array => [
                        'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                        'php artisan atlas:code-reality deletion-preflight --target="'.$path.'" --json',
                    ],
                    $paths
                ))),
                'focused_tests' => $this->duplicateClassFocusedTests($value),
                'claim_policy' => 'cleanup_queue_is_not_delete_permission',
            ];
        }

        usort($items, static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 99)) <=> ((int) ($b['priority'] ?? 99)));

        return $items;
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<string,mixed>|null
     */
    private function duplicateClassGeneratedFixtureEvidence(string $value, array $paths): ?array
    {
        if ($value !== 'smokesubject') {
            return null;
        }

        return [
            'classification' => 'generated_fixture_inside_smoke_workspace',
            'repo_paths_are_generators_not_production_class_files' => true,
            'generator_paths' => $paths,
            'generated_files' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
            'generated_namespace' => 'Smoke',
            'production_boundary' => [
                'persistence' => false,
                'route_surface' => false,
                'domain_model' => false,
            ],
            'forbidden' => 'do_not_rename_or_delete_generator_commands_as_if_smokesubject_were_a_repo_domain_class',
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<int,array<string,mixed>>
     */
    private function duplicateClassExactReferences(array $paths): array
    {
        return array_values(array_map(function (string $path): array {
            $fqcn = $this->phpClassFqcn($path);
            $class = $fqcn === null ? null : class_basename($fqcn);
            $namespace = $fqcn === null || $class === null ? null : substr($fqcn, 0, -strlen('\\'.$class));
            $matches = $fqcn === null ? [] : $this->exactTextReferences($fqcn);
            $namespaceLocalMatches = ($namespace === null || $class === null) ? [] : $this->namespaceLocalReferences($namespace, $class);

            return [
                'path' => $path,
                'fqcn' => $fqcn,
                'reference_count' => count($matches),
                'reference_samples' => array_slice($matches, 0, 12),
                'namespace_local_reference_count' => count($namespaceLocalMatches),
                'namespace_local_reference_samples' => array_slice($namespaceLocalMatches, 0, 12),
                'claim_policy' => 'exact_fqcn_refs_are_stronger_than_short_name_reachability',
            ];
        }, $paths));
    }

    private function phpClassFqcn(string $path): ?string
    {
        $content = $this->readSmallFile(base_path($path));
        if ($content === null) {
            return null;
        }
        if (! preg_match('/^namespace\s+([^;]+);/m', $content, $namespaceMatch)) {
            return null;
        }
        if (! preg_match('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classMatch)) {
            return null;
        }

        return trim((string) $namespaceMatch[1]).'\\'.trim((string) $classMatch[1]);
    }

    /**
     * @return array<int,string>
     */
    private function exactTextReferences(string $needle): array
    {
        $matches = [];
        foreach ($this->allFiles(self::SEARCH_ROOTS) as $file) {
            $path = $this->relativePath($file->getPathname());
            if (! $this->isTextFile($file->getPathname())) {
                continue;
            }
            $content = $this->readSmallFile($file->getPathname());
            if ($content !== null && str_contains($content, $needle)) {
                $matches[] = $path;
            }
        }

        sort($matches);

        return $matches;
    }

    /**
     * @return array<int,string>
     */
    private function namespaceLocalReferences(string $namespace, string $class): array
    {
        $matches = [];
        foreach ($this->allFiles(self::SEARCH_ROOTS) as $file) {
            $path = $this->relativePath($file->getPathname());
            if (! $this->isTextFile($file->getPathname())) {
                continue;
            }
            $content = $this->readSmallFile($file->getPathname());
            if ($content === null) {
                continue;
            }
            if (! preg_match('/^namespace\s+'.preg_quote($namespace, '/').'\s*;/m', $content)) {
                continue;
            }
            if (preg_match('/\b'.preg_quote($class, '/').'\b/', $content)) {
                $matches[] = $path;
            }
        }

        sort($matches);

        return $matches;
    }

    private function duplicateClassSeverity(string $value): string
    {
        return match ($value) {
            'operationenvelope' => 'critical',
            'verificationcommandrunner', 'frontmatterparser' => 'high',
            'aiexecutionplan' => 'medium',
            'smokesubject' => 'low',
            default => 'review',
        };
    }

    private function duplicateClassCleanupType(string $value): string
    {
        return match ($value) {
            'operationenvelope' => 'boundary_doc_then_possible_programming_variant_rename',
            'frontmatterparser' => 'explicit_parser_names_or_adapter',
            'verificationcommandrunner' => 'rename_atlas_dev_gate_interface_or_document_boundary',
            'aiexecutionplan' => 'rename_prompt_value_object_not_persistent_model',
            'smokesubject' => 'exclude_generated_fixture_from_production_duplicate_pressure',
            default => 'owner_review_before_cleanup',
        };
    }

    /**
     * @return array<int,string>
     */
    private function duplicateClassFocusedTests(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'php artisan test --filter=OperationEnvelope',
                'php artisan test tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php',
            ],
            'frontmatterparser' => [
                'php artisan test --filter=FrontmatterParser',
                'php artisan test --filter=EngineeringDocumentationHealthServiceTest',
            ],
            'verificationcommandrunner' => [
                'php artisan test --filter=VerificationCommandRunner',
                'php artisan test --filter=VerificationGate',
            ],
            'aiexecutionplan' => [
                'php artisan test --filter=AiExecutionPlan',
                'php artisan test --filter=AiPromptBuilder',
            ],
            'smokesubject' => [
                'php artisan test --filter=AtlasDevSeniorLoop',
                'php artisan test --filter=AtlasDevDesktopRealSmoke',
            ],
            default => ['php artisan test --filter=<owner-focused-test>'],
        };
    }

    /**
     * @param  array<int,string>  $methodUris
     * @return array<string,mixed>
     */
    private function routeActionAliasBoundaryContract(string $action, array $methodUris, bool $mobileAlias, bool $rootApiAlias = false): array
    {
        if ($action === 'app.http.controllers.atlascodeobservedsessioncontroller@import') {
            return [
                'canonical_owner' => 'atlas_code_observed_session_import',
                'alias_family' => 'backward_compatibility_endpoint',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'primary_controller' => 'app/Http/Controllers/AtlasCodeObservedSessionController.php',
                'primary_route' => 'post:/atlas-code/works/{project}/observed-sessions/{session}/import',
                'alias_routes' => array_values(array_diff($methodUris, [
                    'post:/atlas-code/works/{project}/observed-sessions/{session}/import',
                ])),
                'allowed_direction' => 'keep_import_result_only_as_documented_backward_compatibility_or_replace_it_with_explicit_redirect/deprecation_plan',
                'parity_rule' => 'alias_must_call_same_controller_action_and_return_same_import_contract',
                'cleanup_sequence' => [
                    'keep_import_as_primary_observed_session_result_ingest_route',
                    'keep_import_result_only_as_same_action_backward_compatibility_alias_until_owner_deprecation_decision',
                    'prove_both_routes_call_same_controller_action_and_response_contract_before_any_change',
                    'deprecate_alias_only_with_owner_doc_release_note_and_client_migration_window',
                    'never_add_third_import_ingest_route_without_owner_decision',
                ],
                'alias_boundary' => [
                    'primary_route_intent' => 'operator_report_and_diff_import',
                    'alias_route_intent' => 'backward_compatibility_for_import_result_clients',
                    'logic_owner' => 'single_controller_action_no_branching_business_logic_by_route_path',
                ],
                'proof_commands' => [
                    'php artisan route:list --json',
                    'php artisan test tests/Feature/AtlasCodeInteractiveObservedProviderTest.php',
                ],
                'forbidden' => 'do_not_add_third_import_endpoint_or_choose_alias_without_owner_decision',
            ];
        }

        if ($rootApiAlias) {
            $rootRoutes = array_values(array_filter(
                $methodUris,
                static fn (string $uri): bool => ! str_contains($uri, ':/api/')
            ));
            $apiRoutes = array_values(array_filter(
                $methodUris,
                static fn (string $uri): bool => str_contains($uri, ':/api/')
            ));

            return [
                'canonical_owner' => 'routes_api_php_transport_contract',
                'alias_family' => 'root_api_compatibility_alias',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'primary_controller' => $this->routeActionControllerPath($action),
                'primary_routes' => $rootRoutes,
                'alias_routes' => $apiRoutes,
                'allowed_direction' => 'same_routes_api_php_declaration_may_be_exposed_at_root_and_api_prefix_only_as_transport_compatibility',
                'parity_rule' => 'root_and_api_prefixed_routes_must_call_same_controller_action_same_auth_same_payload_same_response_contract',
                'cleanup_sequence' => [
                    'keep_controller_action_as_single_logic_owner',
                    'treat_api_prefixed_route_as_transport_compatibility_wrapper_not_duplicate_implementation',
                    'prove_root_and_api_prefixed_paths_have_route_list_parity_before_any_contract_change',
                    'deprecate_one_transport_path_only_with_owner_doc_client_migration_and_release_note',
                    'never_add_third_transport_path_for_same_action_without_owner_decision',
                ],
                'alias_boundary' => [
                    'root_route_intent' => 'root_transport_surface_for_routes_api_php_declaration',
                    'api_route_intent' => 'api_prefix_transport_compatibility_surface',
                    'logic_owner' => 'single_controller_action_no_branching_business_logic_by_route_path',
                ],
                'proof_commands' => [
                    'php artisan route:list --json',
                    'php artisan atlas:code-reality global-duplication-audit --summary --json',
                ],
                'forbidden' => 'do_not_treat_root_api_wrapper_pair_as_duplicate_implementation_or_create_third_route',
            ];
        }

        if ($mobileAlias) {
            $baseRoutes = array_values(array_filter(
                $methodUris,
                static fn (string $uri): bool => ! str_contains($uri, '/v1/mobile/')
            ));
            $mobileRoutes = array_values(array_filter(
                $methodUris,
                static fn (string $uri): bool => str_contains($uri, '/v1/mobile/')
            ));

            return [
                'canonical_owner' => $this->mobileAliasCanonicalOwner($action),
                'alias_family' => $this->mobileAliasFamily($action),
                'owner_docs' => $this->mobileAliasOwnerDocs($action),
                'primary_controller' => $this->routeActionControllerPath($action),
                'primary_routes' => $baseRoutes,
                'alias_routes' => $mobileRoutes,
                'allowed_direction' => 'mobile_route_may_alias_base_api_when_payload_auth_and_response_contract_are_identical',
                'parity_rule' => 'mobile_alias_must_remain_same_controller_action_same_auth_same_payload_same_response_contract',
                'cleanup_sequence' => [
                    'keep_base_api_route_as_business_logic_owner',
                    'keep_mobile_route_as_transport_alias_or_thin_wrapper_only',
                    'prove_same_controller_action_auth_payload_and_response_before_new_mobile_path',
                    'split_mobile_wrapper_only_with_owner_doc_and_contract_tests',
                    'never_add_mobile_specific_business_logic_inside_alias_action_without_owner_decision',
                ],
                'alias_boundary' => [
                    'base_route_intent' => 'canonical_api_contract',
                    'mobile_route_intent' => 'mobile_transport_alias',
                    'logic_owner' => 'base_controller_action_or_explicit_documented_wrapper',
                ],
                'proof_commands' => [
                    'php artisan route:list --json',
                    'php artisan atlas:code-reality global-duplication-audit --json',
                ],
                'forbidden' => 'do_not_implement_separate_mobile_business_logic_inside_same_action_without_wrapper_or_owner_doc',
            ];
        }

        return [
            'canonical_owner' => 'route_owner_review_required',
            'alias_family' => 'unclassified_same_action_alias',
            'owner_docs' => ['docs/engineering-knowledge-base/atlas-duplication-reality-governance.md'],
            'primary_controller' => $this->routeActionControllerPath($action),
            'primary_routes' => $methodUris,
            'alias_routes' => [],
            'allowed_direction' => 'owner_must_document_whether_paths_are_backward_compatibility_aliases_or_should_be_merged',
            'parity_rule' => 'same_action_alias_requires_owner_decision_before_new_routes_or_logic',
            'proof_commands' => ['php artisan route:list --json'],
            'forbidden' => 'do_not_create_new_route_for_same_action_before_alias_review',
        ];
    }

    private function mobileAliasCanonicalOwner(string $action): string
    {
        return match (true) {
            str_contains($action, 'atlasaivoicerealtimecontroller@') => 'voice_realtime_base_api_action',
            str_contains($action, 'aitelemetrycontroller@') => 'telemetry_base_api_action',
            str_contains($action, 'atlasconstelacaocontroller@') => 'constelacao_base_api_action',
            default => 'base_api_controller_action',
        };
    }

    private function mobileAliasFamily(string $action): string
    {
        return match (true) {
            str_contains($action, 'atlasaivoicerealtimecontroller@') => 'voice_realtime_mobile_base_alias',
            str_contains($action, 'aitelemetrycontroller@') => 'telemetry_mobile_base_alias',
            str_contains($action, 'atlasconstelacaocontroller@') => 'constelacao_mobile_base_alias',
            default => 'mobile_base_alias',
        };
    }

    /**
     * @return array<int,string>
     */
    private function mobileAliasOwnerDocs(string $action): array
    {
        return match (true) {
            str_contains($action, 'atlasaivoicerealtimecontroller@') => [
                'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ],
            str_contains($action, 'aitelemetrycontroller@') => [
                'docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ],
            str_contains($action, 'atlasconstelacaocontroller@') => [
                'docs/engineering-knowledge-base/atlas-constelacao-surface.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ],
            default => ['docs/engineering-knowledge-base/atlas-duplication-reality-governance.md'],
        };
    }

    private function routeActionControllerPath(string $action): string
    {
        return match (true) {
            str_contains($action, 'atlasaivoicerealtimecontroller@') => 'app/Http/Controllers/AtlasAiVoiceRealtimeController.php',
            str_contains($action, 'aitelemetrycontroller@') => 'app/Http/Controllers/AiTelemetryController.php',
            str_contains($action, 'atlasconstelacaocontroller@') => 'app/Http/Controllers/AtlasConstelacaoController.php',
            str_contains($action, 'atlascodeobservedsessioncontroller@') => 'app/Http/Controllers/AtlasCodeObservedSessionController.php',
            default => 'owner_review_required',
        };
    }

    /**
     * @return array<string,string>
     */
    private function duplicateClassEvidenceHint(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'reachability' => 'all_three_known_paths_are_high_reachability',
                'observed_boundary' => 'kernel_envelope_atlas_dev_provider_safe_schema_and_sdd_pipeline_envelope_are_distinct_runtime_shapes',
                'cleanup_bias' => 'do_not_delete; prefer explicit naming or owner doc boundary before merge',
            ],
            'verificationcommandrunner' => [
                'reachability' => 'both_known_paths_are_high_reachability',
                'observed_boundary' => 'atlas_code_concrete_runner_and_atlas_dev_gate_interface_share_short_name',
                'cleanup_bias' => 'consider interface rename or boundary doc; do_not_delete_without_adapter_plan',
            ],
            'frontmatterparser' => [
                'reachability' => 'both_known_paths_are_high_reachability',
                'observed_boundary' => 'semantic_parser_builds_and_validates_docs; vault_parser_is_read_only_vault_shape_parser',
                'cleanup_bias' => 'consider consolidation or explicit parser naming; do_not_delete_without_vault_semantic_tests',
            ],
            'aiexecutionplan' => [
                'reachability' => 'both_known_paths_are_high_reachability',
                'observed_boundary' => 'eloquent_persistent_plan_model_and_prompt_value_object_share_short_name',
                'cleanup_bias' => 'consider value_object_rename; preserve model/table contract',
            ],
            'smokesubject' => [
                'reachability' => 'generated_inside_smoke_workspaces',
                'observed_boundary' => 'local_fixture_class_created_by_smoke_commands_not_production_domain_class',
                'cleanup_bias' => 'low_priority; document_fixture_intent_or_exclude_generated_workspace_subject',
            ],
            default => [
                'reachability' => 'run_target_reachability_before_cleanup',
                'observed_boundary' => 'unknown',
                'cleanup_bias' => 'owner_review_required',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateClassCleanupRecommendation(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'priority' => 1,
                'decision' => 'keep_boundaries_then_rename_only_with_adapter_plan',
                'safe_next_action' => 'add_or_update_owner_docs_to_name_kernel_vs_atlas_dev_vs_sdd_envelopes_explicitly_before_any_code_merge',
                'rename_candidate' => 'programming_variants_only',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'frontmatterparser' => [
                'priority' => 2,
                'decision' => 'use_explicit_parser_aliases_before_any_rename_or_consolidation',
                'safe_next_action' => 'use CanonicalDocsFrontmatterParser or VaultNoteFrontmatterParser in new code while old names remain compatibility contracts',
                'rename_candidate' => 'App\\Services\\Vault\\FrontmatterParser',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'verificationcommandrunner' => [
                'priority' => 3,
                'decision' => 'use_explicit_runner_aliases_before_any_interface_rename',
                'safe_next_action' => 'use AtlasCodeVerificationCommandRunner for observed-session execution and AtlasDevVerificationCommandRunnerContract for AtlasDev gate injection in new code',
                'rename_candidate' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\VerificationCommandRunner',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'aiexecutionplan' => [
                'priority' => 4,
                'decision' => 'use_explicit_execution_plan_aliases_before_any_rename',
                'safe_next_action' => 'use PersistentAiExecutionPlan for database plans and AiPromptExecutionPlan for prompt payloads in new code while old names remain compatibility contracts',
                'rename_candidate' => 'App\\Services\\Ai\\ValueObjects\\AiExecutionPlan',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'smokesubject' => [
                'priority' => 5,
                'decision' => 'keep_as_generated_fixture_or_exclude_from_production_duplicate_pressure',
                'safe_next_action' => 'document_fixture_generation_and_do_not_include_it_in_production_cleanup_queue',
                'rename_candidate' => null,
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            default => [
                'priority' => 99,
                'decision' => 'owner_review_required',
                'safe_next_action' => 'run_reachability_for_every_path_and_update_owner_doc_before_cleanup',
                'rename_candidate' => null,
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateClassBoundaryContract(string $value): array
    {
        return match ($value) {
            'operationenvelope' => [
                'canonical_owner' => 'kernel_and_programming_boundaries',
                'primary_runtime' => 'app/Services/Ai/Kernel/Envelope/OperationEnvelope.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/kernel/contracts.md',
                    'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md',
                    'docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md',
                ],
                'schema_versions' => [
                    'kernel' => 'atlas.envelope.v1',
                    'atlas_dev' => 'atlas.dev.operation_envelope.v1',
                    'sdd_pipeline' => 'local_pipeline_dto_without_kernel_schema',
                ],
                'cleanup_sequence' => [
                    'keep_kernel_operation_envelope_name_and_schema_stable',
                    'keep_app_and_tests_importing_programming_variants_through_explicit_aliases',
                    'add_adapter_or_alias_tests_before_any_programming_variant_rename',
                    'rename_atlas_dev_variant_only_with_surface_adapter_migration',
                    'rename_sdd_variant_only_with_pipeline_adapter_migration',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->operationEnvelopeMigrationState(),
                'proposed_explicit_names' => [
                    'atlas_dev' => 'AtlasDevOperationEnvelope',
                    'sdd_pipeline' => 'SddPipelineOperationEnvelope',
                ],
                'adapter_boundary' => [
                    'atlas_dev_to_kernel' => 'requires_explicit_projection_not_shared_class_name',
                    'sdd_to_kernel' => 'requires_pipeline_result_adapter_not_kernel_envelope_import',
                ],
                'compatibility_aliases' => [
                    'atlas_dev' => 'app/Services/Ai/Programming/AtlasDev/Schemas/AtlasDevOperationEnvelope.php',
                    'sdd_pipeline' => 'app/Services/Ai/Programming/Sdd/Pipeline/SddPipelineOperationEnvelope.php',
                ],
                'specialized_variants' => [
                    'app/Services/Ai/Programming/AtlasDev/Schemas/OperationEnvelope.php',
                    'app/Services/Ai/Programming/Sdd/Pipeline/OperationEnvelope.php',
                ],
                'allowed_direction' => 'kernel_envelope_is_provider_safe_cross_runtime_contract; specialized_envelopes_must_stay_domain_local',
                'forbidden' => 'do_not_import_specialized_programming_envelope_as_kernel_contract_or_merge_without_adapter_plan',
            ],
            'verificationcommandrunner' => [
                'canonical_owner' => 'atlas_code_runner_vs_atlas_dev_gate',
                'primary_runtime' => 'app/Services/AtlasCode/VerificationCommandRunner.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-06.md',
                    'docs/engineering-knowledge-base/atlas-dev-patamares.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'atlas_code_runner' => [
                        'kind' => 'concrete_service',
                        'schema_version' => 'atlas.code.verification_run.v1',
                        'modes' => ['dry_run', 'execute'],
                        'guards' => ['workspace_check', 'allowlist', 'operator_override_token', 'timeout', 'evidence_persistence'],
                        'primary_consumers' => ['AtlasCodeObservedSessionController'],
                    ],
                    'atlas_dev_gate_runner' => [
                        'kind' => 'interface_contract',
                        'implementation' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\SymfonyProcessCommandRunner',
                        'result_contract' => 'VerificationCommandResult',
                        'guards' => ['UnsafeCommandPolicy', 'workspace_check', 'timeout'],
                        'primary_consumers' => ['VerificationGate', 'PipelineRunExecutor', 'AtlasDevReadinessService'],
                    ],
                ],
                'specialized_variants' => [
                    'app/Services/Ai/Programming/AtlasDev/Gate/VerificationCommandRunner.php',
                ],
                'cleanup_sequence' => [
                    'keep_atlas_code_concrete_runner_schema_and_evidence_contract_stable',
                    'keep_app_and_tests_importing_runner_variants_through_explicit_aliases',
                    'add_observed_session_and_atlas_dev_gate_tests_before_any_rename',
                    'rename_atlas_dev_gate_interface_only_with_container_binding_and_fake_runner_migration',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->verificationCommandRunnerMigrationState(),
                'proposed_explicit_names' => [
                    'atlas_code' => 'AtlasCodeVerificationCommandRunner',
                    'atlas_dev_gate' => 'AtlasDevVerificationCommandRunnerContract',
                ],
                'adapter_boundary' => [
                    'atlas_code_to_atlas_dev_gate' => 'requires_explicit_adapter_not_short_name_typehint_swap',
                    'atlas_dev_gate_to_atlas_code' => 'requires_observed_session_evidence_adapter_not_interface_reuse',
                ],
                'compatibility_aliases' => [
                    'atlas_code' => 'app/Services/AtlasCode/AtlasCodeVerificationCommandRunner.php',
                    'atlas_dev_gate' => 'app/Services/Ai/Programming/AtlasDev/Gate/AtlasDevVerificationCommandRunnerContract.php',
                ],
                'allowed_direction' => 'atlas_code_concrete_runner_executes_verification; atlas_dev_gate_contract_describes_runner_boundary',
                'forbidden' => 'do_not_swap_interface_and_concrete_runner_by_short_class_name',
            ],
            'frontmatterparser' => [
                'canonical_owner' => 'semantic_docs_parser_vs_vault_parser',
                'primary_runtime' => 'app/Services/Semantic/FrontmatterParser.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
                    'docs/engineering-knowledge-base/obsidian-atlas-vault.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'semantic_parser' => [
                        'purpose' => 'canonical_engineering_docs_parse_validate_build',
                        'returns_errors' => true,
                        'validates_required_doc_fields' => ['id', 'type', 'title', 'status', 'summary'],
                        'builds_markdown' => true,
                    ],
                    'vault_parser' => [
                        'purpose' => 'read_only_vault_note_and_cartography_shape_parse',
                        'returns_errors' => false,
                        'accepts_dotted_or_hyphenated_keys' => true,
                        'supports_gear_flow_object_lists' => true,
                        'strips_bom' => true,
                    ],
                ],
                'specialized_variants' => [
                    'app/Services/Vault/FrontmatterParser.php',
                ],
                'proposed_explicit_names' => [
                    'semantic_docs' => 'CanonicalDocsFrontmatterParser',
                    'vault_notes' => 'VaultNoteFrontmatterParser',
                ],
                'compatibility_aliases' => [
                    'semantic_docs' => 'app/Services/Semantic/CanonicalDocsFrontmatterParser.php',
                    'vault_notes' => 'app/Services/Vault/VaultNoteFrontmatterParser.php',
                ],
                'cleanup_sequence' => [
                    'keep_semantic_parser_as_canonical_docs_health_and_authority_parser',
                    'keep_vault_parser_as_read_only_vault_and_cartography_shape_parser',
                    'use_explicit_compatibility_aliases_for_new_code',
                    'add_docs_health_authority_vault_reader_and_cartography_tests_before_any_consolidation',
                    'consolidate_only_with_adapter_that_preserves_error_semantics_and_vault_shape_lists',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->frontmatterParserMigrationState(),
                'adapter_boundary' => [
                    'vault_to_canonical_docs' => 'requires_validation_adapter_that_returns_errors_and_enforces_required_fields',
                    'canonical_docs_to_vault' => 'requires_read_only_shape_adapter_that_preserves_vault_cartography_lists',
                ],
                'allowed_direction' => 'semantic_parser_governs_engineering_docs; vault_parser_reads_vault_note_shape',
                'forbidden' => 'do_not_use_vault_parser_as_canonical_engineering_doc_parser',
            ],
            'aiexecutionplan' => [
                'canonical_owner' => 'persistent_model_vs_prompt_value_object',
                'primary_runtime' => 'app/Models/AiExecutionPlan.php',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'persistent_model' => [
                        'kind' => 'eloquent_model',
                        'table' => 'ai_execution_plans',
                        'primary_consumers' => ['AtlasAutonomousEngineeringService'],
                        'casts' => ['steps', 'expected_files', 'expected_tests', 'risks', 'rollback_plan', 'compounding_memories', 'receipt'],
                        'schema_version_examples' => ['atlas.ai.autonomous_engineering.execution_plan.v1'],
                    ],
                    'prompt_value_object' => [
                        'kind' => 'prompt_runtime_value_object',
                        'factory' => 'AiExecutionPlan::fromTask',
                        'primary_consumers' => ['AiPromptBuilder', 'KernelArchitectureStaticScanner AP-149'],
                        'required_payload' => ['agent_behavior_contract', 'workflow', 'selected_provider', 'tools_allowed', 'quality_gates'],
                        'output_methods' => ['toArray', 'toPromptSection'],
                    ],
                ],
                'specialized_variants' => [
                    'app/Services/Ai/ValueObjects/AiExecutionPlan.php',
                ],
                'cleanup_sequence' => [
                    'keep_persistent_model_table_and_schema_stable',
                    'keep_app_and_tests_importing_execution_plan_variants_through_explicit_aliases',
                    'add_prompt_builder_and_autonomous_engineering_tests_before_any_rename',
                    'rename_prompt_value_object_only_with_prompt_builder_and_static_scanner_migration',
                    'remove_compatibility_alias_only_after_all_exact_references_are_migrated',
                ],
                'current_migration_state' => $this->aiExecutionPlanMigrationState(),
                'proposed_explicit_names' => [
                    'persistent_model' => 'PersistentAiExecutionPlan',
                    'prompt_value_object' => 'AiPromptExecutionPlan',
                ],
                'compatibility_aliases' => [
                    'persistent_model' => 'app/Models/PersistentAiExecutionPlan.php',
                    'prompt_value_object' => 'app/Services/Ai/ValueObjects/AiPromptExecutionPlan.php',
                ],
                'adapter_boundary' => [
                    'model_to_prompt_value_object' => 'requires_explicit_projection_from_persisted_plan_not_direct_type_reuse',
                    'prompt_value_object_to_model' => 'requires_database_model_creation_path_not_prompt_payload_typehint',
                ],
                'allowed_direction' => 'model_represents_database_contract; value_object_represents_prompt_runtime_payload',
                'forbidden' => 'do_not_typehint_value_object_when_database_model_contract_is_required',
            ],
            'smokesubject' => [
                'canonical_owner' => 'generated_smoke_fixture',
                'primary_runtime' => 'generated_fixture_inside_smoke_workspace',
                'owner_docs' => [
                    'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md',
                    'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
                ],
                'contract_features' => [
                    'fixture_generators' => [
                        'kind' => 'generated_workspace_fixture',
                        'primary_consumers' => [
                            'AtlasDevSeniorLoopAuditCommand',
                            'AtlasDevDesktopRealSmokeCommand',
                            'AtlasDevSeniorLoopRunCommand',
                        ],
                        'generated_files' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
                        'intent' => 'exercise_patch_apply_verification_and_desktop_smoke_flow_with_local_workspace_only',
                    ],
                    'production_boundary' => [
                        'kind' => 'non_production_fixture',
                        'namespace' => 'Smoke',
                        'persistence' => false,
                        'route_surface' => false,
                    ],
                ],
                'specialized_variants' => [
                    'app/Console/Commands/AtlasDevSeniorLoopAuditCommand.php',
                    'app/Console/Commands/AtlasDevDesktopRealSmokeCommand.php',
                    'app/Console/Commands/AtlasDevSeniorLoopRunCommand.php',
                ],
                'cleanup_sequence' => [
                    'keep_generator_commands_when_they_prove_distinct_smoke_flows',
                    'never_create_repo_production_class_for_smokesubject',
                    'only_extract_shared_fixture_template_after_atlas_dev_owner_decision',
                    'run_senior_loop_and_desktop_smoke_tests_before_generator_changes',
                ],
                'generator_write_boundary' => [
                    'writes_repo_production_code' => false,
                    'writes_local_smoke_workspace_only' => true,
                    'allowed_generated_paths' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
                    'evidence_scope' => 'smoke_fixture_proves_patch_apply_and_verification_flow_not_atlas_feature_runtime',
                ],
                'allowed_direction' => 'generated_local_fixture_only',
                'forbidden' => 'do_not_treat_as_production_domain_class',
            ],
            default => [
                'canonical_owner' => 'owner_review_required',
                'primary_runtime' => 'owner_review_required',
                'specialized_variants' => [],
                'allowed_direction' => 'declare_before_new_code',
                'forbidden' => 'do_not_choose_by_short_class_name',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function operationEnvelopeMigrationState(): array
    {
        $directAtlasDev = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\OperationEnvelope;';
        $directSdd = 'use App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\OperationEnvelope;';
        $aliasAtlasDev = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Schemas\\AtlasDevOperationEnvelope as OperationEnvelope;';
        $aliasSdd = 'use App\\Services\\Ai\\Programming\\Sdd\\Pipeline\\SddPipelineOperationEnvelope as OperationEnvelope;';

        $counts = [
            'app_and_tests_direct_programming_imports' => 0,
            'atlas_dev_alias_imports' => 0,
            'sdd_pipeline_alias_imports' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_programming_imports'] += substr_count($contents, $directAtlasDev);
                $counts['app_and_tests_direct_programming_imports'] += substr_count($contents, $directSdd);
                $counts['atlas_dev_alias_imports'] += substr_count($contents, $aliasAtlasDev);
                $counts['sdd_pipeline_alias_imports'] += substr_count($contents, $aliasSdd);
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_programming_imports'] === 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function verificationCommandRunnerMigrationState(): array
    {
        $directAtlasCode = 'use App\\Services\\AtlasCode\\VerificationCommandRunner;';
        $directAtlasDevGate = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\VerificationCommandRunner;';
        $aliasAtlasCode = 'use App\\Services\\AtlasCode\\AtlasCodeVerificationCommandRunner as VerificationCommandRunner;';
        $aliasAtlasDevGate = 'use App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;';

        $counts = [
            'app_and_tests_direct_runner_imports' => 0,
            'atlas_code_alias_imports' => 0,
            'atlas_dev_gate_alias_imports' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_runner_imports'] += substr_count($contents, $directAtlasCode);
                $counts['app_and_tests_direct_runner_imports'] += substr_count($contents, $directAtlasDevGate);
                $counts['atlas_code_alias_imports'] += substr_count($contents, $aliasAtlasCode);
                $counts['atlas_dev_gate_alias_imports'] += substr_count($contents, $aliasAtlasDevGate);
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_runner_imports'] === 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function frontmatterParserMigrationState(): array
    {
        $directSemantic = 'use App\\Services\\Semantic\\FrontmatterParser;';
        $directVault = 'use App\\Services\\Vault\\FrontmatterParser;';
        $aliasSemantic = 'use App\\Services\\Semantic\\CanonicalDocsFrontmatterParser;';
        $aliasVault = 'use App\\Services\\Vault\\VaultNoteFrontmatterParser;';

        $counts = [
            'app_and_tests_direct_parser_imports' => 0,
            'canonical_docs_alias_imports' => 0,
            'vault_note_alias_imports' => 0,
            'vault_note_local_typehints' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_parser_imports'] += substr_count($contents, $directSemantic);
                $counts['app_and_tests_direct_parser_imports'] += substr_count($contents, $directVault);
                $counts['canonical_docs_alias_imports'] += substr_count($contents, $aliasSemantic);
                $counts['vault_note_alias_imports'] += substr_count($contents, $aliasVault);
                if ($root === 'app') {
                    $counts['vault_note_local_typehints'] += substr_count($contents, 'private readonly VaultNoteFrontmatterParser $parser');
                }
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_parser_imports'] === 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aiExecutionPlanMigrationState(): array
    {
        $directModel = 'use App\\Models\\AiExecutionPlan;';
        $directPromptValueObject = 'use App\\Services\\Ai\\ValueObjects\\AiExecutionPlan;';
        $aliasModel = 'use App\\Models\\PersistentAiExecutionPlan as AiExecutionPlan;';
        $aliasPromptValueObject = 'use App\\Services\\Ai\\ValueObjects\\AiPromptExecutionPlan as AiExecutionPlan;';

        $counts = [
            'app_and_tests_direct_execution_plan_imports' => 0,
            'persistent_model_alias_imports' => 0,
            'prompt_value_object_alias_imports' => 0,
        ];

        foreach (['app', 'tests'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $counts['app_and_tests_direct_execution_plan_imports'] += substr_count($contents, $directModel);
                $counts['app_and_tests_direct_execution_plan_imports'] += substr_count($contents, $directPromptValueObject);
                $counts['persistent_model_alias_imports'] += substr_count($contents, $aliasModel);
                $counts['prompt_value_object_alias_imports'] += substr_count($contents, $aliasPromptValueObject);
            }
        }

        return [
            ...$counts,
            'remaining_exact_refs_are_boundary_docs_or_compatibility_alias_files' => $counts['app_and_tests_direct_execution_plan_imports'] === 0,
        ];
    }

    private function normalizeRoutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        return '/'.trim($path, '/');
    }

    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $code
     * @return array<string,array<string,mixed>>
     */
    private function topicClusters(array $docs, array $code): array
    {
        $clusters = [];
        foreach (self::CRITICAL_TOPIC_CLUSTERS as $id => $definition) {
            $docMatches = array_values(array_filter(
                (array) $docs['items'],
                static fn (array $doc): bool => in_array($id, (array) ($doc['critical_topics'] ?? []), true)
            ));
            $codeMatches = $this->codeMatchesForTerms((array) $definition['terms']);
            $canonicalDocs = array_values(array_filter($docMatches, static fn (array $doc): bool => $doc['canonical'] === true && $doc['archive'] === false));
            $sourceMaterialDocs = array_values(array_filter($docMatches, static fn (array $doc): bool => $doc['archive'] === true));
            $nonCanonicalActiveDocs = array_values(array_filter(
                $docMatches,
                static fn (array $doc): bool => $doc['canonical'] === false
                    && $doc['archive'] === false
                    && ! in_array($doc['status'], ['archived', 'source_material'], true)
            ));
            $flowFamilies = $this->flowFamilyGroups($codeMatches, $id);
            $flowFamilyBoundaryQueue = $this->flowFamilyReviewQueue($id, $flowFamilies);
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
    private function criticalTopicPressure(array $topicClusters): array
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
    private function ragRetrievalCleanupQueue(array $topicClusters): array
    {
        return $this->ragRetrievalBoundaryQueue($topicClusters, false);
    }

    /**
     * @param  array<string,array<string,mixed>>  $topicClusters
     * @return array<int,array<string,mixed>>
     */
    private function ragRetrievalResolvedBoundaryQueue(array $topicClusters): array
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
            'next_commands' => $this->uniqueStrings($nextCommands),
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
    private function frontendProgrammingCleanupQueue(array $topicClusters): array
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

            $targets = $this->uniqueStrings([
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
                'next_commands' => $this->uniqueStrings($nextCommands),
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
    private function docPathStemBoundaryQueue(array $groups): array
    {
        $items = [];

        foreach ($groups as $group) {
            $stem = (string) ($group['value'] ?? 'unknown');
            $sampleItems = (array) ($group['sample_items'] ?? []);
            $paths = array_values(array_map('strval', (array) ($group['paths'] ?? [])));
            $owners = $this->itemStringColumn($sampleItems, 'owner', filterEmpty: true);
            sort($owners);
            $criticalTopics = $this->itemStringListColumn($sampleItems, 'critical_topics');
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
    private function documentedDocPathStemBoundaryQueue(array $docPathStemBoundaryQueue): array
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
    private function reviewDocPathStemBoundaryQueue(array $docPathStemBoundaryQueue): array
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

        $targetPath = $this->targetPath($target);
        $basename = basename($target);
        $needle = $targetPath ?? $target;
        $reachability = $this->reachabilityEnvelope(
            $targetPath,
            $this->references($needle, $basename),
            $this->ownerDocs($needle, $basename),
            $this->testRefs($needle, $basename),
            $this->entrypoints($needle, $basename),
            $this->constructorInjectors($basename, $targetPath),
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
    private function aiConfusionCleanupQueue(array $triageQueue, array $criticalTopicPressure, array $statusDrift, array $code, array $sourceMaterialShadowQueue, array $ragRetrievalCleanupQueue, array $frontendProgrammingCleanupQueue, array $docPathStemBoundaryQueue): array
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
    private function sourceMaterialShadowQueue(array $topicClusters): array
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

        return $this->formatPathCountGroups($groups, 6);
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

        return $this->formatPathCountGroups($groups);
    }

    /**
     * @param  array<int,string>  $matches
     * @return array<int,array<string,mixed>>
     */
    private function flowFamilyGroups(array $matches, string $topic): array
    {
        $groups = [];
        foreach ($matches as $path) {
            $family = $this->flowFamilyForPath($path, $topic);
            if ($family === null) {
                continue;
            }
            $groups[$family][] = $path;
        }

        return $this->formatPathCountGroups($groups);
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
    private function flowFamilyReviewQueue(string $topic, array $families): array
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
                'owner_runtime' => 'app/Services/Ai/AtlasMemoryQualityService.php',
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

    /**
     * @param  array<string,array<int,string>>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function formatPathCountGroups(array $groups, int $sampleLimit = 8): array
    {
        $items = [];
        foreach ($groups as $key => $paths) {
            $items[] = [
                'value' => $key,
                'count' => count($paths),
                'samples' => array_slice(array_values($paths), 0, $sampleLimit),
            ];
        }
        usort($items, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    private function itemStringColumn(array $items, string $key, bool $filterEmpty = false): array
    {
        $values = array_map(
            static fn (array $item): string => (string) ($item[$key] ?? ''),
            $items,
        );

        return $this->uniqueStrings($values, filterEmpty: $filterEmpty);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    private function itemStringListColumn(array $items, string $key): array
    {
        return $this->uniqueStrings(array_merge(...array_map(
            static fn (array $item): array => array_map('strval', (array) ($item[$key] ?? [])),
            $items,
        )));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function uniqueStrings(array $values, bool $filterEmpty = false): array
    {
        return $filterEmpty
            ? EngineeringStringListNormalizer::uniqueTruthyStringCasts($values)
            : EngineeringStringListNormalizer::uniqueStringCasts($values);
    }

    /**
     * @param  array<int,array<string,string>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function duplicateGroups(array $items, string $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $value = (string) ($item[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $groups[strtolower($value)][] = $item;
        }

        return array_values(array_filter(array_map(
            function (array $group, string $value): ?array {
                if (count($group) <= 1) {
                    return null;
                }

                $duplicateGroup = [
                    'value' => $value,
                    'count' => count($group),
                    'paths' => $this->itemStringColumn($group, 'path'),
                    'sample_items' => array_slice($group, 0, 5),
                ];
                $methodUris = $this->itemStringColumn($group, 'method_uri', filterEmpty: true);
                if ($methodUris !== []) {
                    $duplicateGroup['method_uris'] = $methodUris;
                }

                return $duplicateGroup;
            },
            $groups,
            array_keys($groups)
        )));
    }

    /**
     * @return array<string,mixed>
     */
    private function frontmatter(string $path): array
    {
        $content = $this->readSmallFile($path);
        if ($content === null || ! str_starts_with($content, "---\n")) {
            return [];
        }
        $end = strpos($content, "\n---", 4);
        if ($end === false) {
            return [];
        }

        $frontmatter = [];
        foreach (explode("\n", substr($content, 4, $end - 4)) as $line) {
            if (! str_contains($line, ':') || str_starts_with(ltrim($line), '-')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key !== '' && $value !== '') {
                $frontmatter[$key] = trim($value, '"\'');
            }
        }

        return $frontmatter;
    }

    /**
     * @return array<int,string>
     */
    private function criticalTopicsForFile(string $path): array
    {
        $content = $this->readSmallFile($path);
        if ($content === null) {
            return [];
        }
        $lower = strtolower($content);
        $topics = [];
        foreach (self::CRITICAL_TOPIC_CLUSTERS as $id => $definition) {
            foreach ($definition['terms'] as $term) {
                if (str_contains($lower, strtolower($term))) {
                    $topics[] = $id;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,string>
     */
    private function codeMatchesForTerms(array $terms): array
    {
        $matches = [];
        foreach ($this->allFiles(['app', 'routes', 'config', 'database']) as $file) {
            $path = $file->getPathname();
            if (! $this->isTextFile($path) || ! $this->fileContainsAny($path, $terms)) {
                continue;
            }
            $matches[] = $this->relativePath($path);
        }
        sort($matches);

        return $this->uniqueStrings($matches);
    }

    private function readSmallFile(string $path): ?string
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_SCAN_BYTES) {
            return null;
        }

        try {
            return File::get($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function envelope(array $payload): array
    {
        $base = array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => (($payload['blockers'] ?? []) === []) ? 'ready' : 'blocked',
            'writes' => false,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'deletes_files' => false,
                'dead_code_confirmation_allowed' => false,
            ],
        ], $payload);

        $hashPayload = $base;
        unset($hashPayload['certification_hash']);
        $base['certification_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $base;
    }

    private function targetPath(string $target): ?string
    {
        if ($target === '') {
            return null;
        }

        $direct = base_path($target);
        if (File::exists($direct)) {
            return $target;
        }

        $startedAt = microtime(true);
        $visited = 0;
        foreach ($this->allFiles() as $file) {
            if (basename($file->getPathname()) === $target) {
                return $this->relativePath($file->getPathname());
            }
        }

        foreach ($this->allFiles() as $file) {
            $visited++;
            if ($visited > self::MAX_SCAN_FILES || microtime(true) - $startedAt > self::MAX_SCAN_SECONDS) {
                break;
            }
            $relative = $this->relativePath($file->getPathname());
            if (str_ends_with($relative, $target) || basename($relative) === $target) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function references(string $needle, string $basename): array
    {
        return $this->scanFor($needle, $basename, self::SEARCH_ROOTS);
    }

    /**
     * @return array<int,string>
     */
    private function ownerDocs(string $needle, string $basename): array
    {
        return array_values(array_filter(
            $this->scanFor($needle, $basename, ['docs/engineering-knowledge-base']),
            static fn (string $path): bool => str_ends_with($path, '.md')
        ));
    }

    /**
     * @return array<int,string>
     */
    private function testRefs(string $needle, string $basename): array
    {
        return $this->scanFor($needle, $basename, ['tests']);
    }

    /**
     * @return array<int,string>
     */
    private function entrypoints(string $needle, string $basename): array
    {
        return $this->uniqueStrings(array_merge(
            $this->scanFor($needle, $basename, ['routes']),
            array_values(array_filter($this->scanFor($needle, $basename, ['app/Console/Commands']), static fn (string $path): bool => str_contains($path, 'Commands/'))),
        ));
    }

    /**
     * @param  array<int,string>  $references
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $entrypoints
     * @return array<string,mixed>
     */
    private function reachabilityEnvelope(?string $targetPath, array $references, array $ownerDocs, array $tests, array $entrypoints, array $constructorInjectors = []): array
    {
        $breakdown = $this->sourceBreakdown($references, $ownerDocs, $tests, $entrypoints, $constructorInjectors);
        $signals = [
            'target_exists' => $targetPath !== null,
            'has_route_entrypoint' => $breakdown['routes']['count'] > 0,
            'has_command_entrypoint' => $breakdown['commands']['count'] > 0,
            'has_test_coverage' => $breakdown['tests']['count'] > 0,
            'has_owner_doc' => $breakdown['owner_docs']['count'] > 0,
            'has_service_or_code_callers' => $breakdown['code_callers']['count'] > 0,
            'has_constructor_injectors' => $breakdown['constructor_injectors']['count'] > 0,
            'has_config_reference' => $breakdown['config']['count'] > 0,
            'has_database_reference' => $breakdown['database']['count'] > 0,
        ];
        $strongSignals = count(array_filter([
            $signals['has_route_entrypoint'],
            $signals['has_command_entrypoint'],
            $signals['has_test_coverage'] && $signals['has_owner_doc'],
            $signals['has_service_or_code_callers'] && $signals['has_test_coverage'],
        ]));
        $confidence = match (true) {
            ! $signals['target_exists'] => 'none',
            $strongSignals >= 2 => 'high',
            $strongSignals === 1 || $signals['has_owner_doc'] || $signals['has_service_or_code_callers'] => 'medium',
            count($references) > 1 => 'low',
            default => 'review_required',
        };
        $status = match (true) {
            ! $signals['target_exists'] => 'not_found',
            in_array($confidence, ['high', 'medium'], true) => 'reachable',
            $confidence === 'low' => 'weakly_referenced',
            default => 'unproven',
        };

        return [
            'schema_version' => self::REACHABILITY_SCHEMA_VERSION,
            'status' => $status,
            'confidence' => $confidence,
            'target_path' => $targetPath,
            'signals' => $signals,
            'source_breakdown' => $breakdown,
            'edges' => $this->reachabilityEdges($targetPath, $breakdown),
            'policy' => [
                'dead_code_confirmation_allowed' => false,
                'delete_requires_quarantine_and_human_approval' => true,
                'weak_or_missing_reachability_blocks_delete_claims' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $references
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $entrypoints
     * @param  array<int,string>  $constructorInjectors
     * @return array<string,array{count:int,paths:array<int,string>}>
     */
    private function sourceBreakdown(array $references, array $ownerDocs, array $tests, array $entrypoints, array $constructorInjectors = []): array
    {
        $routes = array_values(array_filter($entrypoints, static fn (string $path): bool => str_starts_with($path, 'routes/')));
        $commands = array_values(array_filter($entrypoints, static fn (string $path): bool => str_starts_with($path, 'app/Console/Commands/')));
        $config = array_values(array_filter($references, static fn (string $path): bool => str_starts_with($path, 'config/')));
        $database = array_values(array_filter($references, static fn (string $path): bool => str_starts_with($path, 'database/')));
        $docs = array_values(array_filter($references, static fn (string $path): bool => str_starts_with($path, 'docs/')));
        $codeCallers = array_values(array_filter($references, static function (string $path): bool {
            return str_starts_with($path, 'app/')
                && ! str_starts_with($path, 'app/Console/Commands/');
        }));

        return [
            'routes' => ['count' => count($routes), 'paths' => array_slice($routes, 0, 12)],
            'commands' => ['count' => count($commands), 'paths' => array_slice($commands, 0, 12)],
            'tests' => ['count' => count($tests), 'paths' => array_slice($tests, 0, 12)],
            'owner_docs' => ['count' => count($ownerDocs), 'paths' => array_slice($ownerDocs, 0, 12)],
            'docs' => ['count' => count($docs), 'paths' => array_slice($docs, 0, 12)],
            'code_callers' => ['count' => count($codeCallers), 'paths' => array_slice($codeCallers, 0, 12)],
            'constructor_injectors' => ['count' => count($constructorInjectors), 'paths' => array_slice($constructorInjectors, 0, 12)],
            'config' => ['count' => count($config), 'paths' => array_slice($config, 0, 12)],
            'database' => ['count' => count($database), 'paths' => array_slice($database, 0, 12)],
        ];
    }

    /**
     * @param  array<string,array{count:int,paths:array<int,string>}>  $breakdown
     * @return array<int,array<string,string>>
     */
    private function reachabilityEdges(?string $targetPath, array $breakdown): array
    {
        if ($targetPath === null) {
            return [];
        }

        $edges = [];
        foreach ($breakdown as $kind => $group) {
            foreach (array_slice($group['paths'], 0, 5) as $path) {
                if ($path === $targetPath) {
                    continue;
                }
                $edges[] = [
                    'from' => $path,
                    'to' => $targetPath,
                    'kind' => $kind,
                ];
            }
        }

        return $edges;
    }

    /**
     * DI-aware reachability (Obra #12 lesson): app files that type-hint the class
     * (`Foo $x`) or resolve it (`Foo::class`) are live wiring even without a `use`
     * statement, tests or docs. Target file itself never counts.
     *
     * @return array<int,string>
     */
    private function constructorInjectors(string $basename, ?string $targetPath): array
    {
        $shortName = pathinfo($basename, PATHINFO_FILENAME);
        if ($shortName === '') {
            return [];
        }

        // ponytail: lexical patterns, not AST — a type-hint or ::class of the short name in app/ is the DI signal
        $terms = [$shortName.' $', $shortName.'::class'];

        $matches = [];
        $startedAt = microtime(true);
        $visited = 0;
        foreach ($this->allFiles(['app']) as $file) {
            $visited++;
            if ($visited > self::MAX_SCAN_FILES || microtime(true) - $startedAt > self::MAX_SCAN_SECONDS) {
                break;
            }
            $path = $file->getPathname();
            if (! $this->isTextFile($path)) {
                continue;
            }
            $relative = $this->relativePath($path);
            if ($relative === $targetPath) {
                continue;
            }
            if ($this->fileContainsAny($path, $terms)) {
                $matches[] = $relative;
            }
        }

        sort($matches);

        return $this->uniqueStrings($matches);
    }

    /**
     * @param  array<int,string>  $roots
     * @return array<int,string>
     */
    private function scanFor(string $needle, string $basename, array $roots): array
    {
        $terms = $this->uniqueStrings([
            $needle,
            $basename,
            pathinfo($basename, PATHINFO_FILENAME),
            class_basename($needle),
        ], filterEmpty: true);
        if ($terms === []) {
            return [];
        }

        $matches = [];
        $startedAt = microtime(true);
        $visited = 0;
        foreach ($this->allFiles($roots) as $file) {
            $visited++;
            if ($visited > self::MAX_SCAN_FILES || microtime(true) - $startedAt > self::MAX_SCAN_SECONDS) {
                break;
            }
            $path = $file->getPathname();
            if (! $this->isTextFile($path)) {
                continue;
            }

            if ($this->fileContainsAny($path, $terms)) {
                $matches[] = $this->relativePath($path);
            }
        }

        sort($matches);

        return $this->uniqueStrings($matches);
    }

    /**
     * @param  array<int,string>  $roots
     * @return iterable<int,\SplFileInfo>
     */
    private function allFiles(array $roots = self::SEARCH_ROOTS): iterable
    {
        foreach ($roots as $root) {
            $path = base_path($root);
            if (! File::isDirectory($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    yield $file;
                }
            }
        }
    }

    /**
     * Real class/interface/trait/enum declarations via the PHP tokenizer, so
     * declarations inside string literals/heredocs (test fixtures embedded in
     * commands) never count as duplicates. Skips `Foo::class` and anonymous
     * classes by looking at the surrounding meaningful tokens.
     *
     * @return array<int,string>
     */
    private function declaredPhpTypeNames(string $content): array
    {
        $names = [];
        try {
            $tokens = token_get_all($content);
        } catch (\Throwable) {
            return [];
        }

        $declarationIds = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];
        $count = count($tokens);
        $previousMeaningfulId = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                $previousMeaningfulId = null;

                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (in_array($token[0], $declarationIds, true) && $previousMeaningfulId !== T_DOUBLE_COLON && $previousMeaningfulId !== T_NEW) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($next) && $next[0] === T_STRING) {
                        $names[] = (string) $next[1];
                    }
                    break;
                }
            }
            $previousMeaningfulId = $token[0];
        }

        return $names;
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function fileContainsAny(string $path, array $terms): bool
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_SCAN_BYTES) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $bytesRead = 0;
            while (($line = fgets($handle)) !== false) {
                $bytesRead += strlen($line);
                if ($bytesRead > self::MAX_FILE_SCAN_BYTES) {
                    return false;
                }
                foreach ($terms as $term) {
                    if (str_contains($line, $term)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($handle);
        }

        return false;
    }

    private function isTextFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'md', 'json', 'yml', 'yaml', 'ts', 'tsx', 'js', 'jsx'], true);
    }

    private function classification(?string $targetPath, array $reachability): string
    {
        if ($targetPath === null) {
            return 'unknown_requires_audit';
        }

        $signals = (array) ($reachability['signals'] ?? []);
        $hasEntrypoint = (bool) ($signals['has_route_entrypoint'] ?? false)
            || (bool) ($signals['has_command_entrypoint'] ?? false);
        $hasTests = (bool) ($signals['has_test_coverage'] ?? false);
        $hasOwner = (bool) ($signals['has_owner_doc'] ?? false);
        $hasCodeCallers = (bool) ($signals['has_service_or_code_callers'] ?? false);
        $hasConstructorInjectors = (bool) ($signals['has_constructor_injectors'] ?? false);
        $confidence = (string) ($reachability['confidence'] ?? 'review_required');

        if ($hasEntrypoint && $hasTests && $hasOwner) {
            return 'active_runtime';
        }

        if ($hasEntrypoint || ($hasTests && $hasOwner) || ($hasCodeCallers && $hasTests) || $hasConstructorInjectors) {
            return 'active_read_only';
        }

        if ($hasOwner && $hasTests) {
            return 'headless_available';
        }

        if (in_array($confidence, ['medium', 'low'], true) || $hasOwner || $hasCodeCallers) {
            return 'unknown_requires_audit';
        }

        return 'unused_candidate';
    }

    private function deletionDecision(string $classification, string $reachabilityStatus): string
    {
        if (in_array($classification, ['active_runtime', 'active_read_only', 'headless_available'], true)) {
            return 'block_delete_active_or_available_target';
        }

        if (in_array($reachabilityStatus, ['reachable', 'weakly_referenced'], true)) {
            return 'block_delete_reachability_present';
        }

        return 'block_delete_until_quarantine_and_human_approval';
    }

    /**
     * @param  array<int,string>  $ownerDocs
     * @return array<int,array<string,mixed>>
     */
    private function blockers(string $classification, ?string $targetPath, array $ownerDocs): array
    {
        if ($classification === 'unknown_requires_audit' && $targetPath === null) {
            return [[
                'reason' => 'target_not_found',
                'severity' => 'review',
                'policy' => 'do_not_implement_or_delete_until_owner_lookup_succeeds',
            ]];
        }

        if ($classification === 'unused_candidate' && $ownerDocs === []) {
            return [[
                'reason' => 'unused_candidate_requires_quarantine_review',
                'severity' => 'review',
                'policy' => 'no_delete_without_reference_scan_tests_and_human_approval',
            ]];
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [],
            static fn (string $token): bool => strlen($token) >= 4
        ));
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
