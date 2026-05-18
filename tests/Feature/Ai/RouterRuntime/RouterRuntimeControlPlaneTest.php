<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\DecisionReceiptService;
use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeControlPlaneService;
use App\Services\Ai\RouterRuntime\RuntimeDispatchService;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class RouterRuntimeControlPlaneTest extends TestCase
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

    public function test_snapshot_has_all_sections_and_aggregates(): void
    {
        $kernel = app(IntentKernelService::class);
        $router = app(DomainRouterService::class);
        $flow = app(FlowRouterService::class);
        $dispatchSvc = app(RuntimeDispatchService::class);
        $receipts = app(DecisionReceiptService::class);

        foreach ([
            'implemente exporter csv com testes',
            'pesquise estado da arte sobre agentes',
            'rascunhe campanha de copy para growth',
        ] as $input) {
            $intent = $kernel->classify($input);
            $decision = $router->route($intent);
            $route = $flow->decideFlow($decision, $intent);
            $dispatch = $dispatchSvc->dispatch($decision, $route, $intent);
            $receipts->recordRouterDecision($decision);
            $receipts->recordRuntimeDispatch($dispatch, $decision);
        }

        $snapshot = app(RouterRuntimeControlPlaneService::class)->snapshot();

        $this->assertSame('atlas.ai.router_runtime.control_plane.v1', $snapshot['schema']);
        foreach (['classifications', 'decisions', 'routes', 'dispatches', 'receipts'] as $section) {
            $this->assertArrayHasKey($section, $snapshot, "missing section [{$section}]");
            $this->assertArrayHasKey('count', $snapshot[$section]);
        }
        $this->assertSame(3, $snapshot['classifications']['count']);
        $this->assertSame(3, $snapshot['decisions']['count']);
        $this->assertSame(3, $snapshot['routes']['count']);
        $this->assertSame(3, $snapshot['dispatches']['count']);
        $this->assertSame(6, $snapshot['receipts']['count']);
    }
}
