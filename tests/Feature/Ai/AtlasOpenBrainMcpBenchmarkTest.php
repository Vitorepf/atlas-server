<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasOpenBrainMcpService;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\Concerns\CreatesAtlasEngineeringKnowledgeTables;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

class AtlasOpenBrainMcpBenchmarkTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;
    use CreatesAtlasEngineeringKnowledgeTables;
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createAtlasEngineeringCodeTables();
        $this->createAtlasEngineeringKnowledgeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasEngineeringKnowledgeTables();
        $this->dropAtlasEngineeringCodeTables();
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_recall_completes_under_500ms_warm(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // Warm up
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_memory_recall', 'arguments' => ['query' => 'warmup']]]);

        $start = microtime(true);
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_memory_recall', 'arguments' => ['query' => 'migrations']]]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed, "Recall took {$elapsed}s warm; budget is 0.5s.");
    }

    public function test_capabilities_completes_under_100ms_warm(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_capabilities', 'arguments' => []]]); // warmup

        $start = microtime(true);
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_capabilities', 'arguments' => []]]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed, "Capabilities took {$elapsed}s; budget is 100ms.");
    }
}
