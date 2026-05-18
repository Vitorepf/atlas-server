<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\RouterRuntime\RuntimeDispatchService;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class DispatchSimulatedTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_low_risk_dispatch_is_simulated_only(): void
    {
        $intent = app(IntentKernelService::class)->classify('explique como funciona o cache de prompt');
        $decision = app(DomainRouterService::class)->route($intent);
        $flow = app(FlowRouterService::class)->decideFlow($decision, $intent);
        $dispatch = app(RuntimeDispatchService::class)->dispatch($decision, $flow, $intent);

        $this->assertSame(RouterRuntimeCanon::DISPATCH_SIMULATED, $dispatch->dispatch_status);
        $this->assertArrayHasKey('simulation', $dispatch->dispatch_payload);
        $this->assertTrue($dispatch->dispatch_payload['simulation']);
        $this->assertSame('v1_simulated_only', $dispatch->dispatch_payload['dispatch_mode']);
        $this->assertSame(64, strlen((string) $dispatch->receipt_hash));
    }

    public function test_high_risk_dispatch_is_planned_not_executed(): void
    {
        $intent = app(IntentKernelService::class)->classify('valuation de carteira e simulacao day trade');
        $decision = app(DomainRouterService::class)->route($intent);
        $flow = app(FlowRouterService::class)->decideFlow($decision, $intent);
        $dispatch = app(RuntimeDispatchService::class)->dispatch($decision, $flow, $intent);

        $this->assertSame(RouterRuntimeCanon::DISPATCH_PLANNED, $dispatch->dispatch_status);
        $this->assertTrue($dispatch->dispatch_payload['policy_required']);
        $this->assertTrue($dispatch->dispatch_payload['simulation']);
    }
}
