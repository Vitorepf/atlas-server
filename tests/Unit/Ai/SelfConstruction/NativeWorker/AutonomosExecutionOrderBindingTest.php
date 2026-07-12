<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\SelfConstruction\NativeWorker\AutonomosExecutionOrderBinding;
use InvalidArgumentException;
use Tests\Unit\Ai\EngineeringKernel\TypedEngineeringContractTest;
use Tests\TestCase;

final class AutonomosExecutionOrderBindingTest extends TestCase
{
    public function test_legacy_payload_has_no_binding_but_quality_foundry_order_is_typed(): void
    {
        self::assertNull(AutonomosExecutionOrderBinding::fromPayload(['task_packet_id' => 'legacy']));

        $order = $this->validOrder();
        $order['mode'] = 'autonomos';
        $order['duration_regime'] = 'continuous';
        $order['work_topology'] = 'workcell';
        $binding = AutonomosExecutionOrderBinding::fromArray(['execution_order' => $order]);

        self::assertSame(AutonomosExecutionOrderBinding::SCHEMA, $binding['schema']);
        self::assertSame($binding['order_hash'], ExecutionOrder::fromArray($binding['execution_order'])->canonicalHash());
    }

    public function test_dev_order_cannot_cross_the_autonomos_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('autonomos_execution_order_mode_invalid');

        AutonomosExecutionOrderBinding::fromArray(['execution_order' => $this->validOrder()]);
    }

    public function test_quality_foundry_action_without_order_fails_closed_in_the_native_executor_contract(): void
    {
        self::assertNull(AutonomosExecutionOrderBinding::fromPayload(['quality_foundry_required' => true]));
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
