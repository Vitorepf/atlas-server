<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasUniversalRealityCartographyServiceTest extends TestCase
{
    public function test_map_projects_adrs_acrui_and_aurc_as_visual_hierarchy(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $nodes = collect($payload['nodes'])->keyBy('id');

        $this->assertSame(AtlasUniversalRealityCartographyService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('universe', $payload['mode']);
        $this->assertSame('atlas.universal_reality_cartography.workspace_scope.v1', $payload['workspace_scope']['schema_version']);
        $this->assertSame('ready', $payload['workspace_scope']['status']);
        $this->assertSame('atlas', $payload['workspace_scope']['active_workspace_id']);
        $this->assertTrue($payload['workspace_scope']['awis_certified']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['cartography_is_source_of_truth']);
        $this->assertTrue($payload['claim_policy']['canonical_docs_remain_authority']);
        $this->assertSame(0, $payload['coverage_audit']['missing_source_count']);
        $this->assertSame(0, $payload['coverage_audit']['missing_modal_count']);
        $this->assertSame(0, $payload['coverage_audit']['missing_visual_state_count']);
        $this->assertSame(0, $payload['coverage_audit']['missing_semantic_zoom_count']);
        $this->assertSame(0, $payload['coverage_audit']['broken_edge_count']);
        $this->assertSame(1.0, $payload['coverage_audit']['visual_completeness_score']);
        $this->assertSame('atlas.universal_reality_cartography.visual_scene.v1', $payload['visual_scene']['schema_version']);
        $this->assertSame('ready', $payload['visual_scene']['status']);
        $this->assertSame('ready', $payload['visual_scene']['cognitive_budget']['status']);
        $this->assertLessThanOrEqual(12, $payload['visual_scene']['cognitive_budget']['visible_node_count']);
        $this->assertSame('atlas.universal_reality_cartography.semantic_zoom_scenes.v1', $payload['semantic_zoom_scenes']['schema_version']);
        $this->assertSame('ready', $payload['semantic_zoom_scenes']['status']);
        $this->assertSame(0, $payload['semantic_zoom_scenes']['invalid_scene_count']);
        $this->assertSame('atlas.universal_reality_cartography.human_route_map.v1', $payload['human_route_map']['schema_version']);
        $this->assertSame('ready', $payload['human_route_map']['status']);
        $this->assertSame(0, $payload['human_route_map']['invalid_route_count']);
        $this->assertSame(AtlasUniversalRealityCartographyService::HUMAN_CLARITY_SCHEMA_VERSION, $payload['human_clarity']['schema_version']);
        $this->assertSame('ready', $payload['human_clarity']['status']);
        $this->assertGreaterThanOrEqual(9.8, $payload['human_clarity']['score']);
        $this->assertSame('9.8_human_visual_clarity', $payload['human_clarity']['grade']);
        $this->assertTrue($payload['human_clarity']['invariants']['human_understands_macro_flow_before_modal']);
        $this->assertTrue($payload['human_clarity']['invariants']['map_text_is_short_label_only']);

        $this->assertSame('Universe', $nodes->get('universe')['label']);
        $this->assertSame('organization', $nodes->get('org.atlas')['semantic_level']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-documentation-reality-system.md', $nodes->get('system.adrs')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', $nodes->get('system.acrui')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-universal-reality-cartography.md', $nodes->get('system.aurc')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md', $nodes->get('system.awis')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md', $nodes->get('system.awtr')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md', $nodes->get('system.awco')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md', $nodes->get('system.awef')['source_path']);
        $this->assertSame('app/Services/Engineering/AtlasUniversalRealityCartographyService.php', $nodes->get('component.aurc-runtime')['source_path']);
        $this->assertSame('zoom_to_children', $nodes->get('system.aurc')['semantic_zoom']['tap_action']);
        $this->assertSame('zoom_to_children', $nodes->get('system.awis')['semantic_zoom']['tap_action']);
        $this->assertSame('open_source_and_tests', $nodes->get('component.aurc-runtime')['human_modal']['next_action']);
    }

    public function test_visual_scene_keeps_map_under_cognitive_budget_and_links_real_nodes(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('implementation');
        $nodeIds = collect($payload['nodes'])->pluck('id')->all();
        $scene = $payload['visual_scene'];

        $this->assertSame('implementation', $scene['mode']);
        $this->assertSame('ready', $scene['status']);
        $this->assertSame('atlas', $scene['workspace_scope']['active_workspace_id']);
        $this->assertSame('workspace', $scene['workspace_scope']['cartography_scope']);
        $this->assertSame('labels_only_on_map_dense_text_in_human_modal', $scene['cognitive_budget']['text_policy']);
        $this->assertSame('semantic_lanes_left_to_right', $scene['viewport']['layout']);
        $this->assertSame('ready', $scene['breadcrumb']['status']);
        $this->assertSame('ready', $scene['legend']['status']);
        $this->assertLessThanOrEqual($scene['cognitive_budget']['max_visible_nodes'], $scene['cognitive_budget']['visible_node_count']);
        $this->assertLessThanOrEqual($scene['cognitive_budget']['max_visible_edges'], $scene['cognitive_budget']['visible_edge_count']);

        foreach ($scene['visible_nodes'] as $node) {
            $this->assertContains($node['id'], $nodeIds);
            $this->assertArrayHasKey('visual_state', $node);
            $this->assertArrayHasKey('semantic_zoom', $node);
            $this->assertArrayHasKey('layout', $node);
            $this->assertArrayHasKey('microcopy', $node);
            $this->assertLessThanOrEqual(28, mb_strlen((string) $node['microcopy']['label_short']));
            $this->assertLessThanOrEqual(96, mb_strlen((string) $node['microcopy']['tooltip']));
            $this->assertNotSame('', $node['source_path']);
        }
    }

    public function test_human_clarity_contract_reaches_9_8_with_visual_first_invariants(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow');
        $clarity = $payload['human_clarity'];
        $dimensions = collect($clarity['dimensions'])->keyBy('id');

        $this->assertSame(AtlasUniversalRealityCartographyService::HUMAN_CLARITY_SCHEMA_VERSION, $clarity['schema_version']);
        $this->assertSame('ready', $clarity['status']);
        $this->assertGreaterThanOrEqual(9.8, $clarity['score']);
        $this->assertSame(9.8, $clarity['target_score']);
        $this->assertSame(7, $dimensions->count());
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('visual_hierarchy')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('cognitive_load')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('source_truth')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('semantic_zoom')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('human_wayfinding')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('nontechnical_microcopy')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('task_simulation')['score']);
        $this->assertTrue($clarity['invariants']['visual_truth_never_overrides_canonical_docs']);
        $this->assertTrue($clarity['invariants']['all_routes_have_sources']);
        $this->assertContains('start_at_universe', $clarity['recommended_operator_use']);
    }

    public function test_cartography_accepts_workspace_scope_without_becoming_source_of_truth(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');

        $this->assertSame('atlas', $payload['workspace_scope']['requested_workspace']);
        $this->assertSame('atlas', $payload['workspace_scope']['active_workspace_id']);
        $this->assertSame('ready', $payload['workspace_scope']['status']);
        $this->assertSame($payload['workspace_scope'], $payload['visual_scene']['workspace_scope']);
        $this->assertTrue($payload['claim_policy']['workspace_scope_is_projection_not_source_of_truth']);
        $this->assertFalse($payload['claim_policy']['cartography_is_source_of_truth']);
    }

    public function test_semantic_zoom_and_human_routes_are_validated_against_node_graph(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $nodeIds = collect($payload['nodes'])->pluck('id')->all();

        foreach ($payload['semantic_zoom_scenes']['scenes'] as $scene) {
            $this->assertContains($scene['from_node'], $nodeIds, $scene['id']);
            foreach ($scene['expected_children'] as $child) {
                $this->assertContains($child, $nodeIds, $scene['id']);
            }
        }

        foreach ($payload['human_route_map']['routes'] as $route) {
            foreach ($route['node_path'] as $nodeId) {
                $this->assertContains($nodeId, $nodeIds, $route['id']);
            }
            $this->assertIsString($route['expected_source']);
            $this->assertNotSame('', $route['expected_source']);
        }
    }

    public function test_edges_only_reference_existing_nodes(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $ids = collect($payload['nodes'])->pluck('id')->all();

        foreach ($payload['edges'] as $edge) {
            $this->assertContains($edge['source'], $ids, $edge['id']);
            $this->assertContains($edge['target'], $ids, $edge['id']);
            $this->assertSame('active', $edge['status']);
        }
    }

    public function test_task_simulator_and_navigation_slice_are_provider_safe(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('implementation');

        $this->assertSame('implementation', $payload['mode']);
        $this->assertSame('ready', $payload['task_simulator']['status']);
        $this->assertSame('ready', $payload['ai_navigation_slice']['status']);
        $this->assertTrue($payload['ai_navigation_slice']['provider_safe']);
        $this->assertContains('system.adrs', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('system.awis', $payload['task_simulator']['node_ids_available']);
        $this->assertSame('use_cartography_as_navigation_slice_not_as_primary_truth', $payload['ai_navigation_slice']['rule']);

        foreach ($payload['ai_navigation_slice']['nodes'] as $node) {
            $this->assertArrayHasKey('id', $node);
            $this->assertArrayHasKey('source_path', $node);
            $this->assertArrayNotHasKey('human_modal', $node);
        }
    }

    public function test_cli_actions_emit_json(): void
    {
        $commandPath = 'app/Console/Commands/AtlasUniversalRealityCartographyCommand.php';
        $this->assertStringEndsWith('AtlasUniversalRealityCartographyCommand.php', $commandPath);

        foreach (['map', 'nodes', 'visual-scene', 'semantic-zoom', 'human-routes', 'task-simulator', 'human-clarity', 'navigation-slice'] as $action) {
            $exit = Artisan::call('atlas:universal-reality-cartography', [
                'action' => $action,
                '--mode' => 'universe',
                '--json' => true,
                '--strict' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, $action);
            $this->assertSame(AtlasUniversalRealityCartographyService::SCHEMA_VERSION, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse($payload['writes']);
        }
    }
}
