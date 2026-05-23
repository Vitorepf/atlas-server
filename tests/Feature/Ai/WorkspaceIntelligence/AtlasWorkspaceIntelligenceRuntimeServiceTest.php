<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\WorkspaceIntelligence;

use App\Models\AtlasWorkspaceArtifactGraphSnapshot;
use App\Models\AtlasWorkspaceArtifactLakeEntry;
use App\Models\AtlasWorkspaceIntelligenceSnapshot;
use App\Models\AtlasWorkspaceRuntimeProjectionSnapshot;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceExecutionBoundaryAuditService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasWorkspaceIntelligenceRuntimeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSnapshotTable();
        $this->createArtifactIntelligenceTables();
        $this->createRuntimeProjectionTable();
    }

    public function test_default_atlas_workspace_certifies_full_awis_family(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'corrigir bug na tela de login',
            conversationTexts: ['decisao: AWIS usa task context pack; blocker antigo foi resolvido'],
        );

        $this->assertSame(AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame('atlas', $report['workspace']['workspace_id']);
        $this->assertSame('ready', $report['workspace']['readiness_status']);

        foreach (['AWIS', 'AWTR', 'ACIOS', 'AWAF', 'AWAIR', 'AWCO', 'AWEF'] as $acronym) {
            $this->assertArrayHasKey($acronym, $report['family']);
        }

        $this->assertSame('ready', $report['awtr']['status']);
        $this->assertNotEmpty($report['awtr']['twin_hash']);
        $this->assertSame('atlas.workspace_genome.v1', $report['awtr']['genome']['schema_version']);
        $this->assertSame(64, strlen((string) $report['awtr']['genome']['genome_hash']));
        $this->assertSame('atlas.workspace_living_code_map.v1', $report['awtr']['living_code_map']['schema_version']);
        $this->assertSame(64, strlen((string) $report['awtr']['living_code_map']['code_map_hash']));
        $this->assertSame('owner_docs_plus_task_artifacts_plus_focused_tests', $report['awtr']['context_autopilot']['strategy']);
        $this->assertSame('atlas.workspace_command_registry.v1', $report['awtr']['command_registry']['schema_version']);
        $this->assertSame(64, strlen((string) $report['awtr']['command_registry']['command_registry_hash']));
        $this->assertSame(64, strlen((string) $report['awtr']['risk_fragility_map']['risk_map_hash']));
        $this->assertSame(false, $report['acios']['task_context_pack_policy']['uses_raw_conversation']);
        $this->assertSame(10, $report['awaf']['artifact_count']);
        $artifactTypes = collect($report['awaf']['artifacts'])->pluck('artifact_type')->all();
        $this->assertSame([
            'workspace_brief',
            'task_packet',
            'context_pack',
            'execution_plan',
            'test_plan',
            'risk_sheet',
            'handoff_packet',
            'failure_capsule',
            'outcome_record',
            'workspace_runbook',
        ], $artifactTypes);
        $this->assertSame('ready', $report['awair']['status']);
        $this->assertSame('atlas.workspace_artifact_intelligence.v1', $report['awair']['schema_version']);
        $this->assertSame(10, $report['awair']['artifact_lake']['artifact_count']);
        $this->assertNotEmpty($report['awair']['artifact_graph']['nodes']);
        $this->assertNotEmpty($report['awair']['artifact_graph']['edges']);
        $this->assertTrue($report['awair']['artifact_replay']['replay_ready']);
        $this->assertSame('ready', $report['awair']['artifact_simulation']['decision']);
        $this->assertFalse($report['awair']['artifact_context_compiler']['raw_conversation_included']);
        $this->assertTrue($report['awair']['artifact_quality_governor']['all_executable_artifacts_ready']);
        $this->assertSame('graph_first', $report['awair']['artifact_cartography_projection']['human_scan_mode']);
        $this->assertSame('patterns_only_no_raw_cross_workspace', $report['awair']['artifact_marketplace']['privacy_policy']);
        $this->assertContains('AEMOR', $report['awair']['artifact_outcome_learning']['feeds']);
        $this->assertSame('ready', $report['awco']['execution_readiness_status']);
        $this->assertSame('atlas.workspace_contract_certification_envelope.v1', $report['awco']['certification_envelope']['schema_version']);
        $this->assertSame(10, $report['awco']['certification_envelope']['certified_count']);
        $this->assertSame('atlas.workspace_contract_invalidation_rules.v1', $report['awco']['invalidation_rules']['schema_version']);
        $this->assertContains('shadow_execution', $report['awco']['orchestration_plan']['required_before_provider']);
        $this->assertSame(64, strlen((string) $report['awco']['contract_hash']));
        $this->assertTrue($report['awef']['privacy_preserving_transfer']);
        $this->assertSame('atlas.workspace_pattern_library.v1', $report['awef']['pattern_library']['schema_version']);
        $this->assertFalse($report['awef']['privacy_transfer_gate']['allows_raw_cross_workspace']);
        $this->assertSame('read_only_shadow', $report['awef']['workspace_benchmark']['mode']);
        $this->assertSame(64, strlen((string) $report['awef']['evolution_hash']));
        $this->assertSame('ready', $report['execution_boundaries']['status']);
        $this->assertGreaterThanOrEqual(17, $report['execution_boundaries']['guarded_boundaries_total']);
        $this->assertGreaterThanOrEqual(20, $report['execution_boundaries']['process_inventory_total']);
        $this->assertSame(0, $report['execution_boundaries']['process_inventory_unclassified']);
        $this->assertSame('ready', $report['registry_editing']['status']);
        $this->assertContains('archive', $report['registry_editing']['api_actions']);
        $this->assertTrue($report['registry_editing']['requirements']['api_archive_route']);
        $this->assertTrue($report['registry_editing']['requirements']['controller_destroy']);
        $this->assertTrue($report['registry_editing']['requirements']['service_archive']);
        $this->assertTrue($report['claim_policy']['known_execution_boundaries_audited']);
        $this->assertFalse($report['claim_policy']['unclassified_workspace_process_boundaries_allowed']);
        $this->assertTrue($report['claim_policy']['ui_registry_editing_complete']);
        $this->assertSame(64, strlen((string) $report['runtime_hash']));
    }

    public function test_unknown_workspace_blocks_without_fabricating_context(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'missing-workspace',
            task: 'qualquer coisa',
        );

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('blocked', $report['workspace']['status']);
        $this->assertSame(['workspace_not_registered'], $report['workspace']['blockers']);
        $this->assertSame('blocked', $report['awair']['status']);
        $this->assertFalse($report['awair']['artifact_replay']['replay_ready']);
        $this->assertSame('blocked', $report['awair']['artifact_simulation']['decision']);
        $this->assertSame('blocked', $report['awco']['execution_readiness_status']);
        $this->assertSame(false, $report['claim_policy']['raw_conversation_used_as_prompt']);
    }

    public function test_long_conversation_is_archived_by_hash_and_not_used_as_prompt(): void
    {
        $raw = str_repeat('contexto bruto sensivel ', 200).'decidido: usar AWIS; blocker: contexto velho';

        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'gerar pacote minimo',
            conversationTexts: [$raw],
        );

        $this->assertSame(1, $report['acios']['raw_archive']['conversation_count']);
        $this->assertSame('audit_only', $report['acios']['raw_archive']['access_mode']);
        $this->assertSame(false, $report['acios']['current_truth_pack']['raw_conversation_in_prompt']);
        $this->assertSame(false, $report['awaf']['artifacts'][2]['body']['raw_conversation_included']);

        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(str_repeat('contexto bruto sensivel ', 20), $encoded);
    }

    public function test_command_emits_json_and_strict_ready_for_atlas(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug na tela de login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace']['workspace_id']);
        $this->assertSame('atlas.workspace_intelligence.runtime.v1', $decoded['schema_version']);
    }

    public function test_command_strict_fails_for_missing_workspace(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'missing-workspace',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', $decoded['status']);
    }

    public function test_api_exposes_workspace_intelligence_for_desktop_and_mobile_surfaces(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace.workspace_id', 'atlas')
            ->assertJsonPath('awaf.artifact_count', 10)
            ->assertJsonPath('awco.execution_readiness_status', 'ready');
    }

    public function test_api_artifacts_endpoint_returns_awaf_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifacts?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_fabric.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('artifact_count', 10);
    }

    public function test_api_twin_endpoint_returns_awtr_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/twin?workspace=atlas',
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_twin.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('genome.schema_version', 'atlas.workspace_genome.v1')
            ->assertJsonPath('context_autopilot.raw_conversation_policy', 'hash_and_excerpt_only')
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');

        $this->assertSame(64, strlen((string) $response->json('twin_hash')));
        $this->assertSame(64, strlen((string) $response->json('genome.genome_hash')));
        $this->assertSame(64, strlen((string) $response->json('living_code_map.code_map_hash')));
    }

    public function test_command_twin_returns_awtr_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'twin',
            '--workspace' => 'atlas',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_twin.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace_id']);
        $this->assertSame('atlas.workspace_context_autopilot.v1', $decoded['context_autopilot']['schema_version']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_api_contracts_endpoint_returns_awco_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/contracts?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_contract_orchestrator.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('execution_readiness_status', 'ready')
            ->assertJsonPath('certification_envelope.artifact_count', 10)
            ->assertJsonPath('certification_envelope.blocked_count', 0)
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');

        $this->assertSame(64, strlen((string) $response->json('contract_hash')));
    }

    public function test_command_contracts_returns_awco_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'contracts',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_contract_orchestrator.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('all_artifacts_must_have_workspace_and_source_hashes', $decoded['certification_envelope']['quality_policy']);
        $this->assertContains('provider_invocation', $decoded['orchestration_plan']['mutative_consumers']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_api_evolution_endpoint_returns_awef_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/evolution?workspace=atlas',
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_evolution_fabric.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('privacy_preserving_transfer', true)
            ->assertJsonPath('privacy_transfer_gate.allows_raw_cross_workspace', false)
            ->assertJsonPath('workspace_benchmark.mode', 'read_only_shadow')
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');

        $this->assertSame(64, strlen((string) $response->json('evolution_hash')));
    }

    public function test_command_evolution_returns_awef_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'evolution',
            '--workspace' => 'atlas',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_evolution_fabric.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('abstracted_only', $decoded['pattern_library']['privacy_level']);
        $this->assertContains('raw_context_present', $decoded['privacy_transfer_gate']['block_when']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_api_artifact_intelligence_endpoint_returns_awair_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_intelligence.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('artifact_lake.artifact_count', 10)
            ->assertJsonPath('artifact_replay.replay_ready', true)
            ->assertJsonPath('artifact_context_compiler.raw_conversation_included', false)
            ->assertJsonMissingPath('awaf')
            ->assertJsonMissingPath('workspace');
    }

    public function test_command_artifact_intelligence_returns_awair_projection_only(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'artifact-intelligence',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.workspace_artifact_intelligence.v1', $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace_id']);
        $this->assertSame(10, $decoded['artifact_lake']['artifact_count']);
        $this->assertTrue($decoded['artifact_replay']['replay_ready']);
        $this->assertArrayNotHasKey('awaf', $decoded);
        $this->assertArrayNotHasKey('workspace', $decoded);
    }

    public function test_command_persists_snapshot_when_requested(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'persistir snapshot AWIS',
            '--json' => true,
            '--persist' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['persisted_snapshot_id']);
        $this->assertNotEmpty($decoded['persisted_artifact_graph_id']);
        $this->assertCount(3, $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWTR', $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWCO', $decoded['persisted_projection_ids']);
        $this->assertArrayHasKey('AWEF', $decoded['persisted_projection_ids']);

        $this->assertDatabaseHas('atlas_workspace_intelligence_snapshots', [
            'workspace_id' => 'atlas',
            'runtime_hash' => $decoded['runtime_hash'],
            'status' => 'ready',
            'artifacts_count' => 10,
        ]);
        $this->assertDatabaseHas('atlas_workspace_artifact_graph_snapshots', [
            'workspace_id' => 'atlas',
            'runtime_hash' => $decoded['runtime_hash'],
            'status' => 'ready',
            'artifact_count' => 10,
            'node_count' => 10,
            'replay_ready' => true,
            'simulation_decision' => 'ready',
        ]);
        $this->assertSame(10, AtlasWorkspaceArtifactLakeEntry::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->count());
        $this->assertSame(3, AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->count());

        $snapshot = AtlasWorkspaceIntelligenceSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->firstOrFail();
        $this->assertSame('ready', $snapshot->family_status['AWAIR'] ?? null);
        $this->assertSame('atlas.workspace_artifact_intelligence.v1', data_get($snapshot->payload, 'awair.schema_version'));

        $graph = AtlasWorkspaceArtifactGraphSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->firstOrFail();
        $this->assertSame($decoded['awair']['artifact_intelligence_hash'], $graph->artifact_intelligence_hash);
        $this->assertSame($decoded['awair']['artifact_graph']['graph_hash'], $graph->graph_hash);

        $twin = AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $decoded['runtime_hash'])
            ->where('family', 'AWTR')
            ->firstOrFail();
        $this->assertSame($decoded['awtr']['twin_hash'], data_get($twin->payload, 'twin_hash'));
        $this->assertSame('atlas.awis.runtime_projection_binding.v1', data_get($twin->payload, 'awis_projection.schema_version'));
        $this->assertSame($decoded['workspace']['workspace_hash'], data_get($twin->payload, 'awis_projection.workspace_hash'));
        $this->assertSame('block_latest_replay_when_workspace_hash_differs_or_is_missing', data_get($twin->payload, 'awis_projection.stale_policy'));
    }

    public function test_api_latest_replays_persisted_snapshot(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('snapshot replay').'&persist=1',
        );
        $persisted->assertOk();

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertOk()
            ->assertJsonPath('runtime_hash', $persisted->json('runtime_hash'))
            ->assertJsonPath('workspace.workspace_id', 'atlas')
            ->assertJsonPath('awaf.artifact_count', 10)
            ->assertJsonPath('awair.artifact_replay.replay_ready', true);

        $latestAwair = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&latest=1',
        );

        $latestAwair
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_intelligence.v1')
            ->assertJsonPath('artifact_intelligence_hash', $persisted->json('awair.artifact_intelligence_hash'));

        $latestTwin = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/twin?workspace=atlas&latest=1',
        );
        $latestTwin
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_twin.v1')
            ->assertJsonPath('twin_hash', $persisted->json('awtr.twin_hash'));

        $latestContracts = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/contracts?workspace=atlas&latest=1',
        );
        $latestContracts
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_contract_orchestrator.v1')
            ->assertJsonPath('contract_hash', $persisted->json('awco.contract_hash'));

        $latestEvolution = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/evolution?workspace=atlas&latest=1',
        );
        $latestEvolution
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_evolution_fabric.v1')
            ->assertJsonPath('evolution_hash', $persisted->json('awef.evolution_hash'));
    }

    public function test_api_latest_blocks_stale_runtime_projection_when_workspace_hash_changes(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('snapshot stale replay').'&persist=1',
        );
        $persisted->assertOk();

        $snapshot = AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('runtime_hash', $persisted->json('runtime_hash'))
            ->where('family', 'AWCO')
            ->firstOrFail();
        $payload = $snapshot->payload;
        data_set($payload, 'awis_projection.workspace_hash', str_repeat('0', 64));
        $snapshot->forceFill(['payload' => $payload])->save();

        $latestContracts = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/contracts?workspace=atlas&latest=1',
        );

        $latestContracts
            ->assertStatus(409)
            ->assertJsonPath('schema_version', 'atlas.awis.runtime_projection_stale.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('family', 'AWCO')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('reason', 'workspace_hash_changed')
            ->assertJsonPath('snapshot_workspace_hash', str_repeat('0', 64))
            ->assertJsonPath('current_workspace_hash', $persisted->json('workspace.workspace_hash'))
            ->assertJsonPath('blockers.0', 'workspace_runtime_projection_stale');
    }

    public function test_api_latest_blocks_stale_full_awis_snapshot_when_workspace_hash_changes(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('full snapshot stale replay').'&persist=1',
        );
        $persisted->assertOk();

        $snapshot = AtlasWorkspaceIntelligenceSnapshot::query()
            ->where('runtime_hash', $persisted->json('runtime_hash'))
            ->firstOrFail();
        $snapshot->forceFill(['workspace_hash' => str_repeat('1', 64)])->save();

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertStatus(409)
            ->assertJsonPath('schema_version', 'atlas.awis.runtime_snapshot_stale.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('reason', 'workspace_hash_changed')
            ->assertJsonPath('snapshot_workspace_hash', str_repeat('1', 64))
            ->assertJsonPath('current_workspace_hash', $persisted->json('workspace.workspace_hash'))
            ->assertJsonPath('blockers.0', 'workspace_intelligence_snapshot_stale');
    }

    public function test_api_artifact_intelligence_persist_writes_lake_and_replays_from_dedicated_graph(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&task='.urlencode('artifact graph replay').'&persist=1',
        );
        $persisted->assertOk();

        $this->assertSame(1, AtlasWorkspaceArtifactGraphSnapshot::query()->count());
        $this->assertSame(10, AtlasWorkspaceArtifactLakeEntry::query()->count());

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifact-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertOk()
            ->assertJsonPath('artifact_intelligence_hash', $persisted->json('artifact_intelligence_hash'))
            ->assertJsonPath('artifact_lake.artifact_count', 10)
            ->assertJsonPath('artifact_graph.graph_hash', $persisted->json('artifact_graph.graph_hash'));
    }

    public function test_persist_is_idempotent_for_same_runtime_hash(): void
    {
        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'mesma tarefa',
            '--json' => true,
            '--persist' => true,
        ]);
        $first = json_decode(Artisan::output(), true);

        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'mesma tarefa',
            '--json' => true,
            '--persist' => true,
        ]);
        $second = json_decode(Artisan::output(), true);

        $this->assertSame($first['runtime_hash'], $second['runtime_hash']);
        $this->assertDatabaseCount('atlas_workspace_intelligence_snapshots', 1);
        $this->assertDatabaseCount('atlas_workspace_artifact_graph_snapshots', 1);
        $this->assertDatabaseCount('atlas_workspace_artifact_lake_entries', 10);
        $this->assertDatabaseCount('atlas_workspace_runtime_projection_snapshots', 3);
    }

    public function test_execution_gate_allows_conversation_without_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'missing-workspace',
            mode: 'conversation',
            task: 'conversa livre',
        );

        $this->assertSame(AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('limited', $gate['status']);
        $this->assertSame('conversation', $gate['execution_class']);
        $this->assertSame(['workspace_not_ready_conversation_only'], $gate['warnings']);
        $this->assertSame([], $gate['blockers']);
    }

    public function test_execution_gate_blocks_mutative_dev_without_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'missing-workspace',
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertFalse($gate['allowed']);
        $this->assertSame('blocked', $gate['status']);
        $this->assertSame('mutative', $gate['execution_class']);
        $this->assertContains('workspace_not_ready', $gate['blockers']);
        $this->assertContains('workspace_contracts_not_certified', $gate['blockers']);
        $this->assertContains('artifact_shadow_execution_blocked', $gate['blockers']);
        $this->assertSame('blocked', $gate['artifact_shadow_execution']['status']);
        $this->assertContains('artifact_replay_not_ready', $gate['artifact_shadow_execution']['blockers']);
    }

    public function test_execution_gate_allows_mutative_forge_for_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'atlas',
            mode: 'forge',
            task: 'criar ecommerce',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('mutative', $gate['execution_class']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertSame([], $gate['blockers']);
        $this->assertTrue($gate['required_contracts']['awco_execution_readiness']);
        $this->assertTrue($gate['required_contracts']['awair_shadow_execution']);
        $this->assertSame('atlas.workspace_artifact_shadow_execution.v1', $gate['artifact_shadow_execution']['schema_version']);
        $this->assertSame('ready', $gate['artifact_shadow_execution']['status']);
        $this->assertFalse($gate['artifact_shadow_execution']['provider_called']);
        $this->assertFalse($gate['artifact_shadow_execution']['workspace_mutated']);
        $this->assertSame(10, $gate['artifact_shadow_execution']['node_count']);
        $this->assertGreaterThanOrEqual(8, $gate['artifact_shadow_execution']['edge_count']);
        $this->assertSame(64, strlen((string) $gate['gate_hash']));
    }

    public function test_execution_gate_accepts_workspace_path_as_alias_for_profile_slug(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: base_path('..'),
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertTrue($gate['required_contracts']['awis_workspace_binding']);
    }

    public function test_command_gate_blocks_mutative_mode_in_strict_mode(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'gate',
            '--workspace' => 'missing-workspace',
            '--mode' => 'index-code',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', $decoded['status']);
        $this->assertFalse($decoded['allowed']);
    }

    public function test_execution_boundary_audit_proves_known_mutative_entrypoints_are_awis_gated(): void
    {
        $report = app(AtlasWorkspaceExecutionBoundaryAuditService::class)->audit();

        $this->assertSame(AtlasWorkspaceExecutionBoundaryAuditService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertGreaterThanOrEqual(16, $report['summary']['total']);
        $this->assertSame($report['summary']['total'], $report['summary']['passed']);
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertGreaterThanOrEqual(20, $report['summary']['process_inventory_total']);
        $this->assertSame(0, $report['summary']['process_inventory_unclassified']);
        $this->assertTrue(data_get($report, 'claim_policy.mutative_dev_forge_boundaries_require_awis'));
        $this->assertFalse(data_get($report, 'claim_policy.unclassified_workspace_process_boundaries_allowed'));
        $this->assertSame([], collect($report['boundaries'])->pluck('missing_markers')->flatten()->values()->all());
        $this->assertSame([], collect($report['process_inventory']['items'])->pluck('missing_markers')->flatten()->values()->all());
        $this->assertSame(0, $report['process_inventory']['unclassified']);
        $this->assertArrayHasKey('directly_guarded_by_awis', $report['process_inventory']['by_classification']);
        $this->assertArrayHasKey('caller_guarded_by_awis', $report['process_inventory']['by_classification']);
        $this->assertArrayHasKey('read_only_workspace_probe', $report['process_inventory']['by_classification']);
        $this->assertTrue(collect($report['process_inventory']['items'])->contains(
            fn (array $item): bool => $item['id'] === 'engineering_quality_scan'
                && $item['classification'] === 'directly_guarded_by_awis',
        ));
        $this->assertTrue(collect($report['process_inventory']['items'])->contains(
            fn (array $item): bool => $item['id'] === 'atlas_dev_ripgrep_discovery'
                && $item['classification'] === 'read_only_workspace_probe',
        ));
        $this->assertTrue(collect($report['process_inventory']['items'])->contains(
            fn (array $item): bool => $item['id'] === 'atlas_dev_provider_gateway'
                && $item['classification'] === 'caller_guarded_by_awis',
        ));
        $this->assertSame(64, strlen((string) $report['audit_hash']));
    }

    public function test_command_boundary_audit_emits_ready_static_scan(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'boundary-audit',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(AtlasWorkspaceExecutionBoundaryAuditService::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame(0, $decoded['summary']['failed']);
    }

    public function test_command_registers_persisted_workspace_and_gate_resolves_it(): void
    {
        $this->createWorkspaceProfilesTable();

        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'register',
            '--workspace' => 'client-y',
            '--name' => 'Client Y',
            '--path' => base_path(),
            '--test-command' => ['php artisan test'],
            '--critical-area' => ['app', 'tests'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('client-y', $decoded['workspace']['slug']);
        $this->assertTrue((bool) data_get($decoded, 'meta.execution_allowed'));

        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'client-y',
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('client-y', $gate['workspace_id']);
    }

    public function test_api_persists_workspace_profile_but_does_not_auto_allow_missing_path_execution(): void
    {
        $this->createWorkspaceProfilesTable();

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/projects/workspaces', [
            'slug' => 'paper-project',
            'name' => 'Paper Project',
            'workspace_path' => '/definitely/missing/atlas/project',
            'docs_status' => 'incomplete',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'paper-project')
            ->assertJsonPath('workspace.workspace_path_exists', false)
            ->assertJsonPath('meta.execution_allowed', false);

        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'paper-project',
            mode: 'forge',
            task: 'criar ecommerce',
        );

        $this->assertFalse($gate['allowed']);
        $this->assertContains('workspace_not_ready', $gate['blockers']);
    }

    public function test_api_gate_endpoint_blocks_mutative_mode_without_workspace(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/gate?workspace=missing-workspace&mode=patch',
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('schema_version', AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION)
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('status', 'blocked');
    }

    public function test_atlas_dev_runtime_resolved_from_container_embeds_awis_execution_gate(): void
    {
        $data = app(AtlasDevRuntimeService::class)->apply([
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => base_path('..'),
                'input_text' => 'corrigir bug login',
                'context_refs' => ['docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md'],
                'expected_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
                'suggested_tests' => ['php artisan test --filter=AtlasDevRuntimeServiceTest'],
                'acceptance_criteria' => ['runtime carries AWIS execution gate'],
            ],
        ]);

        $gate = data_get($data, 'payload.atlas_dev_runtime.workspace_execution_gate');

        $this->assertIsArray($gate);
        $this->assertSame(AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertSame('dev', $gate['mode']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertTrue(data_get($data, 'payload.atlas_dev_runtime.provider_execution_allowed'));
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    private function createSnapshotTable(): void
    {
        if (Schema::hasTable('atlas_workspace_intelligence_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_intelligence_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->index();
            $table->string('workspace_id', 120)->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_passed')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);
            $table->unsignedInteger('artifacts_count')->default(0);
            $table->json('family_status');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    private function createArtifactIntelligenceTables(): void
    {
        if (! Schema::hasTable('atlas_workspace_artifact_lake_entries')) {
            Schema::create('atlas_workspace_artifact_lake_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('workspace_id', 120)->index();
                $table->string('runtime_hash', 64)->index();
                $table->string('artifact_hash', 64)->index();
                $table->string('artifact_type', 120)->index();
                $table->string('status', 40)->index();
                $table->string('consumer', 120)->nullable()->index();
                $table->json('source_hashes');
                $table->json('body');
                $table->decimal('quality_score', 5, 2)->default(0);
                $table->timestamp('captured_at')->index();
                $table->timestamps();

                $table->unique(['runtime_hash', 'artifact_hash']);
            });
        }

        if (Schema::hasTable('atlas_workspace_artifact_graph_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_artifact_graph_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('artifact_intelligence_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('lake_hash', 64)->nullable()->index();
            $table->string('graph_hash', 64)->nullable()->index();
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

    private function createWorkspaceProfilesTable(): void
    {
        if (Schema::hasTable('atlas_workspace_profiles')) {
            return;
        }

        Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('kind', 80)->default('product');
            $table->string('workspace_path', 1000)->nullable();
            $table->string('repo_root', 1000)->nullable();
            $table->string('production_status', 80)->default('development');
            $table->text('stack_summary')->nullable();
            $table->json('commands')->nullable();
            $table->json('test_commands')->nullable();
            $table->json('build_commands')->nullable();
            $table->string('dev_server_command', 1000)->nullable();
            $table->json('critical_areas')->nullable();
            $table->string('docs_status', 80)->default('unknown');
            $table->string('default_risk', 40)->default('medium');
            $table->text('deployment_notes')->nullable();
            $table->json('surfaces_enabled')->nullable();
            $table->string('source', 80)->default('operator');
            $table->string('status', 40)->default('active');
            $table->timestamps();
        });
    }

    private function createRuntimeProjectionTable(): void
    {
        if (Schema::hasTable('atlas_workspace_runtime_projection_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_runtime_projection_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('family', 20)->index();
            $table->string('schema_version', 120)->index();
            $table->string('runtime_hash', 64)->index();
            $table->string('projection_hash', 64)->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->unique(['runtime_hash', 'family']);
        });
    }
}
