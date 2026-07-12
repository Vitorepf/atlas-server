<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Console\Commands\AtlasBrainSeedCommand;
use PHPUnit\Framework\TestCase;

final class AtlasBrainSeedPacketOrderPropagationTest extends TestCase
{
    public function test_seed_forwards_quality_foundry_order_without_rewriting_it(): void
    {
        $command = (new \ReflectionClass(AtlasBrainSeedCommand::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($command))->getMethod('toPacketInput');
        $order = [
            'schema_version' => 'atlas.execution_order.v2',
            'mode' => 'autonomos',
            'risk_class' => 'R3',
            'duration_regime' => 'continuous',
            'work_topology' => 'workcell',
            'product_intent_verdict_hash' => str_repeat('a', 64),
            'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64),
        ];

        $input = $method->invoke($command, [
            'task_packet_id' => 'brain-order-1',
            'objective' => 'Usar a ordem Quality Foundry recebida pelo Brain',
            'quality_foundry_required' => true,
            'execution_order' => $order,
            'execution_order_hash' => hash('sha256', json_encode($order)),
        ], 'brain-order-1');

        self::assertTrue($input['quality_foundry_required']);
        self::assertSame($order, $input['execution_order']);
        self::assertSame(hash('sha256', json_encode($order)), $input['execution_order_hash']);
    }

    public function test_seed_does_not_claim_quality_foundry_when_spec_has_no_order(): void
    {
        $command = (new \ReflectionClass(AtlasBrainSeedCommand::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($command))->getMethod('toPacketInput');

        $input = $method->invoke($command, [
            'task_packet_id' => 'brain-order-2',
            'objective' => 'Spec legado sem ordem',
        ], 'brain-order-2');

        self::assertArrayNotHasKey('quality_foundry_required', $input);
        self::assertArrayNotHasKey('execution_order', $input);
        self::assertArrayNotHasKey('execution_order_hash', $input);
    }
}
