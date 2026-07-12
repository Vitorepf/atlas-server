<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\NativeWorker\AutonomosExecutionOrderBinding;
use App\Console\Commands\AtlasTaskSeedGovLanesCommand;
use Tests\Unit\Ai\EngineeringKernel\TypedEngineeringContractTest;
use Tests\TestCase;

final class QualityFoundryTaskPacketPropagationTest extends TestCase
{
    public function test_brain_packet_freezes_and_carries_the_autonomos_order(): void
    {
        $order = $this->validOrder();
        $order['mode'] = 'autonomos';
        $order['duration_regime'] = 'continuous';
        $order['work_topology'] = 'workcell';
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'Evoluir uma capacidade com prova completa',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/FooTest.php'],
            'required_evidence' => ['tests_or_gates_result'],
            'quality_foundry_required' => true,
            'execution_order' => $order,
        ]);

        self::assertSame('planned', $packet['status']);
        self::assertTrue($packet['quality_foundry_required']);
        self::assertSame(
            $packet['execution_order_hash'],
            AutonomosExecutionOrderBinding::fromPayload($packet)['order_hash'],
        );
    }

    public function test_quality_foundry_packet_without_brain_order_is_blocked_at_source(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'Evoluir uma capacidade com prova completa',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'quality_foundry_required' => true,
        ]);

        self::assertSame('blocked', $packet['status']);
        self::assertContains('quality_foundry_execution_order_missing', $packet['blocking_reasons']);
        self::assertArrayNotHasKey('execution_order', $packet);
    }

    public function test_governed_seed_command_forwards_quality_foundry_order_to_builder(): void
    {
        $command = (new \ReflectionClass(AtlasTaskSeedGovLanesCommand::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($command))->getMethod('toPacketInput');
        $method->setAccessible(true);
        $order = $this->validOrder();
        $order['mode'] = 'autonomos';
        $order['duration_regime'] = 'continuous';
        $order['work_topology'] = 'workcell';

        /** @var array<string,mixed> $input */
        $input = $method->invoke($command, [
            'task_packet_id' => 'seed-qf', 'objective' => 'Quality Foundry seed',
            'quality_foundry_required' => true, 'execution_order' => $order,
        ], 'seed-qf', []);

        self::assertTrue($input['quality_foundry_required']);
        self::assertSame($order, $input['execution_order']);
    }

    /** @return array<string,mixed> */
    private function validOrder(): array
    {
        $test = new TypedEngineeringContractTest('test_execution_order_is_complete_canonical_and_deterministic');
        $method = (new \ReflectionClass($test))->getMethod('validOrder');
        $method->setAccessible(true);

        /** @var array<string,mixed> $order */
        $order = $method->invoke($test);

        return $order;
    }
}
