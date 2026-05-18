<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\DecisionReceiptService;
use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\RouterRuntime\RuntimeDispatchService;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class DecisionReceiptHashTest extends TestCase
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

    public function test_router_decision_receipt_has_deterministic_hash(): void
    {
        $intent = app(IntentKernelService::class)->classify('plano de execucao das fases da Meta 7');
        $decision = app(DomainRouterService::class)->route($intent);
        $receipt = app(DecisionReceiptService::class)->recordRouterDecision($decision);

        $this->assertSame(RouterRuntimeCanon::RECEIPT_ROUTER_DECISION, $receipt->receipt_type);
        $this->assertSame(64, strlen((string) $receipt->receipt_hash));
        $this->assertSame($decision->id, $receipt->router_decision_id);
        $this->assertArrayHasKey('router_decision_uuid', $receipt->decision_summary);
    }

    public function test_runtime_dispatch_receipt_links_to_dispatch(): void
    {
        $intent = app(IntentKernelService::class)->classify('explique sucintamente o que é o atlas');
        $decision = app(DomainRouterService::class)->route($intent);
        $flow = app(FlowRouterService::class)->decideFlow($decision, $intent);
        $dispatch = app(RuntimeDispatchService::class)->dispatch($decision, $flow, $intent);
        $receipt = app(DecisionReceiptService::class)->recordRuntimeDispatch($dispatch, $decision);

        $this->assertSame(RouterRuntimeCanon::RECEIPT_RUNTIME_DISPATCH, $receipt->receipt_type);
        $this->assertSame($dispatch->id, $receipt->runtime_dispatch_id);
        $this->assertNotEmpty($receipt->receipt_hash);
    }
}
