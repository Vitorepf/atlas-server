<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Support\Arr;

final class AtlasUniversalRealityCartographyService
{
    public const SCHEMA_VERSION = 'atlas.universal_reality_cartography.v1';

    public function __construct(
        private readonly AtlasDocumentationRealitySystemService $documentationReality,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function map(string $mode = 'universe'): array
    {
        $adrs = $this->documentationReality->report();
        $acrui = $this->codeReality->classify('app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php');
        $nodes = $this->nodes($adrs, $acrui);
        $edges = $this->edges();
        $coverage = $this->coverage($nodes, $edges);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $coverage['missing_source_count'] === 0 && $coverage['missing_modal_count'] === 0 ? 'ready' : 'review',
            'mode' => $this->mode($mode),
            'summary' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'semantic_levels' => ['universe', 'organization', 'project', 'system', 'flow', 'component', 'evidence'],
                'visual_first_contract' => 'human_should_understand_macro_flow_from_nodes_edges_state_before_reading_modal',
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'coverage_audit' => $coverage,
            'visual_scene' => $this->visualScene($nodes, $edges, $this->mode($mode)),
            'semantic_zoom_scenes' => $this->semanticZoomScenes($nodes),
            'human_route_map' => $this->humanRouteMap($nodes),
            'task_simulator' => $this->taskSimulator($nodes),
            'ai_navigation_slice' => $this->aiNavigationSlice($nodes),
            'claim_policy' => [
                'cartography_is_source_of_truth' => false,
                'canonical_docs_remain_authority' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'writes' => false,
                'external_project_docs_copied' => false,
            ],
            'writes' => false,
        ];

        $payload['cartography_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $adrs
     * @param  array<string,mixed>  $acrui
     * @return array<int,array<string,mixed>>
     */
    private function nodes(array $adrs, array $acrui): array
    {
        return [
            $this->node(
                'universe',
                'universe',
                'Universe',
                'universe',
                'active',
                'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                'atlas-cartography',
                ['universe', 'organization'],
                ['Atlas', 'external_projects_boundary'],
                'Mapa global de organizacoes, projetos e fronteiras de verdade.'
            ),
            $this->node(
                'org.atlas',
                'organization',
                'Atlas',
                'organization',
                'active',
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['project'],
                ['ADRS', 'ACRUI', 'AURC'],
                'Organizacao/plataforma Atlas. A verdade canonica fica nos repo docs do Atlas.'
            ),
            $this->node(
                'project.atlas.documentation-reality',
                'project',
                'Documentation Reality',
                'project',
                (string) ($adrs['status'] ?? 'unknown'),
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['system'],
                ['52 ADRS blocks', 'ADRS runtime score', 'ACRUI child', 'AURC child'],
                'Area mais critica de organizacao: docs canonicos, realidade operacional e Cartografia humana.'
            ),
            $this->node(
                'system.adrs',
                'system',
                'ADRS',
                'system',
                (string) ($adrs['status'] ?? 'unknown'),
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['flow', 'component'],
                [
                    'php artisan atlas:documentation-reality score --strict --json',
                    'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
                ],
                'Doc mae e runtime integrado que materializa os 52 blocos da area.'
            ),
            $this->node(
                'system.acrui',
                'system',
                'ACRUI',
                'system',
                (string) ($acrui['classification'] ?? 'unknown_requires_audit'),
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                'architecture-audit',
                ['flow', 'component'],
                [
                    'php artisan atlas:code-reality classify --target=<target> --json',
                    'tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php',
                ],
                'Filho operacional do ADRS que classifica realidade de codigo/docs de forma conservadora.'
            ),
            $this->node(
                'system.aurc',
                'system',
                'AURC',
                'system',
                'active_read_only',
                'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                'atlas-cartography',
                ['flow', 'component'],
                [
                    'php artisan atlas:universal-reality-cartography map --strict --json',
                    'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php',
                ],
                'Filha visual do ADRS que projeta a verdade em mapa navegavel por humano e IA.'
            ),
            $this->node(
                'flow.adrs-to-acrui-to-aurc',
                'flow',
                'ADRS -> ACRUI -> AURC',
                'flow',
                'active',
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['component', 'evidence'],
                ['ADRS authority', 'ACRUI operational classification', 'AURC visual projection'],
                'Fluxo central: ADRS decide autoridade, ACRUI prova realidade, AURC mostra para humano.'
            ),
            $this->node(
                'component.adrs-runtime',
                'component',
                'AtlasDocumentationRealitySystemService',
                'component',
                'active_runtime',
                'app/Services/Engineering/AtlasDocumentationRealitySystemService.php',
                'documentation-governance',
                ['evidence'],
                ['tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php'],
                'Runtime read-only integrado que gera score, readiness, evaluations e integration evidence.'
            ),
            $this->node(
                'component.acrui-runtime',
                'component',
                'AtlasCodeRealityUsageIntelligenceService',
                'component',
                (string) ($acrui['classification'] ?? 'unknown_requires_audit'),
                'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                'architecture-audit',
                ['evidence'],
                ['tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php'],
                'Runtime read-only que classifica target, usage-map, anti-duplicate e dead-code candidates sem deletar.'
            ),
            $this->node(
                'component.aurc-runtime',
                'component',
                'AtlasUniversalRealityCartographyService',
                'component',
                'active_read_only',
                'app/Services/Engineering/AtlasUniversalRealityCartographyService.php',
                'atlas-cartography',
                ['evidence'],
                ['tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php'],
                'Runtime read-only que emite visual nodes, edges, coverage audit e navigation slice.'
            ),
            $this->node(
                'evidence.adrs-gates',
                'evidence',
                'ADRS Gates',
                'evidence',
                'ready',
                'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
                'documentation-governance',
                [],
                [
                    'php artisan atlas:engineering:knowledge docs-health --json',
                    'php artisan atlas:ai:architecture-validate --json',
                    'git diff --check',
                ],
                'Provas mecanicas que sustentam o mapa; Cartografia nao e prova primaria.'
            ),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function edges(): array
    {
        return [
            $this->edge('universe', 'org.atlas', 'contains'),
            $this->edge('org.atlas', 'project.atlas.documentation-reality', 'owns'),
            $this->edge('project.atlas.documentation-reality', 'system.adrs', 'governs'),
            $this->edge('project.atlas.documentation-reality', 'system.acrui', 'contains_child'),
            $this->edge('project.atlas.documentation-reality', 'system.aurc', 'contains_child'),
            $this->edge('system.adrs', 'flow.adrs-to-acrui-to-aurc', 'defines_flow'),
            $this->edge('flow.adrs-to-acrui-to-aurc', 'system.acrui', 'uses_operational_truth'),
            $this->edge('flow.adrs-to-acrui-to-aurc', 'system.aurc', 'projects_visual_truth'),
            $this->edge('system.adrs', 'component.adrs-runtime', 'implemented_by'),
            $this->edge('system.acrui', 'component.acrui-runtime', 'implemented_by'),
            $this->edge('system.aurc', 'component.aurc-runtime', 'implemented_by'),
            $this->edge('component.adrs-runtime', 'evidence.adrs-gates', 'proved_by'),
            $this->edge('component.acrui-runtime', 'evidence.adrs-gates', 'proved_by'),
            $this->edge('component.aurc-runtime', 'evidence.adrs-gates', 'proved_by'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function node(
        string $id,
        string $kind,
        string $label,
        string $semanticLevel,
        string $status,
        string $sourcePath,
        string $owner,
        array $zoomTargets,
        array $evidenceRefs,
        string $humanSummary
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'semantic_level' => $semanticLevel,
            'status' => $status,
            'source_path' => $sourcePath,
            'owner' => $owner,
            'evidence_refs' => $evidenceRefs,
            'visual_state' => [
                'status_tone' => $this->statusTone($status),
                'shape' => $this->shape($kind),
                'requires_attention' => in_array($status, ['unknown', 'unknown_requires_audit', 'blocked', 'review'], true),
            ],
            'semantic_zoom' => [
                'tap_action' => $zoomTargets === [] ? 'open_evidence' : 'zoom_to_children',
                'targets' => $zoomTargets,
            ],
            'human_modal' => [
                'title' => $label,
                'summary' => $humanSummary,
                'source_path' => $sourcePath,
                'owner' => $owner,
                'status' => $status,
                'proof' => $evidenceRefs,
                'next_action' => $this->nextAction($kind),
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function edge(string $source, string $target, string $type): array
    {
        return [
            'id' => $source.'->'.$target,
            'source' => $source,
            'target' => $target,
            'type' => $type,
            'status' => 'active',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    private function coverage(array $nodes, array $edges): array
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
    private function visualScene(array $nodes, array $edges, string $mode): array
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
            'cognitive_budget' => [
                'max_visible_nodes' => 12,
                'max_visible_edges' => 16,
                'visible_node_count' => count($visibleNodes),
                'visible_edge_count' => count($visibleEdges),
                'text_policy' => 'labels_only_on_map_dense_text_in_human_modal',
                'status' => count($visibleNodes) <= 12 && count($visibleEdges) <= 16 ? 'ready' : 'review',
            ],
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
                'semantic_zoom',
            ]), $visibleNodes),
            'visible_edges' => $visibleEdges,
            'interaction_contract' => [
                'tap' => 'zoom_to_children_or_open_evidence',
                'long_press' => 'open_human_modal',
                'back' => 'return_to_parent_semantic_level',
                'search' => 'route_to_canonical_question_router',
            ],
        ];
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
            'implementation' => ['system', 'flow', 'component', 'evidence'],
        ];

        $levels = $levelsByMode[$mode] ?? $levelsByMode['universe'];

        return array_values(array_filter($nodes, static fn (array $node): bool => in_array((string) $node['semantic_level'], $levels, true)));
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
    private function semanticZoomScenes(array $nodes): array
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
    private function humanRouteMap(array $nodes): array
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
        ];

        $invalid = array_values(array_filter($routes, static fn (array $route): bool => collect($route['node_path'])->contains(
            static fn (string $nodeId): bool => ! in_array($nodeId, $nodeIds, true),
        )));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.human_route_map.v1',
            'status' => $invalid === [] ? 'ready' : 'review',
            'route_count' => count($routes),
            'invalid_route_count' => count($invalid),
            'routes' => $routes,
            'rule' => 'human_can_navigate_by_spatial_path_then_open_modal_for_detail',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function taskSimulator(array $nodes): array
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
            ],
            'node_ids_available' => array_column($nodes, 'id'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function aiNavigationSlice(array $nodes): array
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

    private function mode(string $mode): string
    {
        return in_array($mode, ['universe', 'system', 'flow', 'evidence', 'risk', 'implementation'], true)
            ? $mode
            : 'universe';
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'active', 'active_runtime', 'active_read_only', 'ready' => 'healthy',
            'review', 'unknown_requires_audit', 'unknown' => 'attention',
            'blocked' => 'blocked',
            default => 'neutral',
        };
    }

    private function shape(string $kind): string
    {
        return match ($kind) {
            'universe' => 'sphere',
            'organization' => 'continent',
            'project' => 'region',
            'system' => 'district',
            'flow' => 'path',
            'component' => 'building',
            'evidence' => 'seal',
            default => 'node',
        };
    }

    private function nextAction(string $kind): string
    {
        return match ($kind) {
            'universe', 'organization', 'project' => 'zoom_to_next_semantic_level',
            'system', 'flow' => 'inspect_components_and_evidence',
            'component' => 'open_source_and_tests',
            'evidence' => 'run_validation_commands',
            default => 'inspect_source',
        };
    }
}
