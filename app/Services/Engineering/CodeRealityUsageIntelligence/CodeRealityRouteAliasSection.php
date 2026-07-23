<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;

use Illuminate\Support\Facades\Route;

final class CodeRealityRouteAliasSection
{
    public function __construct(
        private readonly CodeRealityPrimitives $primitives,
    ) {}


    /**
     * @return array{routes:array<int,array<string,string>>,named_routes:array<int,array<string,string>>,route_actions:array<int,array<string,string>>}
     */
    public function routeInventoryForContent(string $content, string $path): array
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
    public function runtimeRouteInventory(): array
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
     * @param  array<int,array<string,mixed>>  $runtimeRouteActionAliasGroups
     * @return array<int,array<string,mixed>>
     */
    public function runtimeRouteAliasCleanupQueue(array $runtimeRouteActionAliasGroups, bool $documentedBoundary = false): array
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
    public function confirmedStaticRouteDuplicateGroups(array $routeDuplicateGroups, array $runtimeRouteDuplicateGroups): array
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
    public function prefixBlindStaticRouteDuplicateGroups(array $routeDuplicateGroups, array $confirmedRouteDuplicateGroups): array
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
    public function annotatedStaticRouteActionAliasGroups(array $routeActionAliasGroups, array $confirmedRouteDuplicateGroups): array
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
    public function documentedStaticRouteActionAliasGroups(array $staticRouteActionAliasGroups): array
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
    public function reviewStaticRouteActionAliasGroups(array $staticRouteActionAliasGroups): array
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
    public function runtimeRootApiCompatibilityAliasGroups(array $runtimeRouteActionAliasGroups): array
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
    public function runtimeDocumentedRouteActionAliasGroups(array $runtimeRouteActionAliasGroups): array
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
    public function runtimeReviewRouteActionAliasGroups(array $runtimeRouteActionAliasGroups): array
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
    public function duplicateGroupMethodUris(array $group): array
    {
        $methodUris = (array) ($group['method_uris'] ?? []);
        if ($methodUris !== []) {
            return $this->primitives->uniqueStrings($methodUris, filterEmpty: true);
        }

        return $this->primitives->itemStringColumn((array) ($group['sample_items'] ?? []), 'method_uri', filterEmpty: true);
    }

    /**
     * @param  array<int,string>  $methodUris
     */
    public function mobileRouteActionAlias(array $methodUris): bool
    {
        return count($methodUris) > 1 && collect($methodUris)->contains(
            static fn (string $uri): bool => str_contains($uri, '/v1/mobile/')
        );
    }

    /**
     * @param  array<int,string>  $methodUris
     */
    public function rootApiCompatibilityAlias(array $methodUris): bool
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
     * @param  array<int,string>  $methodUris
     * @return array<string,mixed>
     */
    public function routeActionAliasBoundaryContract(string $action, array $methodUris, bool $mobileAlias, bool $rootApiAlias = false): array
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

    private function normalizeRoutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        return '/'.trim($path, '/');
    }
}
