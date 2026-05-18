<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainRuntimeAdapter;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterCertificationTest extends TestCase
{
    use CreatesProgrammingAdapterTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgrammingAdapterTables();
    }

    protected function tearDown(): void
    {
        $this->dropProgrammingAdapterTables();
        parent::tearDown();
    }

    public function test_full_dev_cycle_certifies_and_completes_mission(): void
    {
        $adapted = app(AtlasDevMissionAdapter::class)->adapt('corrigir bug pequeno no endpoint /healthz com teste');
        $mission = $adapted['mission'];
        $workOrder = $adapted['work_orders']->first();

        $runtime = app(ProgrammingDomainRuntimeAdapter::class);
        $record = $runtime->plan($mission, $workOrder, ['capability' => $adapted['capability']]);

        $lifecycle = app(MissionLifecycleService::class);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING);

        app(MissionEvidenceService::class)->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'certification_cycle:test:passed',
        ]);

        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING);

        $certification = $runtime->certify($mission, $record);
        $this->assertSame(MissionCertificationService::STATUS_PASSED, $certification['mission_certification_status']);
        $this->assertNotNull($certification['mission_certification_hash']);

        $lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED);
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $mission->refresh()->status);
    }
}
