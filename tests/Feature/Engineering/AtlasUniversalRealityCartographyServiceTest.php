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

        $this->assertSame('Universe', $nodes->get('universe')['label']);
        $this->assertSame('organization', $nodes->get('org.atlas')['semantic_level']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-documentation-reality-system.md', $nodes->get('system.adrs')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', $nodes->get('system.acrui')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-universal-reality-cartography.md', $nodes->get('system.aurc')['source_path']);
        $this->assertSame('app/Services/Engineering/AtlasUniversalRealityCartographyService.php', $nodes->get('component.aurc-runtime')['source_path']);
        $this->assertSame('zoom_to_children', $nodes->get('system.aurc')['semantic_zoom']['tap_action']);
        $this->assertSame('open_source_and_tests', $nodes->get('component.aurc-runtime')['human_modal']['next_action']);
    }

    public function test_visual_scene_keeps_map_under_cognitive_budget_and_links_real_nodes(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('implementation');
        $nodeIds = collect($payload['nodes'])->pluck('id')->all();
        $scene = $payload['visual_scene'];

        $this->assertSame('implementation', $scene['mode']);
        $this->assertSame('ready', $scene['status']);
        $this->assertSame('labels_only_on_map_dense_text_in_human_modal', $scene['cognitive_budget']['text_policy']);
        $this->assertLessThanOrEqual($scene['cognitive_budget']['max_visible_nodes'], $scene['cognitive_budget']['visible_node_count']);
        $this->assertLessThanOrEqual($scene['cognitive_budget']['max_visible_edges'], $scene['cognitive_budget']['visible_edge_count']);

        foreach ($scene['visible_nodes'] as $node) {
            $this->assertContains($node['id'], $nodeIds);
            $this->assertArrayHasKey('visual_state', $node);
            $this->assertArrayHasKey('semantic_zoom', $node);
            $this->assertNotSame('', $node['source_path']);
        }
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

        foreach (['map', 'nodes', 'visual-scene', 'semantic-zoom', 'human-routes', 'task-simulator', 'navigation-slice'] as $action) {
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
