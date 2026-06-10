<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolHealthService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use App\Services\Ai\ToolRuntime\ToolPlanningService;
use App\Services\Ai\ToolRuntime\ToolRuntimeControlPlaneService;
use App\Services\Ai\ToolRuntime\ToolSeedDefinitions;
use App\Services\Ai\ToolRuntime\ToolValidationService;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeControlPlaneTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_ai.tool_runtime.strict_mode', false);
        $this->createToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_snapshot_aggregates_definitions_plans_invocations_receipts_health_and_validations(): void
    {
        $registry = app(ToolDefinitionRegistryService::class);
        $registry->seedDefaults(ToolSeedDefinitions::all());

        $tool = $registry->findByToolId('docs.search');
        app(ToolPlanningService::class)->plan('pesquisar fonte primaria sobre tema X');
        app(ToolInvocationService::class)->invoke($tool, ['query' => 'fonte X']);
        app(ToolHealthService::class)->doctor();
        app(ToolValidationService::class)->validate($tool, 'schema');

        $snapshot = app(ToolRuntimeControlPlaneService::class)->snapshot();

        $this->assertSame(ToolRuntimeControlPlaneService::SCHEMA, $snapshot['schema']);
        $this->assertSame(10, $snapshot['summary']['definitions']);
        $this->assertGreaterThanOrEqual(1, $snapshot['summary']['plans']);
        $this->assertGreaterThanOrEqual(1, $snapshot['summary']['invocations']);
        $this->assertGreaterThanOrEqual(1, $snapshot['summary']['receipts']);
        $this->assertGreaterThanOrEqual(1, $snapshot['summary']['health_checks']);
        $this->assertGreaterThanOrEqual(1, $snapshot['summary']['validations']);
        $this->assertArrayHasKey('policy_runtime_available', $snapshot['bridges']);
        $this->assertArrayHasKey('evidence_runtime_available', $snapshot['bridges']);
    }
}
