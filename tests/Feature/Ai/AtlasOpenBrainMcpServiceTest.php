<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\Concerns\CreatesAtlasEngineeringKnowledgeTables;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

class AtlasOpenBrainMcpServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;
    use CreatesAtlasEngineeringCodeTables;
    use CreatesAtlasEngineeringKnowledgeTables;

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

    public function test_memory_record_creates_entry_with_provider_safe_defaults(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_record',
                'arguments' => [
                    'memory_type' => 'decision',
                    'scope_type' => 'project',
                    'scope_id' => 'atlas-server',
                    'title' => 'Triggers de updated_at vivem no DB',
                    'body' => 'Toda tabela com updated_at precisa de trg_<table>_updated_at chamando set_updated_at(). Eloquent não cobre bulk update, raw SQL ou outros workers.',
                    'evidence' => ['CLAUDE.md', 'app/Console/Commands/AtlasUpdatedAtAuditCommand.php'],
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_memory_record', $structured['tool']);
        $this->assertArrayHasKey('memory_entry_id', $structured);

        $entry = AtlasMemoryEntry::find($structured['memory_entry_id']);
        $this->assertNotNull($entry);
        $this->assertSame('decision', $entry->memory_type);
        $this->assertSame('project', $entry->scope_type);
        $this->assertSame('atlas-server', $entry->scope_id);
        $this->assertSame('active', $entry->status);
        $this->assertSame('normal', $entry->privacy_class);
        $this->assertTrue((bool) $entry->external_ai_allowed);
    }

    public function test_memory_record_rejects_unknown_memory_type(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_record',
                'arguments' => [
                    'memory_type' => 'fofoca',
                    'scope_type' => 'global',
                    'title' => 'X',
                    'body' => 'Y',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('invalid_memory_type', $structured['error']);
    }

    public function test_code_find_relevant_returns_symbols_matching_query(): void
    {
        $module = AtlasEngineeringCodeModule::create([
            'slug' => 'memory-service',
            'name' => 'Memory Service',
            'layer' => 'service',
            'primary_language' => 'php',
            'root_path' => 'app/Services/Memory',
            'source_hash' => sha1('memory-service'),
        ]);

        AtlasEngineeringCodeSymbol::create([
            'module_id' => $module->id,
            'symbol_name' => 'AtlasHybridMemoryRetrievalService',
            'symbol_type' => 'class',
            'language' => 'php',
            'file_path' => 'app/Services/Memory/AtlasHybridMemoryRetrievalService.php',
            'source_hash' => sha1('AtlasHybridMemoryRetrievalService'),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_code_find_relevant',
                'arguments' => ['query' => 'memory', 'limit' => 5],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertNotEmpty($structured['symbols']);
    }

    public function test_code_find_relevant_requires_query(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_code_find_relevant', 'arguments' => []],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('query_required', $response['result']['structuredContent']['error']);
    }

    public function test_docs_lookup_returns_matching_kb_items(): void
    {
        AtlasEngineeringKnowledgeItem::create([
            'slug' => 'memory-core-failure-modes',
            'title' => 'Memory Core Failure Modes',
            'category' => 'engineering',
            'status' => 'active',
            'canonical_path' => 'docs/engineering-knowledge-base/memory-core-failure-modes.md',
            'source_hash' => sha1('memory-core-failure-modes'),
            'content_hash' => sha1('memory-core-failure-modes-content'),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_docs_lookup', 'arguments' => ['query' => 'failure']],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertNotEmpty($structured['docs']);
    }

    public function test_docs_lookup_requires_query(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_docs_lookup', 'arguments' => []],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('query_required', $response['result']['structuredContent']['error']);
    }
}
