<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionLifecycleService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationSmokeTest extends TestCase
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

    public function test_smoke_action_runs_full_end_to_end_flow(): void
    {
        $exit = $this->artisan('atlas:ai:mission-foundation', [
            '--action' => 'smoke',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit, 'smoke action did not exit 0');

        $mission = AiMission::query()->latest('created_at')->first();
        $this->assertNotNull($mission);
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $mission->status);
        $this->assertNotNull($mission->certification_hash);
        $this->assertNotNull($mission->evidence_pack_hash);
        $this->assertNotNull($mission->completed_at);

        $this->assertGreaterThan(0, $mission->objectives()->count(), 'expected at least 1 objective');
        $this->assertGreaterThan(0, $mission->workOrders()->count(), 'expected at least 1 work_order');
        $this->assertGreaterThan(0, $mission->evidenceRefs()->count(), 'expected at least 1 evidence_ref');

        $workOrder = $mission->workOrders()->first();
        $this->assertNotNull($workOrder->receipt_hash, 'expected work_order.receipt_hash to be set');

        $latest = $mission->latestCertification()->first();
        $this->assertNotNull($latest);
        $this->assertSame(MissionCertificationService::STATUS_PASSED, $latest->status);
        $this->assertNotNull($latest->certified_at);

        $eventTypes = $mission->events()->pluck('event_type')->unique()->all();
        foreach ([
            'mission.created',
            'objective.created',
            'work_order.created',
            'evidence.attached',
            'certification.recorded',
            'mission.transition',
        ] as $expectedType) {
            $this->assertContains($expectedType, $eventTypes, "expected event of type [{$expectedType}]");
        }
    }
}
