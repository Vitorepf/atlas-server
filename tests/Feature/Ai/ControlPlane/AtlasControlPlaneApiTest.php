<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasControlPlaneApiTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_index_returns_snapshot_json(): void
    {
        $response = $this->getJson('/atlas/ai/control-plane');
        $response->assertOk();
        $response->assertJsonStructure([
            'schema',
            'status',
            'readiness',
            'missions_summary',
            'evidence_summary',
            'blockers_summary',
            'next_actions',
        ]);
        $response->assertJsonPath('schema', 'atlas.ai.control_plane.snapshot.v1');
    }

    public function test_readiness_endpoint_returns_report(): void
    {
        $response = $this->getJson('/atlas/ai/control-plane/readiness');
        $response->assertOk();
        $response->assertJsonPath('schema', 'atlas.ai.control_plane.readiness.v1');
        $response->assertJsonStructure(['status', 'components', 'summary']);
    }

    public function test_blockers_endpoint_returns_aggregator(): void
    {
        $response = $this->getJson('/atlas/ai/control-plane/blockers');
        $response->assertOk();
        $response->assertJsonPath('schema', 'atlas.ai.control_plane.blocker.v1');
    }

    public function test_next_actions_endpoint_returns_items(): void
    {
        $response = $this->getJson('/atlas/ai/control-plane/next-actions');
        $response->assertOk();
        $response->assertJsonPath('schema', 'atlas.ai.control_plane.next_action.v1');
        $response->assertJsonStructure(['items', 'count']);
    }

    public function test_mission_endpoint_returns_404_for_unknown(): void
    {
        $response = $this->getJson('/atlas/ai/control-plane/missions/00000000-0000-0000-0000-000000000000');
        $response->assertStatus(404);
        $response->assertJsonPath('error', 'not_found');
    }

    public function test_mission_endpoint_returns_snapshot_for_existing(): void
    {
        /** @var MissionFactoryService $factory */
        $factory = app(MissionFactoryService::class);
        $mission = $factory->create('API mission snapshot', [
            'mission_type' => MissionFactoryService::TYPE_TASK,
        ]);

        $response = $this->getJson('/atlas/ai/control-plane/missions/'.$mission->uuid);
        $response->assertOk();
        $response->assertJsonPath('component', 'mission');
        $response->assertJsonPath('mission.uuid', $mission->uuid);
    }
}
