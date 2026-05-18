<?php

namespace Tests\Feature\Ai\Strategy;

use App\Services\Ai\Strategy\StrategyReadinessService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainReadinessTest extends TestCase
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

    public function test_readiness_passes_when_strategy_runtime_is_wired(): void
    {
        /** @var StrategyReadinessService $svc */
        $svc = app(StrategyReadinessService::class);
        $report = $svc->report();

        $this->assertSame('atlas.ai.strategy.readiness.v1', $report['schema']);
        $this->assertTrue($report['ok'], 'readiness should be ok');
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertGreaterThan(0, $report['summary']['passed']);
    }

    public function test_readiness_command_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:strategy-domain', [
            '--action' => 'readiness',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
