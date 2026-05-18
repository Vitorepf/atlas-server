<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionFoundationWorkOrderFactoryTest extends TestCase
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

    public function test_work_order_contains_required_arrays_and_receipt_hash(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $mission = $factory->create('Implementar exporter CSV');
        $decomposer->decompose($mission);
        $created = $workOrders->plan($mission);

        $this->assertCount(1, $created);
        $workOrder = $created->first();

        $this->assertIsArray($workOrder->expected_artifacts);
        $this->assertNotEmpty($workOrder->expected_artifacts);
        $this->assertIsArray($workOrder->expected_tests);
        $this->assertNotEmpty($workOrder->expected_tests);
        $this->assertIsArray($workOrder->risk_notes);
        $this->assertNotEmpty($workOrder->risk_notes);

        $this->assertNotNull($workOrder->receipt_hash);
        $this->assertSame(64, strlen($workOrder->receipt_hash));
    }

    public function test_plan_is_idempotent_per_objective(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $mission = $factory->create('Implementar exporter CSV');
        $decomposer->decompose($mission);
        $first = $workOrders->plan($mission)->pluck('id')->all();
        $second = $workOrders->plan($mission)->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame(1, $mission->workOrders()->count());
    }
}
