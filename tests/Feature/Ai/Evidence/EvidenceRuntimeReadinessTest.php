<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\EvidenceReadinessService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeReadinessTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_readiness_report_is_ok_when_everything_is_in_place(): void
    {
        $readiness = app(EvidenceReadinessService::class);
        $report = $readiness->report();

        $this->assertSame('atlas.ai.evidence.readiness.v1', $report['schema']);
        $this->assertTrue($report['ok'], 'readiness report should be ok');
        $this->assertGreaterThan(0, $report['summary']['passed']);
        $this->assertSame(0, $report['summary']['failed']);
    }

    public function test_command_readiness_action_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:evidence', [
            '--action' => 'readiness',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit, 'readiness action did not exit 0');
    }
}
