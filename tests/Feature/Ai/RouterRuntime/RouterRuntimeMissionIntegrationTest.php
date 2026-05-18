<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\ObjectiveRoutingService;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class RouterRuntimeMissionIntegrationTest extends TestCase
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

    public function test_objective_routing_is_tolerant_when_mission_foundation_absent(): void
    {
        // Mission Foundation tables are NOT created here, so the objective
        // service must degrade gracefully and return a structured payload
        // without crashing the pipeline.
        $intent = app(IntentKernelService::class)->classify('plano para entregar a meta 7');
        $objective = app(ObjectiveRoutingService::class)->resolveObjective($intent);

        $this->assertSame($intent->id, $objective['intent_id']);
        $this->assertSame($intent->intent_type, $objective['intent_type']);
        $this->assertNull($objective['mission'], 'mission ref must be null when no mission resolves');
        $this->assertArrayHasKey('derived_domain', $objective);
    }
}
