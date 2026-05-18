<?php

namespace Tests\Feature\Ai\Strategy;

use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\Strategy\StrategyControlPlaneProjection;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainControlPlaneTest extends TestCase
{
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    public function test_control_plane_returns_ready_status_with_totals(): void
    {
        /** @var OpportunityRadarService $svc */
        $svc = app(OpportunityRadarService::class);
        $svc->create([
            'title' => 'Sample opportunity',
            'problem' => 'p',
            'icp' => 'i',
            'pain' => 'x',
            'urgency' => OpportunityRadarService::URGENCY_MEDIUM,
            'market' => ['size' => 'small'],
            'competitors' => [['name' => 'n']],
            'risks' => ['r'],
        ]);

        /** @var StrategyControlPlaneProjection $svc */
        $cp = app(StrategyControlPlaneProjection::class);
        $snap = $cp->snapshot();

        $this->assertSame('atlas.ai.strategy.control_plane.v1', $snap['schema']);
        $this->assertSame('ready', $snap['status']);
        $this->assertGreaterThanOrEqual(1, $snap['totals']['opportunities']);
        $this->assertArrayHasKey('recent', $snap['opportunities']);
    }

    public function test_control_plane_command_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:strategy-domain', [
            '--action' => 'control-plane',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
