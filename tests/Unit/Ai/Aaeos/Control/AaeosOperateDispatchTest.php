<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosLiveDispatchGateway;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosModeLiveDispatcher;
use App\Services\Ai\Aaeos\Control\Dispatch\DevLiveDispatcher;
use App\Services\Ai\Aaeos\Control\Dispatch\ForgeLiveDispatcher;
use PHPUnit\Framework\TestCase;

final class AaeosOperateDispatchTest extends TestCase
{
    public function test_live_dev_dispatch_builds_session_pack_via_gateway(): void
    {
        $gateway = new AaeosLiveDispatchGateway(dev: new DevLiveDispatcher);
        $result = $gateway->dispatch(AaeosExecutorMode::DEV, [
            'objective' => ['objective' => 'fix flaky login', 'raw' => 'fix flaky login'],
            'difficulty' => ['level' => 1],
            'admission' => ['allows_execution' => true],
            'adapter_accept' => ['operate_path' => ['atlas:cli:dev']],
        ], ['live' => true]);

        $this->assertSame('dispatched_live', $result['status']);
        $this->assertNotEmpty($result['effects']);
        $this->assertSame('dev_session_pack', $result['effects'][0]['kind']);
        $this->assertArrayHasKey('session_pack', $result);
        $this->assertTrue($result['session_pack']['elite_same_bar']);
    }

    public function test_live_forge_dispatch_stamps_spine(): void
    {
        $gateway = new AaeosLiveDispatchGateway(forge: new ForgeLiveDispatcher);
        $result = $gateway->dispatch(AaeosExecutorMode::FORGE, [
            'objective' => ['objective' => 'multi packet obra auth', 'raw' => 'obra'],
            'difficulty' => ['level' => 4],
            'adapter_accept' => [],
        ], ['live' => true]);

        $this->assertSame('dispatched_live', $result['status']);
        $this->assertSame('forge_intake_envelope', $result['effects'][0]['kind']);
        $this->assertArrayHasKey('aaeos_spine_gate', $result['forge_intake']);
    }

    public function test_plan_only_does_not_call_custom_autonomos_dispatcher(): void
    {
        $spy = new class implements AaeosModeLiveDispatcher
        {
            public int $calls = 0;

            public function mode(): string
            {
                return AaeosExecutorMode::AUTONOMOS;
            }

            public function liveDispatch(array $cyclePlan, array $options = []): array
            {
                $this->calls++;

                return ['status' => 'dispatched_live', 'effects' => [], 'next_commands' => [], 'provider_calls' => 0];
            }
        };

        $gateway = new AaeosLiveDispatchGateway(autonomos: $spy);
        $result = $gateway->dispatch(AaeosExecutorMode::AUTONOMOS, [
            'objective' => ['objective' => 'x'],
            'adapter_accept' => ['operate_path' => ['atlas:brain:next']],
        ], ['live' => false, 'plan_only' => true]);

        $this->assertSame('plan_only', $result['status']);
        $this->assertSame(0, $spy->calls);
    }

    public function test_cycle_live_dev_marks_dispatched_live(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runCycle('fix validation edge', [
            'source' => 'human',
            'interactive' => true,
            'live_dispatch' => true,
        ], [], false);

        $this->assertSame(AaeosExecutorMode::DEV, $receipt['mode']['mode']);
        $this->assertTrue($receipt['live_dispatch']);
        $this->assertSame('dispatched_live', $receipt['status']);
        $this->assertSame('dev_session_pack', $receipt['dispatch']['effects'][0]['kind'] ?? null);
    }

    public function test_dry_run_cycle_stays_compatible(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runAutonomosCycle('evolve with proof', [], true);
        $this->assertSame('dispatched', $receipt['status']);
        $this->assertTrue($receipt['dry_run']);
        $this->assertFalse($receipt['live_dispatch']);
        $this->assertContains('atlas:brain:next', $receipt['dispatch']['operate_path']);
    }
}
