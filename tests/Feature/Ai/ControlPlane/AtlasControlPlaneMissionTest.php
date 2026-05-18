<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasControlPlaneMissionService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasControlPlaneMissionTest extends TestCase
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

    public function test_mission_snapshot_for_existing_mission_uuid(): void
    {
        /** @var MissionFactoryService $factory */
        $factory = app(MissionFactoryService::class);
        $mission = $factory->create('Control Plane mission snapshot test', [
            'mission_type' => MissionFactoryService::TYPE_TASK,
        ]);

        /** @var AtlasControlPlaneMissionService $svc */
        $svc = app(AtlasControlPlaneMissionService::class);
        $payload = $svc->snapshot((string) $mission->uuid);

        $this->assertNotNull($payload);
        $this->assertSame('mission', $payload['component']);
        $this->assertArrayHasKey('mission', $payload);
        $this->assertSame($mission->uuid, $payload['mission']['uuid']);
    }

    public function test_mission_snapshot_returns_null_for_unknown_uuid(): void
    {
        /** @var AtlasControlPlaneMissionService $svc */
        $svc = app(AtlasControlPlaneMissionService::class);
        $this->assertNull($svc->snapshot('00000000-0000-0000-0000-000000000000'));
    }

    public function test_summary_returns_ready_status_with_totals(): void
    {
        /** @var MissionFactoryService $factory */
        $factory = app(MissionFactoryService::class);
        $factory->create('summary test 1', ['mission_type' => MissionFactoryService::TYPE_TASK]);

        /** @var AtlasControlPlaneMissionService $svc */
        $svc = app(AtlasControlPlaneMissionService::class);
        $summary = $svc->summary();
        $this->assertSame('mission', $summary['component']);
        $this->assertSame('ready', $summary['status']);
        $this->assertGreaterThanOrEqual(1, $summary['total']);
    }
}
