<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainKernelCanon;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterMissionAdapterTest extends TestCase
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

    public function test_dev_prompt_creates_mission_with_programming_domain_and_work_order(): void
    {
        $report = app(AtlasDevMissionAdapter::class)->adapt(
            'corrigir bug no endpoint /healthz com teste de regressao',
        );

        $mission = $report['mission'];
        $this->assertSame(ProgrammingDomainKernelCanon::DOMAIN_ID, $mission->primary_domain);
        $this->assertSame(MissionLifecycleService::STATUS_PLANNED, $mission->status);
        $this->assertNotEmpty($report['objectives']);
        $this->assertNotEmpty($report['work_orders']);
        $this->assertSame('programming.repair', $report['capability']);
        $this->assertSame(64, strlen((string) $report['task_contract_hash']));
    }

    public function test_obra_prompt_marks_mission_type_as_obra(): void
    {
        $report = app(AtlasDevMissionAdapter::class)->adapt(
            'planejar obra de migracao multi-modulo com sdd e multiagente para reescrever provider router',
        );

        $this->assertSame(MissionFactoryService::TYPE_OBRA, $report['mission']->mission_type);
    }
}
