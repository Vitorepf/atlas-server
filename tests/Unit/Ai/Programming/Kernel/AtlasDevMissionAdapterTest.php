<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use App\Services\Ai\Programming\Kernel\AtlasDevMissionAdapter;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Focused unit coverage for the factory-critical dev mission adapter.
 *
 * Feature suites exercise adapt() end-to-end; this file guards capability/risk
 * defaults, mission-type inference, and the dev execution request envelope
 * without spinning up Domain Runtime tables.
 */
final class AtlasDevMissionAdapterTest extends TestCase
{
    public function test_build_dev_execution_request_assembles_canonical_envelope(): void
    {
        $adapter = $this->makeAdapter();

        $mission = new AiMission([
            'id' => '00000000-0000-0000-0000-000000000001',
            'risk_level' => 'high',
            'autonomy_level' => 'execute_with_approval',
        ]);
        $objective = new AiObjective(['id' => '00000000-0000-0000-0000-000000000002']);
        $workOrder = new AiWorkOrder([
            'id' => '00000000-0000-0000-0000-000000000003',
            'instructions' => 'Fix /healthz regression',
            'expected_artifacts' => ['patch'],
            'expected_tests' => ['tests/Feature/HealthzTest.php'],
        ]);

        $request = $adapter->buildDevExecutionRequest(
            $mission,
            $objective,
            $workOrder,
            'programming.repair',
            str_repeat('a', 64),
        );

        $this->assertSame('atlas.ai.programming.dev_execution_request.v1', $request['schema']);
        $this->assertSame($mission->id, $request['mission_id']);
        $this->assertSame($objective->id, $request['objective_id']);
        $this->assertSame($workOrder->id, $request['work_order_id']);
        $this->assertSame('programming.repair', $request['capability']);
        $this->assertSame('high', $request['risk_level']);
        $this->assertSame('execute_with_approval', $request['autonomy_level']);
        $this->assertSame(str_repeat('a', 64), $request['task_contract_hash']);
        $this->assertSame('Fix /healthz regression', $request['instructions']);
        $this->assertSame(['patch'], $request['expected_artifacts']);
        $this->assertSame(['tests/Feature/HealthzTest.php'], $request['expected_tests']);
    }

    public function test_adapt_promotes_trivial_classification_to_task_mission_type(): void
    {
        $mission = new AiMission([
            'id' => '00000000-0000-0000-0000-000000000010',
            'status' => MissionLifecycleService::STATUS_DRAFT,
            'mission_type' => MissionFactoryService::TYPE_TASK,
            'risk_level' => 'medium',
            'autonomy_level' => 'execute_with_approval',
        ]);

        $missionFactory = $this->createMock(MissionFactoryService::class);
        $missionFactory->expects($this->once())
            ->method('classify')
            ->with('fix typo in readme')
            ->willReturn(MissionFactoryService::TYPE_TRIVIAL);
        $missionFactory->expects($this->once())
            ->method('create')
            ->with(
                'fix typo in readme',
                $this->callback(static fn (array $options): bool => ($options['mission_type'] ?? null) === MissionFactoryService::TYPE_TASK),
            )
            ->willReturn($mission);

        $adapter = $this->makeAdapter(missionFactory: $missionFactory);
        $report = $adapter->adapt('fix typo in readme');

        $this->assertSame(MissionFactoryService::TYPE_TASK, $report['mission_type']);
    }

    public function test_adapt_marks_security_capability_as_high_risk_by_default(): void
    {
        $adapter = $this->makeAdapter();
        $report = $adapter->adapt('auditar seguranca do modulo auth');

        $this->assertSame('programming.security', $report['capability']);
        $this->assertSame('high', $report['risk_level']);
    }

    public function test_adapt_escalates_forge_keywords_to_obra_mission_type(): void
    {
        $mission = new AiMission([
            'id' => '00000000-0000-0000-0000-000000000020',
            'status' => MissionLifecycleService::STATUS_DRAFT,
            'mission_type' => MissionFactoryService::TYPE_OBRA,
            'risk_level' => 'medium',
            'autonomy_level' => 'execute_with_approval',
        ]);

        $missionFactory = $this->createMock(MissionFactoryService::class);
        $missionFactory->expects($this->never())->method('classify');
        $missionFactory->expects($this->once())
            ->method('create')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $options): bool => ($options['mission_type'] ?? null) === MissionFactoryService::TYPE_OBRA),
            )
            ->willReturn($mission);

        $adapter = $this->makeAdapter(missionFactory: $missionFactory);
        $report = $adapter->adapt('planejar obra multi-modulo com sdd');

        $this->assertSame(MissionFactoryService::TYPE_OBRA, $report['mission_type']);
    }

    private function makeAdapter(?MissionFactoryService $missionFactory = null): AtlasDevMissionAdapter
    {
        $mission = new AiMission([
            'id' => '00000000-0000-0000-0000-000000000099',
            'status' => MissionLifecycleService::STATUS_PLANNED,
            'mission_type' => MissionFactoryService::TYPE_TASK,
            'risk_level' => 'medium',
            'autonomy_level' => 'execute_with_approval',
        ]);

        $missionFactory ??= $this->createConfiguredMock(MissionFactoryService::class, [
            'classify' => MissionFactoryService::TYPE_TASK,
            'create' => $mission,
        ]);

        $objectives = new Collection([new AiObjective(['id' => '00000000-0000-0000-0000-000000000098'])]);
        $workOrders = new Collection([new AiWorkOrder(['id' => '00000000-0000-0000-0000-000000000097'])]);

        $decomposer = $this->createMock(ObjectiveDecomposerService::class);
        $decomposer->method('decompose')->willReturn($objectives);

        $workOrderFactory = $this->createMock(WorkOrderFactoryService::class);
        $workOrderFactory->method('plan')->willReturn($workOrders);

        $lifecycle = $this->createMock(MissionLifecycleService::class);

        return new AtlasDevMissionAdapter(
            missionFactory: $missionFactory,
            decomposer: $decomposer,
            workOrderFactory: $workOrderFactory,
            lifecycle: $lifecycle,
        );
    }
}
