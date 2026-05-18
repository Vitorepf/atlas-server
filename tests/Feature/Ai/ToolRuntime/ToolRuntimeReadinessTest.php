<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolRuntimeReadinessService;
use App\Services\Ai\ToolRuntime\ToolSeedDefinitions;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeReadinessTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_readiness_reports_seed_missing_until_seed_runs(): void
    {
        $report = app(ToolRuntimeReadinessService::class)->report();

        $seedCheck = collect($report['checks'])->firstWhere('name', 'seed:default_tools');
        $this->assertNotNull($seedCheck);
        $this->assertSame('failed', $seedCheck['status']);
        $this->assertFalse($report['ok']);
    }

    public function test_readiness_passes_after_seeding_defaults(): void
    {
        app(ToolDefinitionRegistryService::class)->seedDefaults(ToolSeedDefinitions::all());

        $report = app(ToolRuntimeReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'readiness summary='.json_encode($report['summary']));
        $this->assertSame(0, $report['summary']['failed']);
        $bridgeNames = collect($report['checks'])->pluck('name')->all();
        $this->assertContains('bridge:policy_runtime', $bridgeNames);
        $this->assertContains('bridge:evidence_runtime', $bridgeNames);
    }
}
