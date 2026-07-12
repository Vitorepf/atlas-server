<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleException;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionPort;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleService;
use Tests\TestCase;

final class ForgeWorkPacketKernelExecutionTest extends TestCase
{
    public function test_real_running_cycle_builds_forge_order_and_routes_to_shared_kernel_port(): void
    {
        $captured = null;
        $port = new class($captured) implements ForgeWorkPacketExecutionPort
        {
            public function __construct(private mixed &$captured) {}

            public function execute(ExecutionOrder $order): EngineeringOutcome
            {
                $this->captured = $order;
                throw new \RuntimeException('fixture_kernel_stop_after_capture');
            }
        };
        $this->app->instance(ForgeWorkPacketExecutionPort::class, $port);

        $intake = new AiForgeIntake([
            'id' => 'intake-1',
            'intake_hash' => str_repeat('a', 64),
            'context_pack_hash' => str_repeat('b', 64),
        ]);
        $packet = new AiForgeWorkPacket([
            'id' => 'packet-1',
            'packet_id' => 'packet-canonical-1',
            'packet_hash' => str_repeat('c', 64),
            'objective' => 'Implement bounded change',
            'scope' => 'app/Services/Example.php',
            'expected_files' => ['app/Services/Example.php'],
            'risk_band' => 'R4',
        ]);
        $cycle = new AiForgeWorkPacketExecutionCycle([
            'uuid' => 'cycle-1',
            'execution_mode' => ForgeWorkPacketExecutionCycleCanon::MODE_REAL,
            'status' => ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING,
            'execution_plan' => [
                'scope_reservation' => [
                    'id' => 'reservation-1',
                    'fencing_token' => 7,
                    'released_at' => null,
                ],
            ],
        ]);

        try {
            app(ForgeWorkPacketExecutionCycleService::class)->executeRealCycle(
                $intake,
                $packet,
                $cycle,
                '/tmp/forge-workspace',
                str_repeat('d', 40),
                'operator-1',
                ['provider' => 'fixture-provider', 'model' => 'fixture-model'],
            );
            self::fail('shared kernel fixture should stop after capturing the order');
        } catch (\RuntimeException $exception) {
            self::assertSame('fixture_kernel_stop_after_capture', $exception->getMessage());
        }

        self::assertInstanceOf(ExecutionOrder::class, $captured);
        self::assertSame('forge', $captured->mode);
        self::assertSame('R4', $captured->riskClass);
        self::assertSame('DAG', $captured->workTopology);
        self::assertTrue($captured->toolPermissions['mutate']);
        self::assertSame('reservation-1', $captured->authorityEnvelope['lease_id']);
        self::assertSame(7, $captured->authorityEnvelope['fencing_token']);
        self::assertSame('fixture-provider', $captured->providerRoute['provider']);
        self::assertSame('forge-cycle:cycle-1', $captured->idempotencyKey);
    }

    public function test_safe_simulation_cannot_reach_kernel_execution_port(): void
    {
        $calls = 0;
        $this->app->instance(ForgeWorkPacketExecutionPort::class, new class($calls) implements ForgeWorkPacketExecutionPort
        {
            public function __construct(private int &$calls) {}

            public function execute(ExecutionOrder $order): EngineeringOutcome
            {
                $this->calls++;
                throw new \LogicException('simulation must not invoke shared kernel');
            }
        });

        $cycle = new AiForgeWorkPacketExecutionCycle([
            'uuid' => 'cycle-sim',
            'execution_mode' => ForgeWorkPacketExecutionCycleCanon::MODE_SAFE_SIMULATION,
            'status' => ForgeWorkPacketExecutionCycleCanon::STATUS_RUNNING,
            'execution_plan' => [],
        ]);

        $this->expectException(ForgeWorkPacketExecutionCycleException::class);
        $this->expectExceptionMessage('real_kernel_execution_requires_running_cycle');
        try {
            app(ForgeWorkPacketExecutionCycleService::class)->executeRealCycle(
                new AiForgeIntake(['intake_hash' => str_repeat('a', 64)]),
                new AiForgeWorkPacket(['packet_hash' => str_repeat('b', 64)]),
                $cycle,
                '/tmp/forge-workspace',
                str_repeat('d', 40),
                'operator-1',
            );
        } finally {
            self::assertSame(0, $calls);
        }
    }
}
