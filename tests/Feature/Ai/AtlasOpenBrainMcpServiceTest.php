<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\Concerns\CreatesAtlasEngineeringKnowledgeTables;
use Tests\Concerns\CreatesAtlasMemoryEntryRelationsTable;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\Concerns\CreatesAtlasTaskTables;
use Tests\TestCase;

class AtlasOpenBrainMcpServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;
    use CreatesAtlasMemoryEntryRelationsTable;
    use CreatesAtlasEngineeringCodeTables;
    use CreatesAtlasEngineeringKnowledgeTables;
    use CreatesAtlasTaskTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createAtlasMemoryEntryRelationsTable();
        $this->createAtlasEngineeringCodeTables();
        $this->createAtlasEngineeringKnowledgeTables();
        $this->createAtlasTaskTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasTaskTables();
        $this->dropAtlasEngineeringKnowledgeTables();
        $this->dropAtlasEngineeringCodeTables();
        $this->dropAtlasMemoryEntryRelationsTable();
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

    public function test_capabilities_returns_full_tool_inventory(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_capabilities', 'arguments' => []],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertSame(AtlasOpenBrainMcpService::PROTOCOL_VERSION, $structured['protocol_version']);
        // After phase 7: 16 + 5 new tools + atlas_domain_catalog = 22.
        $this->assertCount(22, $structured['tools']);
        $this->assertContains('atlas_memory_record', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_capabilities', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_domain_catalog', array_column($structured['tools'], 'name'));
    }

    public function test_domain_catalog_tool_exposes_ready_domain_flow_contracts(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 70, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_domain_catalog',
                'arguments' => ['flow' => 'programming.repair'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_domain_catalog', $structured['tool']);
        $this->assertSame('ok', $structured['status']);
        $this->assertTrue(data_get($structured, 'validation.valid'));
        $this->assertSame('programming', data_get($structured, 'domains.0.id'));
        $this->assertSame('ready', data_get($structured, 'domains.0.onboarding.status'));
        $this->assertSame('programming.repair', data_get($structured, 'flows.0.id'));
        $this->assertSame('dev_repair_executor', data_get($structured, 'flows.0.executor_preference'));
    }

    public function test_domain_catalog_tool_rejects_invalid_maturity_filter(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 71, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_domain_catalog',
                'arguments' => ['maturity' => 'experimental'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('invalid_maturity', $structured['error']);
    }

    public function test_workspace_info_returns_metadata_for_atlas_tracked_workspace(): void
    {
        \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision',
            'scope_type' => 'project',
            'scope_id' => 'atlas-server',
            'title' => 'Test decision',
            'body' => 'Test body',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_workspace_info',
                'arguments' => ['workspace' => '/Users/vitorepf/Develop/atlas/atlas-server'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertTrue($structured['atlas_tracked']);
        $this->assertGreaterThan(0, $structured['memory_entry_count']);
    }

    public function test_recent_changes_returns_files_and_index_freshness(): void
    {
        // Use atlas-server itself as test workspace — it IS a git repo
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_recent_changes',
                'arguments' => [
                    'workspace' => base_path(),  // base_path() resolves to atlas-server in this context
                    'since' => '7 days ago',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertArrayHasKey('changed_files', $structured);
        $this->assertIsArray($structured['changed_files']);
        $this->assertArrayHasKey('index_fresh', $structured);
    }

    public function test_recent_changes_rejects_non_git_workspace(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_recent_changes',
                'arguments' => [
                    'workspace' => '/tmp',  // Not a git repo
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('workspace_not_git_repo', $structured['error']);
    }

    public function test_decision_query_filters_to_decisions_only(): void
    {
        \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Use Postgres',
            'body' => 'Postgres é o DB padrão',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);
        \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'preference',
            'scope_type' => 'global',
            'title' => 'Tabs over spaces',
            'body' => 'Use tabs',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_decision_query',
                'arguments' => ['query' => 'database'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        // Every returned decision must have memory_type=decision (no preferences)
        foreach ($structured['decisions'] as $d) {
            $this->assertSame('decision', $d['memory_type'] ?? $d['type'] ?? null);
        }
    }

    public function test_decision_query_requires_query(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_decision_query', 'arguments' => []],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('query_required', $response['result']['structuredContent']['error']);
    }

    public function test_task_start_creates_open_task(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_task_start',
                'arguments' => [
                    'title' => 'Refator de billing',
                    'workspace' => '/Users/vitorepf/Develop/atlas/atlas-server',
                    'objective' => 'Implementar cobrança recorrente',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertArrayHasKey('task_id', $structured);

        $task = \App\Models\AtlasTask::find($structured['task_id']);
        $this->assertNotNull($task);
        $this->assertSame('open', $task->status);
    }

    public function test_task_progress_appends_event(): void
    {
        $task = \App\Models\AtlasTask::create([
            'title' => 'Test', 'status' => 'open', 'domain' => 'dev',
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_task_progress',
                'arguments' => [
                    'task_id' => (string) $task->id,
                    'milestone' => 'tests-passing',
                    'details' => 'All 12 unit tests green',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertSame(1, \App\Models\AtlasTaskEvent::where('task_id', $task->id)->count());
    }

    public function test_task_complete_sets_status_and_event(): void
    {
        $task = \App\Models\AtlasTask::create([
            'title' => 'Test', 'status' => 'open', 'domain' => 'dev',
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 15, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_task_complete',
                'arguments' => [
                    'task_id' => (string) $task->id,
                    'summary' => 'Implementação completa',
                    'files_changed' => ['app/X.php', 'tests/Y.php'],
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);

        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_memory_archive_sets_status_archived(): void
    {
        $entry = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Old decision',
            'body' => 'Outdated',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 16, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_archive',
                'arguments' => [
                    'memory_entry_id' => (string) $entry->id,
                    'reason' => 'Substituída por nova decisão sobre Postgres 16',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);

        $entry->refresh();
        $this->assertSame('archived', $entry->status);
        $this->assertNotNull($entry->archived_at);
    }

    public function test_memory_archive_rejects_missing_id(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 17, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_memory_archive', 'arguments' => []],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('memory_entry_id_required', $response['result']['structuredContent']['error']);
    }

    public function test_memory_link_creates_relation(): void
    {
        $entry1 = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Decision A', 'body' => 'A',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);
        $entry2 = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Decision B', 'body' => 'B',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 18, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_link',
                'arguments' => [
                    'source_id' => (string) $entry1->id,
                    'target_id' => (string) $entry2->id,
                    'relation_type' => 'duplicate',
                    'reason' => 'Mesma decisão registrada em scopes diferentes',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertArrayHasKey('relation_id', $structured);

        $relation = \App\Models\AtlasMemoryEntryRelation::find($structured['relation_id']);
        $this->assertNotNull($relation);
        $this->assertSame((string) $entry1->id, (string) $relation->source_memory_entry_id);
        $this->assertSame((string) $entry2->id, (string) $relation->target_memory_entry_id);
        $this->assertSame('duplicate', $relation->relation_type);
    }

    public function test_memory_link_rejects_unknown_relation_type(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 19, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_link',
                'arguments' => [
                    'source_id' => 'fake-uuid',
                    'target_id' => 'other-uuid',
                    'relation_type' => 'invented_type',
                ],
            ],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('invalid_relation_type', $response['result']['structuredContent']['error']);
    }

    public function test_memory_supersede_links_old_to_new_and_archives(): void
    {
        $oldEntry = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Use SQLite', 'body' => 'SQLite é o DB',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);
        $newEntry = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Use Postgres', 'body' => 'Postgres é o DB padrão',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_supersede',
                'arguments' => [
                    'old_entry_id' => (string) $oldEntry->id,
                    'new_entry_id' => (string) $newEntry->id,
                    'reason' => 'Decisão arquitetural mudou para Postgres',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);

        $oldEntry->refresh();
        $this->assertSame((string) $newEntry->id, (string) $oldEntry->superseded_by_id);
        $this->assertSame('archived', $oldEntry->status);
        $this->assertNotNull($oldEntry->archived_at);
    }

    public function test_memory_supersede_rejects_self_reference(): void
    {
        $entry = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Test', 'body' => 'Test',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_supersede',
                'arguments' => [
                    'old_entry_id' => (string) $entry->id,
                    'new_entry_id' => (string) $entry->id,
                ],
            ],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('cannot_supersede_self', $response['result']['structuredContent']['error']);
    }

    public function test_memory_get_returns_full_entry_when_provider_safe(): void
    {
        $entry = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Drill-down test', 'body' => 'Full body content here',
            'summary' => 'Short summary',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'tags' => ['testing', 'drill-down'],
            'metadata' => ['evidence' => ['file.md']],
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_get',
                'arguments' => ['memory_entry_id' => (string) $entry->id],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertSame((string) $entry->id, (string) $structured['entry']['id']);
        $this->assertSame('Drill-down test', $structured['entry']['title']);
        $this->assertSame('Full body content here', $structured['entry']['body']);
    }

    public function test_memory_get_blocks_non_provider_safe(): void
    {
        $entry = \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Sensitive', 'body' => 'Secret',
            'status' => 'active', 'privacy_class' => 'secret',
            'external_ai_allowed' => false, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 23, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_get',
                'arguments' => ['memory_entry_id' => (string) $entry->id],
            ],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('not_provider_safe', $response['result']['structuredContent']['error']);
    }

    public function test_module_info_returns_module_with_symbols(): void
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
            'symbol_name' => 'recall',
            'symbol_type' => 'method',
            'language' => 'php',
            'file_path' => 'app/Services/Memory/AtlasHybridMemoryRetrievalService.php',
            'source_hash' => sha1('recall'),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 24, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_module_info',
                'arguments' => ['slug' => 'memory-service'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertNotNull($structured['module']);
    }

    public function test_module_info_returns_not_found_for_unknown_slug(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 25, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_module_info', 'arguments' => ['slug' => 'nonexistent-module']],
        ]);

        $this->assertFalse($response['result']['structuredContent']['ok']);
        $this->assertSame('module_not_found', $response['result']['structuredContent']['error']);
    }

    public function test_route_info_returns_routes_matching_pattern(): void
    {
        $module = AtlasEngineeringCodeModule::create([
            'slug' => 'routes',
            'name' => 'Routes',
            'layer' => 'http',
            'primary_language' => 'php',
            'root_path' => 'routes',
            'source_hash' => sha1('routes'),
        ]);
        AtlasEngineeringCodeSymbol::create([
            'module_id' => $module->id,
            'symbol_name' => 'GET /api/atlas/memory',
            'symbol_type' => 'route',
            'language' => 'php',
            'file_path' => 'routes/api.php',
            'source_hash' => sha1('GET /api/atlas/memory'),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 26, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_route_info',
                'arguments' => ['path' => 'memory'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertIsArray($structured['routes']);
    }

    public function test_test_for_returns_test_symbols_matching_target(): void
    {
        $module = AtlasEngineeringCodeModule::create([
            'slug' => 'tests-feature-billing',
            'name' => 'Tests',
            'layer' => 'test',
            'primary_language' => 'php',
            'root_path' => 'tests/Feature',
            'source_hash' => sha1('tests-feature-billing'),
        ]);
        AtlasEngineeringCodeSymbol::create([
            'module_id' => $module->id,
            'symbol_name' => 'test_billing_creates_invoice',
            'symbol_type' => 'test_method',
            'language' => 'php',
            'file_path' => 'tests/Feature/BillingTest.php',
            'source_hash' => sha1('test_billing_creates_invoice'),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 27, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_test_for',
                'arguments' => ['target' => 'billing'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertNotEmpty($structured['tests']);
    }

    public function test_context_for_composes_recall_code_and_docs(): void
    {
        \App\Models\AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'billing canonical', 'body' => 'Use Stripe',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 28, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_context_for',
                'arguments' => ['task_description' => 'implementar cobrança recorrente com billing'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertArrayHasKey('memory', $structured);
        $this->assertArrayHasKey('code', $structured);
        $this->assertArrayHasKey('docs', $structured);
    }
}
