<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Programming\Kernel\ProgrammingAdapterSmokeService;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterSmokeTest extends TestCase
{
    use CreatesProgrammingAdapterTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgrammingAdapterTables();
    }

    protected function tearDown(): void
    {
        $this->dropProgrammingAdapterTables();
        parent::tearDown();
    }

    public function test_smoke_runs_dev_and_forge_paths_end_to_end(): void
    {
        $report = app(ProgrammingAdapterSmokeService::class)->run();

        $this->assertTrue($report['ok'], 'smoke ok expected, got: '.json_encode($report));
        $this->assertSame('programming', $report['manifest']['domain_id']);
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $report['dev']['mission_status']);
        $this->assertSame('passed', $report['dev']['certification_status']);
        $this->assertSame(64, strlen((string) $report['dev']['certification_hash']));
        $this->assertNotNull($report['forge']['handoff_receipt_hash']);
        $this->assertSame('pending', $report['forge']['handoff_status']);
        $this->assertContains('workspace', $report['forge']['context_pack_keys']);
        $this->assertContains('task_contract', $report['forge']['context_pack_keys']);
        $this->assertContains('evidence_refs', $report['forge']['context_pack_keys']);
    }

    public function test_smoke_command_returns_ok_via_artisan(): void
    {
        $exit = $this->artisan('atlas:ai:programming-adapter', [
            '--action' => 'smoke',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
