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

    private const MAX_SCAN_FILES = 10000;

    private const MAX_SCAN_SECONDS = 2.5;

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
            $entrypoints = array_values(array_unique($entrypoints));
            sort($entrypoints);
        }
        $reachability = $this->reachabilityEnvelope($targetPath, $references, $ownerDocs, $tests, $entrypoints);
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
        $docIdDuplicateGroups = $this->duplicateGroups($docs['canonical_active_items'], 'id');
        $docGraphDuplicateGroups = $this->duplicateGroups($docs['canonical_active_items'], 'graph_id');
        $docTitleDuplicateGroups = $this->duplicateGroups($docs['canonical_active_items'], 'title_key');
        $docPathStemDuplicateGroups = $this->duplicateGroups($docs['active_non_archive_items'], 'path_stem');
        $classDuplicateGroups = $this->duplicateGroups($code['classes'], 'name');
        $commandDuplicateGroups = $this->duplicateGroups($code['artisan_commands'], 'signature');
        $routeDuplicateGroups = $this->duplicateGroups($code['routes'], 'method_uri');
        $routeNameDuplicateGroups = $this->duplicateGroups($code['named_routes'], 'name');
        $routeActionAliasGroups = $this->duplicateGroups($code['route_actions'], 'action');
        $runtimeRouteDuplicateGroups = $this->duplicateGroups($runtimeRoutes['routes'], 'method_uri');
        $runtimeRouteNameDuplicateGroups = $this->duplicateGroups($runtimeRoutes['named_routes'], 'name');
        $runtimeRouteActionAliasGroups = $this->duplicateGroups($runtimeRoutes['route_actions'], 'action');
        $triageQueue = $this->duplicationTriageQueue($classDuplicateGroups, $runtimeRouteActionAliasGroups);
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
            $classDuplicateGroups === [] ? null : [
                'reason' => 'duplicate_php_class_names',
                'count' => count($classDuplicateGroups),
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
            $docPathStemDuplicateGroups === [] ? null : [
                'reason' => 'active_doc_path_stem_overlap',
                'count' => count($docPathStemDuplicateGroups),
                'policy' => 'same_filename_stem_requires_review_before_ai_uses_as_unique_owner',
            ],
            $docs['non_canonical_active_count'] === 0 ? null : [
                'reason' => 'non_canonical_active_docs_present',
                'count' => $docs['non_canonical_active_count'],
                'policy' => 'review_before_using_as_ai_authority',
            ],
            $docs['archived_source_material_count'] === 0 ? null : [
                'reason' => 'archived_source_material_can_look_duplicate',
                'count' => $docs['archived_source_material_count'],
                'policy' => 'archive_is_source_material_not_authority',
            ],
            $code['legacy_signal_count'] === 0 ? null : [
                'reason' => 'legacy_or_scaffold_signals_present',
                'count' => $code['legacy_signal_count'],
                'policy' => 'requires_reachability_before_cleanup_claims',
            ],
            $routeActionAliasGroups === [] ? null : [
                'reason' => 'route_action_aliases_present',
                'count' => count($routeActionAliasGroups),
                'policy' => 'same_controller_action_on_multiple_routes_requires_boundary_review',
            ],
            $runtimeRouteActionAliasGroups === [] ? null : [
                'reason' => 'runtime_route_action_aliases_present',
                'count' => count($runtimeRouteActionAliasGroups),
                'policy' => 'registered_aliases_require_intentional_boundary_or_cleanup_decision',
            ],
            $routeDuplicateGroups === [] ? null : [
                'reason' => 'literal_route_method_uri_overlap',
                'count' => count($routeDuplicateGroups),
                'policy' => 'regex_scan_ignores_group_prefixes_confirm_with_route_list_before_claiming_duplicate',
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
                'php_class_count' => count($code['classes']),
                'artisan_command_count' => count($code['artisan_commands']),
                'route_count' => count($code['routes']),
                'named_route_count' => count($code['named_routes']),
                'runtime_route_count' => count($runtimeRoutes['routes']),
                'runtime_named_route_count' => count($runtimeRoutes['named_routes']),
                'duplicate_class_group_count' => count($classDuplicateGroups),
                'duplicate_command_group_count' => count($commandDuplicateGroups),
                'duplicate_route_group_count' => count($routeDuplicateGroups),
                'duplicate_route_name_group_count' => count($routeNameDuplicateGroups),
                'route_action_alias_group_count' => count($routeActionAliasGroups),
                'runtime_duplicate_route_group_count' => count($runtimeRouteDuplicateGroups),
                'runtime_duplicate_route_name_group_count' => count($runtimeRouteNameDuplicateGroups),
                'runtime_route_action_alias_group_count' => count($runtimeRouteActionAliasGroups),
                'status_drift_review_count' => count($statusDrift['review_items']),
                'status_drift_owner_group_count' => count($statusDrift['owner_groups']),
                'status_drift_doc_area_group_count' => count($statusDrift['doc_area_groups']),
                'planned_or_future_with_existing_code_count' => $statusDrift['summary']['planned_or_future_with_existing_code_count'],
                'scaffold_language_with_existing_code_count' => $statusDrift['summary']['scaffold_language_with_existing_code_count'],
                'triage_queue_count' => count($triageQueue),
                'legacy_signal_count' => $code['legacy_signal_count'],
                'legacy_signal_type_count' => count($code['legacy_signal_groups']),
                'legacy_signal_area_count' => count($code['legacy_area_groups']),
                'ai_service_file_count' => $code['ai_service_hotspots']['file_count'],
                'ai_service_hotspot_subarea_count' => count($code['ai_service_hotspots']['subareas']),
                'ai_service_hotspot_topic_count' => count($code['ai_service_hotspots']['topics']),
                'critical_topic_cluster_count' => count($topicClusters),
                'critical_topic_source_material_count' => $criticalTopicPressure['source_material_count'],
                'critical_topic_noncanonical_active_count' => $criticalTopicPressure['noncanonical_active_count'],
                'rag_retrieval_source_material_count' => (int) data_get($topicClusters, 'rag_retrieval.source_material_doc_count', 0),
                'rag_retrieval_code_match_count' => (int) data_get($topicClusters, 'rag_retrieval.code_match_count', 0),
                'rag_retrieval_flow_family_count' => count((array) data_get($topicClusters, 'rag_retrieval.flow_families', [])),
                'rag_retrieval_flow_family_review_count' => count((array) data_get($topicClusters, 'rag_retrieval.flow_family_review_queue', [])),
                'rag_retrieval_code_role_count' => count((array) data_get($topicClusters, 'rag_retrieval.code_roles', [])),
            ],
            'documentation' => [
                'duplicate_canonical_doc_id_groups' => $docIdDuplicateGroups,
                'duplicate_canonical_doc_graph_id_groups' => $docGraphDuplicateGroups,
                'duplicate_canonical_doc_title_groups' => $docTitleDuplicateGroups,
                'duplicate_active_doc_path_stem_groups' => $docPathStemDuplicateGroups,
                'non_canonical_active_samples' => array_slice($docs['non_canonical_active'], 0, 20),
                'archived_source_material_samples' => array_slice($docs['archived_source_material'], 0, 20),
                'critical_topic_noncanonical_samples' => $docs['critical_topic_noncanonical_samples'],
            ],
            'code' => [
                'duplicate_class_groups' => $classDuplicateGroups,
                'duplicate_artisan_command_groups' => $commandDuplicateGroups,
                'duplicate_route_groups' => $routeDuplicateGroups,
                'duplicate_route_name_groups' => $routeNameDuplicateGroups,
                'route_action_alias_groups' => array_slice($routeActionAliasGroups, 0, 20),
                'runtime_duplicate_route_groups' => $runtimeRouteDuplicateGroups,
                'runtime_duplicate_route_name_groups' => $runtimeRouteNameDuplicateGroups,
                'runtime_route_action_alias_groups' => array_slice($runtimeRouteActionAliasGroups, 0, 30),
                'legacy_signal_groups' => $code['legacy_signal_groups'],
                'legacy_area_groups' => array_slice($code['legacy_area_groups'], 0, 20),
                'ai_service_hotspots' => $code['ai_service_hotspots'],
                'legacy_signal_samples' => array_slice($code['legacy_signals'], 0, 30),
            ],
            'topic_clusters' => $topicClusters,
            'critical_topic_pressure' => $criticalTopicPressure,
            'status_drift' => $statusDrift,
            'triage_queue' => $triageQueue,
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

            if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $matches)) {
                foreach ($matches[1] as $name) {
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

            if (preg_match('/\b(legacy|deprecated|scaffold|planned_scaffold|executed_scaffold|duplicate|todo|fixme)\b/i', $content, $signalMatch)) {
                $legacySignals[] = [
                    'path' => $path,
                    'signal' => strtolower($signalMatch[1]),
                ];
            }
        }

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
            'ai_service_hotspots' => $this->aiServiceHotspots($aiServiceRecords, $legacySignals),
        ];
    }

    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $code
     * @return array<string,mixed>
     */
    private function statusDriftInventory(array $docs, array $code): array
    {
        $commandSignatures = array_values(array_unique(array_map(
            static fn (array $command): string => (string) ($command['signature'] ?? ''),
            (array) ($code['artisan_commands'] ?? [])
        )));
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

            if ($isPlannedLikeStatus && $hasExistingCode) {
                $plannedOrFutureWithExistingCode++;
            }
            if ($hasScaffoldLanguage && $hasExistingCode) {
                $scaffoldLanguageWithExistingCode++;
            }

            $reason = null;
            $severity = 'review';
            $boundaryContract = $this->statusDriftBoundaryContract($docId);
            if ($isPlannedLikeStatus && $hasExistingCode) {
                if ($boundaryContract !== null) {
                    $reason = 'planned_or_future_status_references_existing_dependency_boundary';
                    $severity = 'low';
                    $plannedFutureBoundaryExplained++;
                } else {
                    $reason = 'frontmatter_status_may_understate_existing_code';
                    $severity = $evidenceScore >= 8 ? 'high' : 'medium';
                }
            } elseif ($hasScaffoldLanguage && $hasExistingCode && $hasImplementedLanguage) {
                $reason = 'body_mixes_scaffold_and_implemented_language_with_code_evidence';
                $severity = $evidenceScore >= 8 ? 'medium' : 'review';
            } elseif ($hasScaffoldLanguage && $evidenceScore >= 8) {
                $reason = 'scaffold_language_has_strong_code_evidence';
                $severity = 'medium';
            }

            if ($reason === null) {
                continue;
            }

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

    /**
     * @param  array<int,string>  $commandSignatures
     * @return array<string,mixed>
     */
    private function docImplementationEvidence(string $content, array $commandSignatures): array
    {
        preg_match_all('/\b(?:app|routes|config|database|tests)\/[A-Za-z0-9_\/.\-]+/', $content, $pathMatches);
        $pathRefs = array_values(array_unique($pathMatches[0] ?? []));
        $existingPathRefs = array_values(array_filter(
            $pathRefs,
            static fn (string $path): bool => File::exists(base_path($path))
        ));
        preg_match_all('/php artisan\s+([a-z0-9:._-]+)/i', $content, $commandMatches);
        $commandRefs = array_values(array_unique($commandMatches[1] ?? []));
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
        $scaffoldTerms = ['scaffold', 'planned', 'future', 'not implemented', 'nao implementado', 'falta ', 'missing'];
        $implementedTerms = ['implemented_ready', 'implemented_partial', 'active_runtime', 'codigo/teste', 'tests existem', 'rotas', 'comando', 'runtime'];

        return [
            'has_scaffold_language' => $this->containsAny($lower, $scaffoldTerms),
            'has_implemented_language' => $this->containsAny($lower, $implementedTerms),
            'policy' => 'language_is_heuristic_confirm_with_owner_doc_and_reachability',
        ];
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
                        'signals' => array_values(array_unique($legacyByPath[$path])),
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
                            'signals' => array_values(array_unique($legacyByPath[$path])),
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
            if (is_string($name) && $name !== '') {
                $namedRoutes[] = [
                    'name' => $name,
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
            $sampleItems = (array) ($group['sample_items'] ?? []);
            $methodUris = array_values(array_unique(array_map(
                static fn (array $item): string => (string) ($item['method_uri'] ?? ''),
                $sampleItems
            )));
            $mobileAlias = count($methodUris) > 1 && collect($methodUris)->contains(
                static fn (string $uri): bool => str_contains($uri, '/v1/mobile/')
            );
            $items[] = [
                'id' => 'route_action_alias:'.$action,
                'kind' => 'runtime_route_action_alias',
                'severity' => $mobileAlias ? 'low' : 'medium',
                'status' => $mobileAlias ? 'document_intentional_alias_or_wrapper_boundary' : 'owner_review_required',
                'action' => $action,
                'method_uris' => $methodUris,
                'boundary_contract' => $this->routeActionAliasBoundaryContract($action, $methodUris, $mobileAlias),
                'why_it_can_confuse_ai' => 'same_controller_action_is_exposed_through_multiple_urls',
                'required_decision' => $mobileAlias ? 'document_as_mobile_alias|split_mobile_wrapper|deprecate_one_path' : 'document_alias_or_merge_paths',
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
     * @param  array<int,string>  $methodUris
     * @return array<string,mixed>
     */
    private function routeActionAliasBoundaryContract(string $action, array $methodUris, bool $mobileAlias): array
    {
        if ($action === 'app.http.controllers.atlascodeobservedsessioncontroller@import') {
            return [
                'canonical_owner' => 'atlas_code_observed_session_import',
                'primary_route' => 'post:/atlas-code/works/{project}/observed-sessions/{session}/import',
                'alias_routes' => array_values(array_diff($methodUris, [
                    'post:/atlas-code/works/{project}/observed-sessions/{session}/import',
                ])),
                'allowed_direction' => 'keep_import_result_only_as_documented_backward_compatibility_or_replace_it_with_explicit_redirect/deprecation_plan',
                'forbidden' => 'do_not_add_third_import_endpoint_or_choose_alias_without_owner_decision',
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
                'canonical_owner' => 'base_api_controller_action',
                'primary_routes' => $baseRoutes,
                'alias_routes' => $mobileRoutes,
                'allowed_direction' => 'mobile_route_may_alias_base_api_when_payload_auth_and_response_contract_are_identical',
                'forbidden' => 'do_not_implement_separate_mobile_business_logic_inside_same_action_without_wrapper_or_owner_doc',
            ];
        }

        return [
            'canonical_owner' => 'route_owner_review_required',
            'primary_routes' => $methodUris,
            'alias_routes' => [],
            'allowed_direction' => 'owner_must_document_whether_paths_are_backward_compatibility_aliases_or_should_be_merged',
            'forbidden' => 'do_not_create_new_route_for_same_action_before_alias_review',
        ];
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
                'decision' => 'prefer_explicit_names_or_adapter_over_silent_consolidation',
                'safe_next_action' => 'prove_semantic_docs_parser_and_vault_note_parser_contracts_with_tests_before_renaming_or_consolidating',
                'rename_candidate' => 'App\\Services\\Vault\\FrontmatterParser',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'verificationcommandrunner' => [
                'priority' => 3,
                'decision' => 'rename_interface_or_document_interface_boundary',
                'safe_next_action' => 'prefer_renaming_atlas_dev_gate_interface_after_container_binding_and_test_reachability_are_proved',
                'rename_candidate' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Gate\\VerificationCommandRunner',
                'delete_allowed' => false,
                'merge_allowed_without_owner_decision' => false,
            ],
            'aiexecutionplan' => [
                'priority' => 4,
                'decision' => 'preserve_model_contract_and_consider_value_object_rename',
                'safe_next_action' => 'rename_value_object_only_after_prompt_builder_and_kernel_static_scanner_references_are_updated_together',
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
                'specialized_variants' => [
                    'app/Services/Ai/Programming/AtlasDev/Gate/VerificationCommandRunner.php',
                ],
                'allowed_direction' => 'atlas_code_concrete_runner_executes_verification; atlas_dev_gate_contract_describes_runner_boundary',
                'forbidden' => 'do_not_swap_interface_and_concrete_runner_by_short_class_name',
            ],
            'frontmatterparser' => [
                'canonical_owner' => 'semantic_docs_parser_vs_vault_parser',
                'primary_runtime' => 'app/Services/Semantic/FrontmatterParser.php',
                'specialized_variants' => [
                    'app/Services/Vault/FrontmatterParser.php',
                ],
                'allowed_direction' => 'semantic_parser_governs_engineering_docs; vault_parser_reads_vault_note_shape',
                'forbidden' => 'do_not_use_vault_parser_as_canonical_engineering_doc_parser',
            ],
            'aiexecutionplan' => [
                'canonical_owner' => 'persistent_model_vs_prompt_value_object',
                'primary_runtime' => 'app/Models/AiExecutionPlan.php',
                'specialized_variants' => [
                    'app/Services/Ai/ValueObjects/AiExecutionPlan.php',
                ],
                'allowed_direction' => 'model_represents_database_contract; value_object_represents_prompt_runtime_payload',
                'forbidden' => 'do_not_typehint_value_object_when_database_model_contract_is_required',
            ],
            'smokesubject' => [
                'canonical_owner' => 'generated_smoke_fixture',
                'primary_runtime' => 'generated_fixture_inside_smoke_workspace',
                'specialized_variants' => [
                    'app/Console/Commands/AtlasDevSeniorLoopAuditCommand.php',
                    'app/Console/Commands/AtlasDevDesktopRealSmokeCommand.php',
                    'app/Console/Commands/AtlasDevSeniorLoopRunCommand.php',
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
            $flowFamilies = $this->flowFamilyGroups($codeMatches);
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
                'source_material_samples' => array_slice(array_map(static fn (array $doc): string => (string) $doc['path'], $sourceMaterialDocs), 0, 12),
                'noncanonical_active_samples' => array_slice(array_map(static fn (array $doc): string => (string) $doc['path'], $nonCanonicalActiveDocs), 0, 12),
                'code_samples' => array_slice($codeMatches, 0, 12),
                'code_subareas' => $this->codeMatchSubareas($codeMatches),
                'code_roles' => $this->codeRoleGroups($codeMatches),
                'flow_families' => $flowFamilies,
                'flow_family_review_queue' => $this->flowFamilyReviewQueue($id, $flowFamilies),
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

        $items = [];
        foreach ($groups as $key => $paths) {
            $items[] = [
                'value' => $key,
                'count' => count($paths),
                'samples' => array_slice($paths, 0, 6),
            ];
        }
        usort($items, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return $items;
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
    private function flowFamilyGroups(array $matches): array
    {
        $groups = [];
        foreach ($matches as $path) {
            $family = $this->flowFamilyForPath($path);
            if ($family === null) {
                continue;
            }
            $groups[$family][] = $path;
        }

        return $this->formatPathCountGroups($groups);
    }

    private function flowFamilyForPath(string $path): ?string
    {
        $basename = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $normalized = strtolower(str_replace(['-', '_', '/', '\\'], '', $path));

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
                'local_rag', 'context_ranking_rerank', 'retrieval_feedback', 'context_pack' => 'medium',
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
                'allowed_direction' => 'context_orchestrates_embedding_policy_semantic_service_provides_primitive_embedding',
                'forbidden' => 'semantic_service_must_not_bypass_context_policy_privacy_or_runtime_boundary',
            ],
            'retrieval_feedback' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md',
                'owner_runtime' => 'app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php',
                'adapter_or_consumer' => 'app/Services/Ai/Compounding/AtlasRagFeedbackService.php',
                'allowed_direction' => 'context_records_retrieval_feedback_compounding_consumes_or_distills_learning',
                'forbidden' => 'compounding_must_not_create_parallel_retrieval_feedback_schema_or_owner',
            ],
            'context_pack' => [
                'canonical_owner' => 'docs/engineering-knowledge-base/memory/retrieval-and-context.md',
                'owner_runtime' => 'app/Services/Ai/AiContextPackBuilder.php',
                'adapter_or_consumer' => 'app/Services/Ai/Programming/ProgrammingContextPackStore.php',
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
     * @param  array<string,array<int,string>>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function formatPathCountGroups(array $groups): array
    {
        $items = [];
        foreach ($groups as $key => $paths) {
            $items[] = [
                'value' => $key,
                'count' => count($paths),
                'samples' => array_slice(array_values($paths), 0, 8),
            ];
        }
        usort($items, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return $items;
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
            static fn (array $group, string $value): ?array => count($group) > 1 ? [
                'value' => $value,
                'count' => count($group),
                'paths' => array_values(array_unique(array_map(static fn (array $item): string => (string) ($item['path'] ?? ''), $group))),
                'sample_items' => array_slice($group, 0, 5),
            ] : null,
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

        return array_values(array_unique($matches));
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
        return array_values(array_unique(array_merge(
            $this->scanFor($needle, $basename, ['routes']),
            array_values(array_filter($this->scanFor($needle, $basename, ['app/Console/Commands']), static fn (string $path): bool => str_contains($path, 'Commands/'))),
        )));
    }

    /**
     * @param  array<int,string>  $references
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $entrypoints
     * @return array<string,mixed>
     */
    private function reachabilityEnvelope(?string $targetPath, array $references, array $ownerDocs, array $tests, array $entrypoints): array
    {
        $breakdown = $this->sourceBreakdown($references, $ownerDocs, $tests, $entrypoints);
        $signals = [
            'target_exists' => $targetPath !== null,
            'has_route_entrypoint' => $breakdown['routes']['count'] > 0,
            'has_command_entrypoint' => $breakdown['commands']['count'] > 0,
            'has_test_coverage' => $breakdown['tests']['count'] > 0,
            'has_owner_doc' => $breakdown['owner_docs']['count'] > 0,
            'has_service_or_code_callers' => $breakdown['code_callers']['count'] > 0,
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
     * @return array<string,array{count:int,paths:array<int,string>}>
     */
    private function sourceBreakdown(array $references, array $ownerDocs, array $tests, array $entrypoints): array
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
     * @param  array<int,string>  $roots
     * @return array<int,string>
     */
    private function scanFor(string $needle, string $basename, array $roots): array
    {
        $terms = array_values(array_filter(array_unique([
            $needle,
            $basename,
            pathinfo($basename, PATHINFO_FILENAME),
            class_basename($needle),
        ]), static fn (string $term): bool => $term !== ''));
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

        return array_values(array_unique($matches));
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
        $confidence = (string) ($reachability['confidence'] ?? 'review_required');

        if ($hasEntrypoint && $hasTests && $hasOwner) {
            return 'active_runtime';
        }

        if ($hasEntrypoint || ($hasTests && $hasOwner) || ($hasCodeCallers && $hasTests)) {
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
