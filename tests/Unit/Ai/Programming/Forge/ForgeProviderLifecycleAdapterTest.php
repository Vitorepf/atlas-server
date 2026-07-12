<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Forge;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\Forge\AtlasForgeProviderLifecycleAdapter;
use PHPUnit\Framework\TestCase;

final class ForgeProviderLifecycleAdapterTest extends TestCase
{
    public function test_kernel_route_is_ready_without_external_provider_call(): void
    {
        $router = $this->createMock(AtlasForgeProviderInvocationDriverRouter::class);
        $router->expects(self::never())->method('driverPlan');
        $adapter = new AtlasForgeProviderLifecycleAdapter($router);

        $result = $adapter->start(['provider' => 'atlas_kernel', 'cycle_id' => 'cycle-1']);

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['external_provider_call']);
        self::assertSame('shared_kernel_execution', $result['route']);
    }

    public function test_external_provider_plan_is_fail_closed_when_driver_plan_is_blocked(): void
    {
        $router = $this->createMock(AtlasForgeProviderInvocationDriverRouter::class);
        $router->expects(self::once())->method('driverPlan')->with('claude_cli', self::isType('array'))->willReturn([
            'plan_safe' => false, 'blockers' => ['provider_driver_not_configured'],
        ]);
        $router->expects(self::once())->method('callsExternalProvider')->with('claude_cli')->willReturn(true);
        $adapter = new AtlasForgeProviderLifecycleAdapter($router);

        $result = $adapter->start(['provider' => 'claude_cli', 'cycle_id' => 'cycle-1']);

        self::assertSame('blocked', $result['status']);
        self::assertSame('provider_driver_not_configured', $result['reason']);
        self::assertTrue($result['external_provider_call']);
    }

    public function test_observation_operations_never_invoke_external_provider(): void
    {
        $router = $this->createMock(AtlasForgeProviderInvocationDriverRouter::class);
        $adapter = new AtlasForgeProviderLifecycleAdapter($router);

        foreach (['poll', 'heartbeat', 'cancel'] as $operation) {
            $result = $adapter->{$operation}(['provider' => 'claude_cli', 'cycle_id' => 'cycle-1', 'fencing_token' => 4]);
            self::assertSame('ready', $result['status']);
            self::assertFalse($result['external_provider_call']);
        }
    }
}
