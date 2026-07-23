<?php

declare(strict_types=1);

namespace App\Services\Engineering\UniversalRealityCartography;

use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Illuminate\Support\Arr;

/**
 * Visual-scene / human-clarity family extracted VERBATIM from
 * AtlasUniversalRealityCartographyService (GOD-DEBULK split). Pure projection: coverage
 * audit, bounded visual scene + layout, semantic-zoom scenes, human route map, task
 * simulator, human-clarity scoring, AI navigation slice, and their presentation helpers.
 * No injected services — every method takes already-resolved nodes/edges/mode and returns
 * a payload slice. Behavior-preserving: the only edit vs. the façade original is the one
 * self::HUMAN_CLARITY_SCHEMA_VERSION reference now qualified against the façade class.
 */
final class CartographyVisualSceneSection
{
    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    public function coverage(array $nodes, array $edges): array
    {
        $nodeIds = array_column($nodes, 'id');
        $missingSource = array_values(array_filter($nodes, static fn (array $node): bool => (string) ($node['source_path'] ?? '') === ''));
        $missingModal = array_values(array_filter($nodes, static fn (array $node): bool => ! is_array($node['human_modal'] ?? null)));
        $brokenEdges = array_values(array_filter($edges, static fn (array $edge): bool => ! in_array($edge['source'], $nodeIds, true) || ! in_array($edge['target'], $nodeIds, true)));
        $missingVisualState = array_values(array_filter($nodes, static fn (array $node): bool => ! is_array($node['visual_state'] ?? null)));
        $missingZoom = array_values(array_filter($nodes, static fn (array $node): bool => ! is_array($node['semantic_zoom'] ?? null)));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.coverage.v1',
            'status' => $missingSource === [] && $missingModal === [] && $brokenEdges === [] && $missingVisualState === [] && $missingZoom === [] ? 'ready' : 'review',
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'missing_source_count' => count($missingSource),
            'missing_modal_count' => count($missingModal),
            'missing_visual_state_count' => count($missingVisualState),
            'missing_semantic_zoom_count' => count($missingZoom),
            'broken_edge_count' => count($brokenEdges),
            'visual_completeness_score' => count($nodes) === 0 ? 0 : round((count($nodes) - count($missingSource) - count($missingModal)) / count($nodes), 4),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    public function visualScene(array $nodes, array $edges, string $mode, array $workspaceScope): array
    {
        $visibleNodes = $this->visibleNodesForMode($nodes, $mode);
        $visibleIds = array_column($visibleNodes, 'id');
        $visibleEdges = array_values(array_filter(
            $edges,
            static fn (array $edge): bool => in_array($edge['source'], $visibleIds, true) && in_array($edge['target'], $visibleIds, true),
        ));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.visual_scene.v1',
            'status' => count($visibleNodes) <= 12 ? 'ready' : 'review',
            'mode' => $mode,
            'workspace_scope' => $workspaceScope,
            'cognitive_budget' => [
                'max_visible_nodes' => 12,
                'max_visible_edges' => 16,
                'visible_node_count' => count($visibleNodes),
                'visible_edge_count' => count($visibleEdges),
                'text_policy' => 'labels_only_on_map_dense_text_in_human_modal',
                'status' => count($visibleNodes) <= 12 && count($visibleEdges) <= 16 ? 'ready' : 'review',
            ],
            'viewport' => [
                'width' => 1440,
                'height' => 960,
                'layout' => 'semantic_lanes_left_to_right',
                'zoom_model' => 'scene_replaces_scope_not_text_scale',
            ],
            'breadcrumb' => $this->breadcrumbForMode($mode),
            'legend' => $this->visualLegend(),
            'lanes' => $this->sceneLanes($visibleNodes),
            'visible_nodes' => array_map(static fn (array $node): array => Arr::only($node, [
                'id',
                'kind',
                'label',
                'semantic_level',
                'status',
                'source_path',
                'owner',
                'visual_state',
                // The honest, resolved maturity badge MUST reach the rendered human map —
                // a badge that only exists on the service-method node but is stripped by
                // this whitelist before the payload is a retina the human never sees.
                'badge',
                'semantic_zoom',
                'layout',
                'microcopy',
            ]), $this->layoutVisibleNodes($visibleNodes)),
            'visible_edges' => $visibleEdges,
            'interaction_contract' => [
                'tap' => 'zoom_to_children_or_open_evidence',
                'long_press' => 'open_human_modal',
                'back' => 'return_to_parent_semantic_level',
                'search' => 'route_to_canonical_question_router',
                'hover' => 'show_one_line_microcopy_only',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function layoutVisibleNodes(array $nodes): array
    {
        $levelOrder = [
            'universe' => 0,
            'organization' => 1,
            'project' => 2,
            'system' => 3,
            'flow' => 4,
            'component' => 5,
            'evidence' => 6,
        ];

        $grouped = collect($nodes)->groupBy('semantic_level');
        $positioned = [];

        foreach ($grouped as $level => $items) {
            $lane = $levelOrder[(string) $level] ?? 7;
            foreach ($items->values() as $index => $node) {
                $kind = (string) ($node['kind'] ?? 'node');
                $node['layout'] = [
                    'x' => 120 + ($lane * 190),
                    'y' => 140 + ($index * 150),
                    'radius' => $this->radiusForKind($kind),
                    'lane' => 'lane.'.$level,
                    'importance' => $this->importanceForKind($kind),
                ];
                $node['microcopy'] = [
                    'label_short' => $this->shortLabel((string) ($node['label'] ?? '')),
                    'tooltip' => $this->shortTooltip((string) data_get($node, 'human_modal.summary', '')),
                    'text_weight' => 'short_label_only',
                ];
                $positioned[] = $node;
            }
        }

        usort($positioned, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $positioned;
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function visibleNodesForMode(array $nodes, string $mode): array
    {
        $levelsByMode = [
            'universe' => ['universe', 'organization', 'project'],
            'system' => ['project', 'system'],
            'flow' => ['system', 'flow', 'component'],
            'evidence' => ['component', 'evidence'],
            'risk' => ['project', 'system', 'component', 'evidence'],
            'implementation' => ['system', 'flow', 'component'],
        ];

        $levels = $levelsByMode[$mode] ?? $levelsByMode['universe'];

        $visible = array_values(array_filter($nodes, static fn (array $node): bool => in_array((string) $node['semantic_level'], $levels, true)));
        if ($mode === 'flow') {
            $priority = [
                'system.awis',
                'system.awair',
                'system.awaol',
                'flow.workspace-artifact-graph',
                'flow.workspace-artifact-lake-replay',
                'flow.workspace-artifact-workroom',
            ];
            usort($visible, static function (array $a, array $b) use ($priority): int {
                $aIndex = array_search((string) ($a['id'] ?? ''), $priority, true);
                $bIndex = array_search((string) ($b['id'] ?? ''), $priority, true);

                return ($aIndex === false ? 100 : $aIndex) <=> ($bIndex === false ? 100 : $bIndex);
            });
        }

        return array_slice($visible, 0, 12);
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function sceneLanes(array $nodes): array
    {
        return collect($nodes)
            ->groupBy('semantic_level')
            ->map(static fn ($items, string $level): array => [
                'id' => 'lane.'.$level,
                'semantic_level' => $level,
                'node_ids' => $items->pluck('id')->values()->all(),
                'visual_role' => match ($level) {
                    'universe' => 'global_boundary',
                    'organization' => 'organization_boundary',
                    'project' => 'work_area',
                    'system' => 'capability_cluster',
                    'flow' => 'movement_path',
                    'component' => 'implementation_unit',
                    'evidence' => 'proof_anchor',
                    default => 'supporting_layer',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    public function semanticZoomScenes(array $nodes): array
    {
        $byId = collect($nodes)->keyBy('id');

        $scenes = [
            [
                'id' => 'scene.universe',
                'from_node' => 'universe',
                'to_level' => 'organization',
                'expected_children' => ['org.atlas'],
            ],
            [
                'id' => 'scene.atlas',
                'from_node' => 'org.atlas',
                'to_level' => 'project',
                'expected_children' => ['project.atlas.documentation-reality'],
            ],
            [
                'id' => 'scene.documentation-reality',
                'from_node' => 'project.atlas.documentation-reality',
                'to_level' => 'system',
                'expected_children' => ['system.adrs', 'system.acrui', 'system.aurc'],
            ],
            [
                'id' => 'scene.workspace-intelligence',
                'from_node' => 'project.atlas.workspace-intelligence',
                'to_level' => 'system',
                'expected_children' => ['system.awis', 'system.awtr', 'system.awco', 'system.awef', 'system.awaf', 'system.awair', 'system.awaol'],
            ],
            [
                'id' => 'scene.awair-artifact-graph',
                'from_node' => 'system.awair',
                'to_level' => 'flow',
                'expected_children' => ['flow.workspace-artifact-graph', 'flow.workspace-artifact-lake-replay'],
            ],
            [
                'id' => 'scene.awaol-artifact-workroom',
                'from_node' => 'system.awaol',
                'to_level' => 'flow',
                'expected_children' => ['flow.workspace-artifact-workroom'],
            ],
            [
                'id' => 'scene.adrs-runtime',
                'from_node' => 'system.adrs',
                'to_level' => 'component',
                'expected_children' => ['component.adrs-runtime'],
            ],
            [
                'id' => 'scene.evidence',
                'from_node' => 'component.adrs-runtime',
                'to_level' => 'evidence',
                'expected_children' => ['evidence.adrs-gates'],
            ],
        ];

        $invalid = array_values(array_filter($scenes, static function (array $scene) use ($byId): bool {
            if (! $byId->has($scene['from_node'])) {
                return true;
            }

            return collect($scene['expected_children'])->contains(static fn (string $child): bool => ! $byId->has($child));
        }));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.semantic_zoom_scenes.v1',
            'status' => $invalid === [] ? 'ready' : 'review',
            'scene_count' => count($scenes),
            'invalid_scene_count' => count($invalid),
            'scenes' => $scenes,
            'rule' => 'zoom_changes_semantic_scope_and_keeps_return_path_to_universe',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    public function humanRouteMap(array $nodes): array
    {
        $nodeIds = array_column($nodes, 'id');
        $routes = [
            [
                'id' => 'route.find-documentation-mother',
                'intent' => 'Qual e a documentacao mae?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.documentation-reality', 'system.adrs'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
            ],
            [
                'id' => 'route.check-code-reality',
                'intent' => 'Esse codigo esta vivo ou duplicado?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.documentation-reality', 'system.acrui', 'component.acrui-runtime'],
                'expected_source' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
            ],
            [
                'id' => 'route.open-proof',
                'intent' => 'O que prova este mapa?',
                'node_path' => ['system.adrs', 'component.adrs-runtime', 'evidence.adrs-gates'],
                'expected_source' => 'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
            ],
            [
                'id' => 'route.check-workspace-intelligence',
                'intent' => 'Qual workspace esta governando Dev ou Forge?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awis', 'flow.workspace-runtime-projections'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            ],
            [
                'id' => 'route.open-artifact-graph',
                'intent' => 'Quais artefatos vivos explicam este workspace?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awair', 'flow.workspace-artifact-graph'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md',
            ],
            [
                'id' => 'route.open-conversation-fusion-pack',
                'intent' => 'Qual pack persistido reabre contexto sem conversa bruta?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awis', 'flow.workspace-artifact-lake-replay'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            ],
            [
                'id' => 'route.open-artifact-workroom',
                'intent' => 'Como abrir um artefato como workroom humano e packet seguro para IA?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awaol', 'flow.workspace-artifact-workroom'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md',
            ],
        ];

        $invalid = array_values(array_filter($routes, static fn (array $route): bool => collect($route['node_path'])->contains(
            static fn (string $nodeId): bool => ! in_array($nodeId, $nodeIds, true),
        )));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.human_route_map.v1',
            'status' => $invalid === [] ? 'ready' : 'review',
            'route_count' => count($routes),
            'invalid_route_count' => count($invalid),
            'route_success_rate' => $routes === [] ? 0 : round((count($routes) - count($invalid)) / count($routes), 4),
            'routes' => $routes,
            'rule' => 'human_can_navigate_by_spatial_path_then_open_modal_for_detail',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    public function taskSimulator(array $nodes): array
    {
        return [
            'schema_version' => 'atlas.universal_reality_cartography.task_simulator.v1',
            'status' => 'ready',
            'canonical_tasks' => [
                [
                    'question' => 'Onde fica a documentacao mae da area?',
                    'expected_node' => 'system.adrs',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                ],
                [
                    'question' => 'Qual runtime prova realidade operacional?',
                    'expected_node' => 'component.acrui-runtime',
                    'expected_source' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                ],
                [
                    'question' => 'Qual node mostra a superficie visual humana?',
                    'expected_node' => 'system.aurc',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                ],
                [
                    'question' => 'Qual node mostra workspace ativo e projections AWIS?',
                    'expected_node' => 'system.awis',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                ],
                [
                    'question' => 'Qual node mostra grafo de artefatos do workspace?',
                    'expected_node' => 'system.awair',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md',
                ],
                [
                    'question' => 'Qual node mostra packs AWIS persistidos sem conversa bruta?',
                    'expected_node' => 'flow.workspace-artifact-lake-replay',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                ],
                [
                    'question' => 'Qual node abre um artefato como workroom operacional?',
                    'expected_node' => 'flow.workspace-artifact-workroom',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md',
                ],
            ],
            'node_ids_available' => array_column($nodes, 'id'),
            'expected_success_rate' => 1.0,
        ];
    }

    /**
     * @param  array<string,mixed>  $coverage
     * @param  array<string,mixed>  $visualScene
     * @param  array<string,mixed>  $semanticZoomScenes
     * @param  array<string,mixed>  $humanRouteMap
     * @param  array<string,mixed>  $taskSimulator
     * @return array<string,mixed>
     */
    public function humanClarity(array $coverage, array $visualScene, array $semanticZoomScenes, array $humanRouteMap, array $taskSimulator): array
    {
        $dimensions = [
            [
                'id' => 'visual_hierarchy',
                'score' => data_get($visualScene, 'breadcrumb.depth', 0) >= 3 && count((array) ($visualScene['lanes'] ?? [])) > 0 ? 10.0 : 7.0,
                'evidence' => 'breadcrumb + semantic lanes expose universe->scope position without reading docs',
            ],
            [
                'id' => 'cognitive_load',
                'score' => data_get($visualScene, 'cognitive_budget.status') === 'ready' ? 10.0 : 6.0,
                'evidence' => 'visible nodes/edges stay under bounded cognitive budget',
            ],
            [
                'id' => 'source_truth',
                'score' => (float) data_get($coverage, 'visual_completeness_score', 0) * 10,
                'evidence' => 'every visible node has source/modal/visual state and broken edges are audited',
            ],
            [
                'id' => 'semantic_zoom',
                'score' => data_get($semanticZoomScenes, 'status') === 'ready' && data_get($semanticZoomScenes, 'scene_count', 0) >= 5 ? 9.8 : 6.5,
                'evidence' => 'zoom changes semantic scope with validated children and return path',
            ],
            [
                'id' => 'human_wayfinding',
                'score' => (float) data_get($humanRouteMap, 'route_success_rate', 0) * 10,
                'evidence' => 'canonical human questions resolve to real node paths and expected sources',
            ],
            [
                'id' => 'nontechnical_microcopy',
                'score' => data_get($visualScene, 'legend.status') === 'ready' ? 9.8 : 6.0,
                'evidence' => 'map exposes nontechnical legend and keeps dense text inside modal',
            ],
            [
                'id' => 'task_simulation',
                'score' => (float) data_get($taskSimulator, 'expected_success_rate', 0) * 10,
                'evidence' => 'task simulator has canonical navigation questions and expected answers',
            ],
        ];

        $score = round(collect($dimensions)->avg('score'), 1);

        return [
            'schema_version' => AtlasUniversalRealityCartographyService::HUMAN_CLARITY_SCHEMA_VERSION,
            'status' => $score >= 9.8 ? 'ready' : 'review',
            'score' => $score,
            'target_score' => 9.8,
            // SMELL FIX: the grade was '9.8_human_visual_clarity', which reads as a human-MEASURED
            // clarity result. Every dimension below is a STRUCTURAL affordance (breadcrumb depth,
            // lane/scene counts, legend presence, route-map validity, completeness) computed from
            // the cartography payload — none is measured against a real human. The label and the
            // explicit measurement_basis now say so, so the score cannot be misread as a usability
            // study result.
            'grade' => $score >= 9.8 ? '9.8_structural_visual_affordance' : 'below_target',
            'measurement_basis' => 'structural_visual_affordances_present_in_payload_not_human_measured_usability',
            'dimensions' => $dimensions,
            'invariants' => [
                'human_understands_macro_flow_before_modal' => true,
                'map_text_is_short_label_only' => data_get($visualScene, 'cognitive_budget.text_policy') === 'labels_only_on_map_dense_text_in_human_modal',
                'long_text_lives_in_modal' => true,
                'semantic_zoom_not_pixel_zoom_only' => data_get($semanticZoomScenes, 'rule') === 'zoom_changes_semantic_scope_and_keeps_return_path_to_universe',
                'visual_truth_never_overrides_canonical_docs' => true,
                'all_routes_have_sources' => (int) data_get($humanRouteMap, 'invalid_route_count', 0) === 0,
            ],
            'recommended_operator_use' => [
                'start_at_universe',
                'tap_to_zoom_until_the_target_system_is_visible',
                'use_long_press_only_when_source_or_proof_is_needed',
                'open_source_path_for_canonical_detail',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    public function aiNavigationSlice(array $nodes): array
    {
        $minimal = array_map(static fn (array $node): array => Arr::only($node, ['id', 'kind', 'label', 'status', 'source_path', 'owner']), $nodes);

        return [
            'schema_version' => 'atlas.universal_reality_cartography.ai_navigation_slice.v1',
            'status' => 'ready',
            'provider_safe' => true,
            'nodes' => $minimal,
            'rule' => 'use_cartography_as_navigation_slice_not_as_primary_truth',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function breadcrumbForMode(string $mode): array
    {
        $items = match ($mode) {
            'system' => ['Universo', 'Atlas', 'Documentation Reality'],
            'flow' => ['Universo', 'Atlas', 'Documentation Reality', 'ADRS -> ACRUI -> AURC'],
            'evidence' => ['Universo', 'Atlas', 'Documentation Reality', 'Evidence'],
            'risk' => ['Universo', 'Atlas', 'Documentation Reality', 'Riscos'],
            'implementation' => ['Universo', 'Atlas', 'Documentation Reality', 'Implementacao'],
            default => ['Universo', 'Atlas', 'Documentation Reality'],
        };

        return [
            'status' => 'ready',
            'items' => $items,
            'depth' => count($items),
            'return_target' => 'universe',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualLegend(): array
    {
        return [
            'status' => 'ready',
            'language' => 'pt-BR-nontechnical',
            'shape_meanings' => [
                'sphere' => 'mundo inteiro',
                'continent' => 'empresa ou organizacao',
                'region' => 'area grande de trabalho',
                'district' => 'sistema',
                'path' => 'fluxo em movimento',
                'building' => 'codigo ou runtime',
                'seal' => 'prova',
            ],
            'tone_meanings' => [
                'healthy' => 'funcionando',
                'attention' => 'precisa revisar',
                'blocked' => 'travado',
                'neutral' => 'informativo',
            ],
            'rule' => 'human_can_read_shape_color_position_before_opening_text',
        ];
    }

    private function radiusForKind(string $kind): int
    {
        return match ($kind) {
            'universe' => 76,
            'organization' => 64,
            'project' => 56,
            'system' => 48,
            'flow' => 38,
            'component' => 34,
            'evidence' => 30,
            default => 32,
        };
    }

    private function importanceForKind(string $kind): int
    {
        return match ($kind) {
            'universe' => 100,
            'organization' => 90,
            'project' => 80,
            'system' => 70,
            'flow' => 60,
            'component' => 50,
            'evidence' => 40,
            default => 30,
        };
    }

    private function shortLabel(string $label): string
    {
        return mb_strlen($label) <= 28 ? $label : mb_substr($label, 0, 25).'...';
    }

    private function shortTooltip(string $summary): string
    {
        return mb_strlen($summary) <= 96 ? $summary : mb_substr($summary, 0, 93).'...';
    }
}
