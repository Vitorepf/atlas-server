<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\AutomationDomainCanon;
use App\Services\Ai\AutomationDomain\AutomationDomainException;
use App\Services\Ai\AutomationDomain\ToolEvolutionLoopService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainEvolutionLoopTest extends TestCase
{
    use CreatesAutomationDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAutomationDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropAutomationDomainTables();
        parent::tearDown();
    }

    public function test_emits_success_streak_event_with_keep_recommendation(): void
    {
        $event = app(ToolEvolutionLoopService::class)->emitEvent([
            'tool_id' => 'atlas.demo.tool',
            'event_kind' => AutomationDomainCanon::EVOLUTION_SUCCESS_STREAK,
            'observation' => ['passed' => 8, 'failed' => 0, 'sample_size' => 8],
        ]);

        $this->assertSame(AutomationDomainCanon::EVOLUTION_SUCCESS_STREAK, $event->event_kind);
        $this->assertSame(AutomationDomainCanon::EVOLUTION_RECOMMENDATION_KEEP, $event->recommendation);
        $this->assertNotEmpty($event->event_hash);
    }

    public function test_emits_failure_spike_event_with_replace_recommendation_by_default(): void
    {
        $event = app(ToolEvolutionLoopService::class)->emitEvent([
            'tool_id' => 'atlas.demo.broken_tool',
            'event_kind' => AutomationDomainCanon::EVOLUTION_FAILURE_SPIKE,
            'observation' => ['passed' => 1, 'failed' => 5, 'sample_size' => 6],
        ]);

        $this->assertSame(AutomationDomainCanon::EVOLUTION_RECOMMENDATION_REPLACE, $event->recommendation);
    }

    public function test_rejects_unknown_event_kind(): void
    {
        $this->expectException(AutomationDomainException::class);
        app(ToolEvolutionLoopService::class)->emitEvent([
            'tool_id' => 'atlas.demo.x',
            'event_kind' => 'invalid_event_kind',
            'observation' => [],
        ]);
    }

    public function test_observe_from_invocations_is_empty_when_tool_runtime_absent(): void
    {
        // Tool Runtime tables not created in this test fixture.
        $events = app(ToolEvolutionLoopService::class)->observeFromInvocations();
        $this->assertSame([], $events);
    }
}
