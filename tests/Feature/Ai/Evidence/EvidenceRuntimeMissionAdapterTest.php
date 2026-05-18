<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\CertificationRuntimeService;
use App\Services\Ai\Evidence\MissionEvidenceAdapter;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class EvidenceRuntimeMissionAdapterTest extends TestCase
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

    public function test_adapter_builds_pack_and_certifies_mission_after_full_foundation_flow(): void
    {
        /** @var MissionFactoryService $factory */
        $factory = app(MissionFactoryService::class);
        /** @var ObjectiveDecomposerService $decomposer */
        $decomposer = app(ObjectiveDecomposerService::class);
        /** @var WorkOrderFactoryService $workOrders */
        $workOrders = app(WorkOrderFactoryService::class);
        /** @var MissionLifecycleService $lifecycle */
        $lifecycle = app(MissionLifecycleService::class);
        /** @var MissionEvidenceService $missionEvidence */
        $missionEvidence = app(MissionEvidenceService::class);
        /** @var MissionCertificationService $missionCertification */
        $missionCertification = app(MissionCertificationService::class);
        /** @var MissionEvidenceAdapter $adapter */
        $adapter = app(MissionEvidenceAdapter::class);

        $mission = $factory->create('Atlas Evidence Runtime adapter smoke test mission.', [
            'mission_type' => MissionFactoryService::TYPE_TASK,
            'autonomy_level' => MissionFactoryService::AUTONOMY_EXECUTE_WITH_APPROVAL,
            'risk_level' => MissionFactoryService::RISK_LOW,
        ]);
        $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'system']);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'system']);
        $missionEvidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'evidence-adapter:smoke:phpunit',
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, ['actor_type' => 'system']);
        $missionCertification->certify($mission);

        $mission->refresh();

        $pack = $adapter->buildMissionPack($mission);
        $this->assertSame((string) $mission->id, $pack->target_id);
        $this->assertNotEmpty($pack->evidence_hash);

        $cert = $adapter->certifyMission($mission, $pack->id);
        $this->assertSame(CertificationRuntimeService::STATUS_PASSED, $cert->status);
        $this->assertTrue($adapter->canCompleteMission($mission));
    }

    public function test_adapter_blocks_completion_when_foundation_missing_evidence(): void
    {
        /** @var MissionFactoryService $factory */
        $factory = app(MissionFactoryService::class);
        /** @var MissionEvidenceAdapter $adapter */
        $adapter = app(MissionEvidenceAdapter::class);

        $mission = $factory->create('Mission without evidence', [
            'mission_type' => MissionFactoryService::TYPE_TASK,
        ]);

        $pack = $adapter->buildMissionPack($mission);
        $cert = $adapter->certifyMission($mission, $pack->id);

        $this->assertNotSame(CertificationRuntimeService::STATUS_PASSED, $cert->status);
        $this->assertFalse($adapter->canCompleteMission($mission));
    }
}
