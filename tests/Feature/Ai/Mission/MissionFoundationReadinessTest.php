<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionReadinessService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationReadinessTest extends TestCase
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

    public function test_readiness_command_returns_ok_with_all_checks_passing(): void
    {
        $exitCode = $this->artisan('atlas:ai:mission-foundation', ['--action' => 'readiness', '--json' => true])
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_readiness_payload_includes_table_model_and_service_checks(): void
    {
        $readiness = app(MissionReadinessService::class);
        $report = $readiness->report();

        $this->assertTrue($report['ok'], 'expected readiness ok=true, got: '.json_encode($report['summary']));
        $checkNames = collect($report['checks'])->pluck('name')->all();

        foreach ([
            'table:ai_missions',
            'table:ai_objectives',
            'table:ai_work_orders',
            'table:ai_mission_events',
            'table:ai_mission_evidence_refs',
            'table:ai_mission_certifications',
            'model:AiMission',
            'service:MissionFactoryService',
            'service:MissionCertificationService',
            'guard:lifecycle_transitions',
            'guard:completion_requires_certification',
        ] as $expected) {
            $this->assertContains($expected, $checkNames, "readiness check [{$expected}] missing");
        }
    }
}
