<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\CyberReadinessService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainReadinessTest extends TestCase
{
    use CreatesCyberRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCyberRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropCyberRuntimeTables();
        parent::tearDown();
    }

    public function test_readiness_passes_when_runtime_wired(): void
    {
        /** @var CyberReadinessService $svc */
        $svc = app(CyberReadinessService::class);
        $report = $svc->report();

        $this->assertSame('atlas.ai.cyber.readiness.v1', $report['schema']);
        $this->assertTrue($report['ok']);
        $this->assertSame(0, $report['summary']['failed']);
    }

    public function test_command_readiness_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:cyber-domain', [
            '--action' => 'readiness',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }
}
