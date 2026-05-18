<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\RouterRuntimeReadinessService;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class RouterRuntimeReadinessTest extends TestCase
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

    public function test_readiness_returns_ok_with_all_checks_passing(): void
    {
        $report = app(RouterRuntimeReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'expected readiness ok=true, got '.json_encode($report['summary']));
        $this->assertSame('atlas.ai.router_runtime.readiness.v1', $report['schema']);
        $this->assertSame(0, $report['summary']['failed']);
    }

    public function test_readiness_lists_required_checks(): void
    {
        $report = app(RouterRuntimeReadinessService::class)->report();
        $names = collect($report['checks'])->pluck('name')->all();

        foreach ([
            'table:ai_atlas_intent_classifications',
            'table:ai_atlas_router_decisions',
            'table:ai_atlas_flow_routes',
            'table:ai_atlas_runtime_dispatches',
            'table:ai_atlas_decision_receipts',
            'service:IntentKernelService',
            'service:DomainRouterService',
            'service:FlowRouterService',
            'service:RuntimeDispatchService',
            'service:DecisionReceiptService',
            'canon:intent_to_flow_map_coverage',
            'bridge:policy_tolerant',
            'bridge:evidence_tolerant',
        ] as $expected) {
            $this->assertContains($expected, $names, "missing readiness check [{$expected}]");
        }
    }

    public function test_readiness_fails_when_table_missing(): void
    {
        $this->dropRouterRuntimeTables();
        $report = app(RouterRuntimeReadinessService::class)->report();
        $this->assertFalse($report['ok']);
        $this->assertGreaterThan(0, $report['summary']['failed']);
    }
}
