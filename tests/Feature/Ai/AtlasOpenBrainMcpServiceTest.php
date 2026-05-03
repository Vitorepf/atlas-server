<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

class AtlasOpenBrainMcpServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
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
}
