<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md', $nodes->get('system.awaf')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md', $nodes->get('system.awair')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md', $nodes->get('system.awaol')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md', $nodes->get('flow.workspace-artifact-graph')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md', $nodes->get('flow.workspace-artifact-lake-replay')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md', $nodes->get('flow.workspace-artifact-workroom')['source_path']);
        $this->assertSame('ready', $payload['workspace_scope']['artifact_workroom']['status']);
        $this->assertSame('task_packet', $payload['workspace_scope']['artifact_workroom']['artifact_type']);
        $this->assertSame('dev', $payload['workspace_scope']['artifact_workroom']['route_target']);
        $this->assertFalse($payload['workspace_scope']['artifact_workroom']['source_policy']['raw_conversation_returned']);
        $this->assertFalse($payload['workspace_scope']['artifact_workroom']['source_policy']['artifact_body_returned']);
        $this->assertSame('app/Services/Engineering/AtlasUniversalRealityCartographyService.php', $nodes->get('component.aurc-runtime')['source_path']);
        $this->assertSame('zoom_to_children', $nodes->get('system.aurc')['semantic_zoom']['tap_action']);
        $this->assertSame('zoom_to_children', $nodes->get('system.awis')['semantic_zoom']['tap_action']);
        $this->assertSame('zoom_to_children', $nodes->get('system.awair')['semantic_zoom']['tap_action']);
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
        $visibleIds = collect($payload['visual_scene']['visible_nodes'])->pluck('id')->all();

        $this->assertSame('atlas', $payload['workspace_scope']['requested_workspace']);
        $this->assertSame('atlas', $payload['workspace_scope']['active_workspace_id']);
        $this->assertSame('ready', $payload['workspace_scope']['status']);
        $this->assertSame($payload['workspace_scope'], $payload['visual_scene']['workspace_scope']);
        $this->assertContains('system.awair', $visibleIds);
        $this->assertContains('flow.workspace-artifact-graph', $visibleIds);
        $this->assertArrayHasKey('artifact_lake_replay', $payload['workspace_scope']);
        $this->assertTrue($payload['claim_policy']['workspace_scope_is_projection_not_source_of_truth']);
        $this->assertFalse($payload['claim_policy']['cartography_is_source_of_truth']);
    }

    public function test_cartography_surfaces_artifact_lake_replay_without_artifact_body(): void
    {
        $this->createArtifactLakeTable();
        try {
            $artifactId = Str::uuid()->toString();
            $rawTail = 'RAW_CONVERSATION_TAIL_MUST_NOT_REACH_CARTOGRAPHY';
            DB::table('atlas_workspace_artifact_lake_entries')->insert([
                'id' => $artifactId,
                'workspace_id' => 'atlas',
                'runtime_hash' => str_repeat('a', 64),
                'artifact_hash' => str_repeat('b', 64),
                'artifact_type' => 'conversation_fusion_pack',
                'status' => 'ready',
                'consumer' => 'atlas_dev',
                'source_hashes' => json_encode([str_repeat('c', 64)], JSON_THROW_ON_ERROR),
                'body' => json_encode([
                    'fusion_pack' => [
                        'summary' => $rawTail,
                        'handoff_context' => [
                            'allowed_for_provider_prompt' => true,
                            'recommended_consumers' => ['atlas_dev'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'quality_score' => 9.4,
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
            $replay = $payload['workspace_scope']['artifact_lake_replay'];
            $nodes = collect($payload['nodes'])->keyBy('id');

            $this->assertSame('atlas.universal_reality_cartography.artifact_lake_replay.v1', $replay['schema_version']);
            $this->assertSame('ready', $replay['status']);
            $this->assertSame(1, $replay['artifact_count']);
            $this->assertSame(1, $replay['conversation_fusion_pack_count']);
            $this->assertSame($artifactId, $replay['latest_artifacts'][0]['artifact_id']);
            $this->assertSame('conversation_fusion_pack', $replay['latest_artifacts'][0]['artifact_type']);
            $this->assertFalse((bool) $replay['source_policy']['raw_conversation_returned']);
            $this->assertFalse((bool) $replay['source_policy']['full_message_content_returned']);
            $this->assertArrayNotHasKey('body', $replay['latest_artifacts'][0]);
            $this->assertSame('active_read_only', $nodes->get('flow.workspace-artifact-lake-replay')['status']);
            $this->assertStringNotContainsString($rawTail, json_encode($payload, JSON_THROW_ON_ERROR));
        } finally {
            Schema::dropIfExists('atlas_workspace_artifact_lake_entries');
        }
    }

    public function test_cartography_surfaces_stale_awis_runtime_projection_for_human_navigation(): void
    {
        $this->createRuntimeProjectionTable();
        try {
            DB::table('atlas_workspace_runtime_projection_snapshots')->insert([
                'id' => Str::uuid()->toString(),
                'workspace_id' => 'atlas',
                'family' => 'AWTR',
                'schema_version' => 'atlas.workspace_twin.v1',
                'runtime_hash' => 'sha256:runtime_stale',
                'projection_hash' => 'sha256:projection_stale',
                'status' => 'ready',
                'payload' => json_encode([
                    'status' => 'ready',
                    'awis_projection' => [
                        'schema_version' => 'atlas.awis.runtime_projection_binding.v1',
                        'workspace_id' => 'atlas',
                        'workspace_hash' => str_repeat('0', 64),
                        'runtime_hash' => 'sha256:runtime_stale',
                        'family' => 'AWTR',
                        'projection_hash' => 'sha256:projection_stale',
                    ],
                ], JSON_THROW_ON_ERROR),
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
            $nodes = collect($payload['nodes'])->keyBy('id');

            $this->assertSame('blocked', $payload['workspace_scope']['runtime_projection_replay']['status']);
            $this->assertSame(1, $payload['workspace_scope']['runtime_projection_replay']['stale_count']);
            $this->assertSame(['AWTR'], $payload['workspace_scope']['runtime_projection_replay']['stale_families']);
            $this->assertSame('review', $nodes->get('system.awis')['status']);
            $this->assertTrue($nodes->get('system.awis')['visual_state']['requires_attention']);
            $this->assertSame('review', $nodes->get('flow.workspace-runtime-projections')['status']);
            $this->assertStringContainsString('projection AWIS persistida divergente', $nodes->get('project.atlas.workspace-intelligence')['human_modal']['summary']);
        } finally {
            Schema::dropIfExists('atlas_workspace_runtime_projection_snapshots');
        }
    }

    public function test_cartography_surfaces_stale_awair_artifact_graph_for_human_navigation(): void
    {
        $this->createArtifactGraphTable();
        try {
            DB::table('atlas_workspace_artifact_graph_snapshots')->insert([
                'id' => Str::uuid()->toString(),
                'workspace_id' => 'atlas',
                'runtime_hash' => 'sha256:runtime_awair_stale',
                'artifact_intelligence_hash' => 'sha256:artifact_awair_stale',
                'status' => 'ready',
                'lake_hash' => 'sha256:lake',
                'graph_hash' => 'sha256:graph',
                'artifact_count' => 10,
                'node_count' => 10,
                'edge_count' => 9,
                'replay_ready' => true,
                'simulation_decision' => 'ready',
                'nodes' => json_encode([]),
                'edges' => json_encode([]),
                'payload' => json_encode([
                    'schema_version' => 'atlas.workspace_artifact_intelligence.v1',
                    'status' => 'ready',
                    'workspace_id' => 'atlas',
                    'workspace_hash' => str_repeat('5', 64),
                    'artifact_intelligence_hash' => 'sha256:artifact_awair_stale',
                    'artifact_graph' => ['graph_hash' => 'sha256:graph'],
                ], JSON_THROW_ON_ERROR),
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
            $nodes = collect($payload['nodes'])->keyBy('id');

            $this->assertSame('blocked', $payload['workspace_scope']['artifact_graph_replay']['status']);
            $this->assertTrue($payload['workspace_scope']['artifact_graph_replay']['stale']);
            $this->assertSame('workspace_hash_changed', $payload['workspace_scope']['artifact_graph_replay']['reason']);
            $this->assertSame('review', $nodes->get('system.awair')['status']);
            $this->assertTrue($nodes->get('system.awair')['visual_state']['requires_attention']);
            $this->assertSame('review', $nodes->get('flow.workspace-artifact-graph')['status']);
            $this->assertStringContainsString('artifact graph AWAIR persistido divergente', $nodes->get('project.atlas.workspace-intelligence')['human_modal']['summary']);
        } finally {
            Schema::dropIfExists('atlas_workspace_artifact_graph_snapshots');
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
        $this->assertContains('system.awis', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('system.awair', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('system.awaol', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('flow.workspace-artifact-graph', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('flow.workspace-artifact-lake-replay', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('flow.workspace-artifact-workroom', $payload['task_simulator']['node_ids_available']);
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

    private function createRuntimeProjectionTable(): void
    {
        Schema::dropIfExists('atlas_workspace_runtime_projection_snapshots');
        Schema::create('atlas_workspace_runtime_projection_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('family', 20)->index();
            $table->string('schema_version', 120)->index();
            $table->string('runtime_hash', 80)->index();
            $table->string('projection_hash', 80)->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->unique(['runtime_hash', 'family'], 'aurc_runtime_projection_unique');
        });
    }

    private function createArtifactGraphTable(): void
    {
        Schema::dropIfExists('atlas_workspace_artifact_graph_snapshots');
        Schema::create('atlas_workspace_artifact_graph_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 80)->unique();
            $table->string('artifact_intelligence_hash', 80)->unique();
            $table->string('status', 40)->index();
            $table->string('lake_hash', 80)->nullable()->index();
            $table->string('graph_hash', 80)->nullable()->index();
            $table->unsignedInteger('artifact_count')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('edge_count')->default(0);
            $table->boolean('replay_ready')->default(false)->index();
            $table->string('simulation_decision', 40)->index();
            $table->json('nodes');
            $table->json('edges');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    private function createArtifactLakeTable(): void
    {
        Schema::dropIfExists('atlas_workspace_artifact_lake_entries');
        Schema::create('atlas_workspace_artifact_lake_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 80)->index();
            $table->string('artifact_hash', 80)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('status', 40)->index();
            $table->string('consumer', 120)->nullable()->index();
            $table->json('source_hashes');
            $table->json('body');
            $table->decimal('quality_score', 5, 2)->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->unique(['runtime_hash', 'artifact_hash'], 'aurc_artifact_lake_unique');
        });
    }
}
