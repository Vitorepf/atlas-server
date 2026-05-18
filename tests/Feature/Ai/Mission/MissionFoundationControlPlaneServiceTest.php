<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionControlPlaneService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationControlPlaneServiceTest extends TestCase
{
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
    }

    protected function tearDown(): void
    {
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_snapshot_aggregates_state_with_canonical_schema(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $evidence = app(MissionEvidenceService::class);
        $certification = app(MissionCertificationService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $controlPlane = app(MissionControlPlaneService::class);

        $mission = $factory->create('Implementar exporter CSV');
        $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'control-plane:test',
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING);
        $certification->certify($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED);

        $snapshot = $controlPlane->snapshot($mission);

        $this->assertSame(MissionControlPlaneService::SCHEMA, $snapshot['schema']);
        foreach ([
            'mission', 'definition_of_done', 'objectives', 'work_orders',
            'evidence_refs', 'events', 'latest_certification', 'blockers',
            'next_action', 'readiness',
        ] as $key) {
            $this->assertArrayHasKey($key, $snapshot, "snapshot missing key [{$key}]");
        }

        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $snapshot['mission']['status']);
        $this->assertTrue($snapshot['readiness']['has_objectives']);
        $this->assertTrue($snapshot['readiness']['has_work_orders']);
        $this->assertTrue($snapshot['readiness']['has_evidence']);
        $this->assertTrue($snapshot['readiness']['has_passed_certification']);
        $this->assertNull($snapshot['next_action']);
    }

    public function test_snapshot_emits_next_action_for_blocked_mission(): void
    {
        $factory = app(MissionFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $controlPlane = app(MissionControlPlaneService::class);
        $mission = $factory->create('Implementar exporter CSV');
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, ['blocker_reason' => 'missing source']);

        $snapshot = $controlPlane->snapshot($mission);

        $this->assertSame(MissionLifecycleService::STATUS_BLOCKED, $snapshot['mission']['status']);
        $this->assertNotEmpty($snapshot['blockers']);
        $this->assertNotNull($snapshot['next_action']);
        $this->assertStringContainsString('missing source', (string) $snapshot['next_action']);
    }
}
