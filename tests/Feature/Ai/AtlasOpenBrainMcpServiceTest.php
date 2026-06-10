<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\Concerns\CreatesAtlasEngineeringKnowledgeTables;
use Tests\Concerns\CreatesAtlasMemoryEntryRelationsTable;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\Concerns\CreatesAtlasTaskTables;
use Tests\TestCase;

class AtlasOpenBrainMcpServiceTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;
    use CreatesAtlasEngineeringKnowledgeTables;
    use CreatesAtlasMemoryEntryRelationsTable;
    use CreatesAtlasMemoryEntryTable;
    use CreatesAtlasTaskTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createAtlasMemoryEntryRelationsTable();
        $this->createAtlasEngineeringCodeTables();
        $this->createAtlasEngineeringKnowledgeTables();
        $this->createAtlasTaskTables();
        $this->createBlackboardTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_inbox_items');
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
        // After Governance MCP: 16 + 5 new tools + domain/architecture/governance/schedule/kernel/provider/market/release/source/inbox/agent/receipt/projection/readiness/runtime reports = 42,
        // + AP-811 code-graph traversal tools (atlas_code_neighbors/atlas_code_path/atlas_code_explain) = 45,
        // + AP-813 compression layer retrieve tool (atlas_ccr_retrieve) = 46,
        // + AP-814 M-8 cross-domain query tool (atlas_cross_domain_query) = 47,
        // + Salto-1 F2 AURG brain query tool (atlas_aurg_query) = 48,
        // + Salto-2 F3 closed-loop mission history tool (atlas_mission_history) = 49,
        // + AOBG N1.F1 unified context-pack front door (atlas_context_pack) = 50,
        // + AOBG N1.F2 governed write-back tools (atlas_record_outcome, atlas_propose_learning) = 52,
        // + AOBG N1.F3 multi-project workspace status tool (atlas_workspace_status) = 53,
        // + AOBG N2.F4 blackboard coordination tools (atlas_claim_task, atlas_blackboard_status) = 55.
        $this->assertCount(55, $structured['tools']);
        $this->assertContains('atlas_aurg_query', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_mission_history', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_context_pack', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_record_outcome', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_propose_learning', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_workspace_status', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_claim_task', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_blackboard_status', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_code_neighbors', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_code_path', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_code_explain', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_ccr_retrieve', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_cross_domain_query', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_memory_record', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_capabilities', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_domain_catalog', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_architecture_validate', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_architecture_operations', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_architecture_readiness', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_runtime_boundary', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_session_bootstrap', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_feature_placement', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_docs_split_plan', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_self_improvement_schedule', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_self_improvement_schedule_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_kernel_slo_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_kernel_pipeline_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_repair_loop_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_inbox_action_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_agent_behavior_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_provider_performance_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_dynamic_compute_market_report', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_provider_release_review', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_provider_release_sources', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_ledger_projection_health', array_column($structured['tools'], 'name'));
        $this->assertContains('atlas_decision_receipt_report', array_column($structured['tools'], 'name'));
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

    public function test_domain_catalog_tool_schema_exposes_onboarding_status_filter(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 72, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_capabilities', 'arguments' => []],
        ]);

        $tools = $response['result']['structuredContent']['tools'];
        $domainCatalog = collect($tools)->firstWhere('name', 'atlas_domain_catalog');

        $this->assertIsArray($domainCatalog);
        $this->assertSame(
            'string',
            data_get($domainCatalog, 'inputSchema.properties.onboarding_status.type')
        );
    }

    public function test_domain_catalog_tool_filters_by_onboarding_status(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 73, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_domain_catalog',
                'arguments' => ['onboarding_status' => 'ready'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertSame('ready', data_get($structured, 'filters.onboarding_status'));
        $this->assertGreaterThanOrEqual(1, data_get($structured, 'summary.domains'));

        foreach ($structured['domains'] as $domain) {
            $this->assertSame('ready', data_get($domain, 'onboarding.status'));
        }
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

    public function test_domain_catalog_tool_rejects_invalid_onboarding_status_filter(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 74, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_domain_catalog',
                'arguments' => ['onboarding_status' => 'half-ready'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('invalid_onboarding_status', $structured['error']);
        $this->assertSame(['ready', 'executable_incomplete', 'scaffold'], $structured['allowed_onboarding_status']);
    }

    public function test_architecture_validate_tool_exposes_shared_contract_summary(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 75,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_validate',
                'arguments' => ['detail' => 'summary'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_architecture_validate', $structured['tool']);
        $this->assertSame('summary', $structured['detail']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('ok', data_get($structured, 'architecture_validation.status'));
        $this->assertTrue(data_get($structured, 'architecture_validation.kernel.valid'));
        $this->assertTrue(data_get($structured, 'architecture_validation.kernel.static_scan.valid'));
        $this->assertSame(0, data_get($structured, 'architecture_validation.kernel.static_scan.summary.failed_count'));
        $this->assertSame(0, data_get($structured, 'architecture_validation.kernel.static_scan.summary.violation_count'));
        $this->assertContains(
            'ap39_architecture_validation_contract_parity',
            data_get($structured, 'architecture_validation.kernel.static_scan.summary.valid_keys')
        );
        $this->assertGreaterThanOrEqual(1, data_get($structured, 'architecture_validation.onboarding.ready_domains'));
    }

    public function test_architecture_validate_tool_rejects_invalid_detail(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 76,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_validate',
                'arguments' => ['detail' => 'everything-ish'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_architecture_validate', $structured['tool']);
        $this->assertSame('invalid_detail', $structured['error']);
        $this->assertSame(['summary', 'full'], $structured['allowed_detail']);
    }

    public function test_architecture_operations_tool_exposes_shared_operations_catalog(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 765,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => [],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_architecture_operations', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('atlas.architecture_operations.v1', data_get($structured, 'architecture_operations.schema_version'));
        $this->assertSame('arquitetura_mae', data_get($structured, 'architecture_operations.section'));
        $this->assertContains('architecture_operations', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('architecture_readiness', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_projection_status', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_release_review', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_release_sources', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('external_graph_harness_report', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('ap_agent_workflow_registry', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_preflight', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_activation_contract', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_production_loop_plan', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_product_loop_check', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_curator_review', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_production_loop_smoke', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_runtime_certification', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertSame('architecture_operations', data_get($structured, 'architecture_operations.commands.0.id'));
        $this->assertSame('catalog', data_get($structured, 'architecture_operations.commands.0.kind'));
        $this->assertSame('cli', data_get($structured, 'architecture_operations.commands.0.surface'));
        $this->assertSame('php artisan atlas:ai:architecture-operations --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $readinessOperation = collect(data_get($structured, 'architecture_operations.commands'))->firstWhere('id', 'architecture_readiness');
        $this->assertSame('atlas_architecture_readiness', data_get($readinessOperation, 'mcp_tool'));

        $commands = data_get($structured, 'architecture_operations.commands');

        $this->assertSame(count($commands), data_get($structured, 'architecture_operations.command_count'));
        $this->assertContains('atlas engineering knowledge docs-health --json', array_column($commands, 'command'));
        $this->assertContains('atlas engineering knowledge sync --prune --json', array_column($commands, 'command'));
        $this->assertContains('atlas engineering knowledge index-code --prune --summary-only --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:provider-release-sources --json', array_column($commands, 'command'));
        $providerRelease = collect($commands)->firstWhere('id', 'provider_release_review');
        $this->assertSame('/ai/provider-release-review', data_get($providerRelease, 'api_endpoint'));
        $providerReleaseSources = collect($commands)->firstWhere('id', 'provider_release_sources');
        $this->assertSame('/ai/provider-release-sources', data_get($providerReleaseSources, 'api_endpoint'));
        $this->assertContains('php artisan atlas:ai:voice callback-smoke --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice callback-sequence-smoke --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice callback-loop-check --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice preflight --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice activation-contract --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice production-loop-plan --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice product-loop-check --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice production-loop-smoke --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:voice runtime-certify --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:ledger <id> --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:inbox-action-report --hours=24 --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:agent-behavior-report --hours=24 --json', array_column($commands, 'command'));
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', array_column($commands, 'command'));
    }

    public function test_memory_maintenance_status_recommends_compact_code_index_command(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 767,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_maintenance_status',
                'arguments' => [
                    'workspace' => base_path(),
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_memory_maintenance_status', $structured['tool']);
        $this->assertContains(
            './bin/atlas engineering knowledge index-code --prune --workspace="'.base_path().'" --summary-only --json',
            $structured['next_actions'],
        );
    }

    public function test_architecture_readiness_tool_exposes_preimplementation_snapshot(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 766,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_readiness',
                'arguments' => [
                    'workspace' => base_path(),
                    'owner' => 'kernel_architecture',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_architecture_readiness', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('atlas.architecture_readiness.v1', data_get($structured, 'architecture_readiness.schema_version'));
        $this->assertSame('ready', data_get($structured, 'architecture_readiness.status'));
        $this->assertSame('kernel_architecture', data_get($structured, 'architecture_readiness.owner'));
        $this->assertTrue(data_get($structured, 'architecture_readiness.checks.architecture_validate.ok'));
        $this->assertTrue(data_get($structured, 'architecture_readiness.checks.documentation_health.ok'));
        $this->assertSame('architecture_readiness', data_get($structured, 'architecture_readiness.architecture_operations.commands.0.id'));
        $this->assertContains(
            'feature_placement',
            data_get($structured, 'architecture_readiness.architecture_operations.related_operation_ids')
        );
        $this->assertContains(
            'runtime_language_boundary',
            data_get($structured, 'architecture_readiness.architecture_operations.related_operation_ids')
        );
        $this->assertSame(
            ['runtime_language_boundary'],
            data_get($structured, 'architecture_readiness.architecture_operations.owner_layer_operations.runtime.operation_ids')
        );
        $this->assertTrue(
            data_get($structured, 'architecture_readiness.architecture_operations.owner_layer_operations.runtime.commands.0.pre_implementation_gate')
        );
        $this->assertSame(
            'continue_implementation_with_session_bootstrap_and_feature_placement',
            data_get($structured, 'architecture_readiness.review_signal.recommended_action')
        );
    }

    public function test_runtime_boundary_tool_exposes_ap201_report(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 773,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_runtime_boundary',
                'arguments' => [],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_runtime_boundary', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('atlas.runtime_language_boundary_report.v1', data_get($structured, 'runtime_boundary.schema_version'));
        $this->assertSame('ok', data_get($structured, 'runtime_boundary.status'));
        $this->assertTrue(data_get($structured, 'runtime_boundary.boundary.valid'));
        $this->assertSame(0, data_get($structured, 'runtime_boundary.boundary.violation_count'));
        $this->assertSame('/ai/runtime-boundary', data_get($structured, 'runtime_boundary.surfaces.api'));
        $this->assertSame('atlas_runtime_boundary', data_get($structured, 'runtime_boundary.surfaces.mcp'));
        $this->assertSame('atlas.runtime_boundary_preflight_gate.v1', data_get($structured, 'runtime_boundary.preflight_gate.schema_version'));
        $this->assertContains('skip_decision_receipt_for_runtime', data_get($structured, 'runtime_boundary.preflight_gate.forbidden_preflight_shortcuts'));
        $this->assertSame('atlas.runtime_promotion_policy.v1', data_get($structured, 'runtime_boundary.runtime_promotion_policy.schema_version'));
        $this->assertFalse(data_get($structured, 'runtime_boundary.runtime_promotion_policy.auto_promotion_allowed'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($structured, 'runtime_boundary.runtime_invocation_contract.schema_version'));
        $this->assertContains('decision_receipt_hash', data_get($structured, 'runtime_boundary.runtime_invocation_contract.required_fields'));
        $this->assertSame('runtimes/python', data_get($structured, 'runtime_boundary.runtime_owner_map.python_ai_data.allowed_write_scope'));
    }

    public function test_architecture_operations_tool_filters_shared_operations_catalog(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 766,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['kind' => 'evidence_report'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['kind' => 'evidence_report'], data_get($structured, 'architecture_operations.filters'));
        $this->assertContains('voice_realtime_readiness', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_performance_report', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('agent_behavior_report', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('dynamic_compute_market_report', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('provider_cost_rates_missing', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('decision_receipt_report', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertContains('ledger_replay', data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertNotContains('architecture_operations', data_get($structured, 'architecture_operations.operation_ids'));

        $this->assertSame(
            count(data_get($structured, 'architecture_operations.commands')),
            data_get($structured, 'architecture_operations.command_count'),
        );

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 768,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['section' => 'arquitetura_mae'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['section' => 'arquitetura_mae'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(
            count(data_get($structured, 'architecture_operations.commands')),
            data_get($structured, 'architecture_operations.command_count'),
        );
        $this->assertContains('architecture_operations', data_get($structured, 'architecture_operations.operation_ids'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 770,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['surface' => 'runtime'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['surface' => 'runtime'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame(['voice_python_runtime_contract_test'], data_get($structured, 'architecture_operations.operation_ids'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 771,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['owner_layer' => 'runtime'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['owner_layer' => 'runtime'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame(['runtime_language_boundary'], data_get($structured, 'architecture_operations.operation_ids'));
        $this->assertSame('php artisan atlas:ai:runtime-boundary --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime', data_get($structured, 'architecture_operations.commands.0.owner_layer'));
        $this->assertTrue(data_get($structured, 'architecture_operations.commands.0.pre_implementation_gate'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 767,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['id' => 'voice_realtime_activation_contract'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['id' => 'voice_realtime_activation_contract'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice activation-contract --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($structured, 'architecture_operations.commands.0.kind'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 769,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['id' => 'voice_realtime_runtime_certification'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['id' => 'voice_realtime_runtime_certification'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice runtime-certify --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $this->assertSame('/ai/voice/runtime/certification', data_get($structured, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/certification', data_get($structured, 'architecture_operations.commands.0.mobile_endpoint'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 770,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['id' => 'voice_realtime_dependencies'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['id' => 'voice_realtime_dependencies'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice dependencies --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $this->assertSame('/ai/voice/runtime/dependencies', data_get($structured, 'architecture_operations.commands.0.api_endpoint'));
        $this->assertSame('/v1/mobile/ai/voice/runtime/dependencies', data_get($structured, 'architecture_operations.commands.0.mobile_endpoint'));
        $this->assertSame('atlas.voice_realtime.python_runtime_plan.v1', data_get($structured, 'architecture_operations.commands.0.runtime_dependency_contract'));
        $this->assertSame('ATLAS_VOICE_PYTHON_BIN', data_get($structured, 'architecture_operations.commands.0.python_binary_policy.environment_variable'));
        $this->assertSame('atlas_ai.voice_realtime.python_binary', data_get($structured, 'architecture_operations.commands.0.python_binary_policy.config_key'));
        $this->assertTrue(data_get($structured, 'architecture_operations.commands.0.pre_implementation_gate'));
        $this->assertFalse(data_get($structured, 'architecture_operations.commands.0.python_binary_policy.auto_install'));
        $this->assertContains('configured_binary', data_get($structured, 'architecture_operations.commands.0.output_contract.python_runtime'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 771,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['id' => 'voice_realtime_production_loop_plan'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['id' => 'voice_realtime_production_loop_plan'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice production-loop-plan --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($structured, 'architecture_operations.commands.0.kind'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 772,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_architecture_operations',
                'arguments' => ['id' => 'voice_realtime_product_loop_check'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame(['id' => 'voice_realtime_product_loop_check'], data_get($structured, 'architecture_operations.filters'));
        $this->assertSame(1, data_get($structured, 'architecture_operations.command_count'));
        $this->assertSame('php artisan atlas:ai:voice product-loop-check --json', data_get($structured, 'architecture_operations.commands.0.command'));
        $this->assertSame('runtime_contract', data_get($structured, 'architecture_operations.commands.0.kind'));
    }

    public function test_governance_tools_expose_session_bootstrap_feature_placement_and_split_plan(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $bootstrap = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 767,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_session_bootstrap',
                'arguments' => ['task' => 'voice realtime no mobile'],
            ],
        ])['result']['structuredContent'];

        $this->assertTrue($bootstrap['ok']);
        $this->assertSame('atlas_session_bootstrap', $bootstrap['tool']);
        $this->assertSame('voice_realtime', data_get($bootstrap, 'placement.surface'));
        $this->assertSame('knowledge_governance', data_get($bootstrap, 'docs_split_plan.owner'));
        $this->assertSame(
            'php artisan atlas:ai:docs-split-plan --owner=knowledge_governance --json',
            data_get($bootstrap, 'docs_split_plan.command'),
        );
        $this->assertSame('atlas.architecture_readiness.v1', data_get($bootstrap, 'architecture_readiness.schema_version'));
        $this->assertSame('ready', data_get($bootstrap, 'architecture_readiness.status'));
        $this->assertSame('knowledge_governance', data_get($bootstrap, 'architecture_readiness.owner'));
        $this->assertSame('atlas.implemented_vs_scaffold.coverage_boundary.v1', data_get($bootstrap, 'coverage_boundary.schema_version'));
        $this->assertSame('Voice Realtime product loop', data_get($bootstrap, 'safe_next_blocks.0.block'));
        $this->assertSame(
            'atlas.implemented_vs_scaffold.coverage_boundary.v1',
            data_get($bootstrap, 'architecture_readiness.coverage_boundary.schema_version'),
        );
        $this->assertSame(
            'Voice Realtime product loop',
            data_get($bootstrap, 'architecture_readiness.safe_next_blocks.0.block'),
        );
        $this->assertSame(
            'php artisan atlas:ai:architecture-readiness --owner=knowledge_governance --json',
            data_get($bootstrap, 'architecture_readiness.command'),
        );
        $this->assertArrayHasKey('session_gate', $bootstrap);
        $this->assertArrayHasKey('implementation_contract', $bootstrap);
        $this->assertContains('architecture_readiness', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('ap_agent_workflow_registry', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertContains('voice_realtime_dependencies', data_get($bootstrap, 'architecture_operations.operation_ids'));
        $this->assertSame(
            'atlas.voice_realtime.python_runtime_plan.v1',
            collect(data_get($bootstrap, 'architecture_operations.commands', []))
                ->firstWhere('id', 'voice_realtime_dependencies')['runtime_dependency_contract'] ?? null,
        );
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', data_get($bootstrap, 'required_validation'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md', $bootstrap['read_first']);
        $this->assertFalse($bootstrap['writes']);

        $placement = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 768,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_feature_placement',
                'arguments' => ['feature' => 'Anthropic Finance Agents provider release'],
            ],
        ])['result']['structuredContent'];

        $this->assertTrue($placement['ok']);
        $this->assertSame('atlas_feature_placement', $placement['tool']);
        $this->assertSame('provider_evolution', data_get($placement, 'placement.layer'));
        $this->assertSame('provider_evolution.review', data_get($placement, 'placement.flow'));
        $this->assertContains('architecture_readiness', data_get($placement, 'architecture_operations.operation_ids'));
        $this->assertContains('feature_placement', data_get($placement, 'architecture_operations.operation_ids'));
        $this->assertContains('session_bootstrap', data_get($placement, 'architecture_operations.operation_ids'));
        $this->assertContains('documentation_split_plan', data_get($placement, 'architecture_operations.operation_ids'));
        $this->assertContains('architecture_validate', data_get($placement, 'architecture_operations.operation_ids'));
        $this->assertContains('runtime_language_boundary', data_get($placement, 'architecture_operations.operation_ids'));
        $this->assertSame(
            ['runtime_language_boundary'],
            data_get($placement, 'architecture_operations.owner_layer_operations.runtime.operation_ids'),
        );
        $this->assertContains('php artisan atlas:ai:runtime-boundary --json', data_get($placement, 'required_validation'));
        $this->assertFalse($placement['writes']);

        $runtimePlacement = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 7691,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_feature_placement',
                'arguments' => ['feature' => 'Graph RAG FAISS embeddings reranker local'],
            ],
        ])['result']['structuredContent'];

        $this->assertTrue($runtimePlacement['ok']);
        $this->assertSame('runtime', data_get($runtimePlacement, 'placement.layer'));
        $this->assertSame('python_ai_data', data_get($runtimePlacement, 'placement.runtime'));
        $this->assertSame('python_ai_data', data_get($runtimePlacement, 'implementation_contract.runtime_family'));
        $this->assertSame(
            'atlas.runtime_invocation_contract.v1',
            data_get($runtimePlacement, 'implementation_contract.runtime_invocation_contract.schema_version'),
        );
        $this->assertSame(
            'python_ai_data',
            data_get($runtimePlacement, 'implementation_contract.runtime_invocation_contract.selected_runtime_family'),
        );
        $this->assertContains(
            'evidence_sink',
            data_get($runtimePlacement, 'implementation_contract.runtime_invocation_contract.required_fields'),
        );
        $this->assertContains(
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            data_get($runtimePlacement, 'implementation_contract.forbidden_write_scopes'),
        );
        $this->assertTrue(data_get($runtimePlacement, 'architecture_operations.owner_layer_operations.runtime.commands.0.pre_implementation_gate'));
        $this->assertFalse($runtimePlacement['writes']);

        $splitPlan = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 769,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_docs_split_plan',
                'arguments' => ['status' => 'split_required'],
            ],
        ])['result']['structuredContent'];

        $this->assertTrue($splitPlan['ok']);
        $this->assertSame('atlas_docs_split_plan', $splitPlan['tool']);
        $this->assertSame('split_required', data_get($splitPlan, 'filters.status'));
        $this->assertGreaterThanOrEqual(0, $splitPlan['split_required_count']);
        $this->assertSame('EngineeringDocumentationHealthService', data_get($splitPlan, 'policy.line_limits_source'));
        $this->assertCount($splitPlan['split_required_count'], $splitPlan['execution_order']);
        $this->assertCount($splitPlan['split_required_count'], $splitPlan['docs']);
        $this->assertSame(
            'If a split_required doc is touched, the change must either reduce it or add a focused child spec and backlink.',
            data_get($splitPlan, 'policy.growth_gate'),
        );
        $this->assertFalse($splitPlan['writes']);
    }

    public function test_session_bootstrap_tool_strict_mode_reports_blocked_gate(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 770,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_session_bootstrap',
                'arguments' => [
                    'task' => 'coisa generica sem owner claro',
                    'strict' => true,
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_session_bootstrap', $structured['tool']);
        $this->assertSame('session_bootstrap_blocked_by_strict_gate', $structured['error']);
        $this->assertSame('blocked', $structured['gate_status']);
        $this->assertTrue(data_get($structured, 'session_gate.strict_blocks_session'));
        $this->assertFalse($structured['writes']);
    }

    public function test_feature_placement_tool_requires_feature(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 770,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_feature_placement',
                'arguments' => [],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('feature_required', $structured['error']);
        $this->assertFalse($structured['writes']);
    }

    public function test_feature_placement_tool_strict_mode_reports_blocked_gate(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 771,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_feature_placement',
                'arguments' => [
                    'feature' => 'coisa generica sem owner claro',
                    'strict' => true,
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('feature_placement_blocked_by_strict_gate', $structured['error']);
        $this->assertSame('blocked', $structured['gate_status']);
        $this->assertContains('ambiguous_placement_requires_more_specific_feature_or_hint', $structured['blocked_when']);
        $this->assertFalse($structured['writes']);
    }

    public function test_self_improvement_schedule_tool_exposes_recurring_health(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review', 'agent_behavior_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 77,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_self_improvement_schedule',
                'arguments' => ['detail' => 'commands'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_self_improvement_schedule', $structured['tool']);
        $this->assertSame('commands', $structured['detail']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('registered', data_get($structured, 'schedule.scheduler_registration.status'));
        $this->assertSame(5, data_get($structured, 'schedule.count'));
        $this->assertSame(['daily' => 4, 'weekly' => 1], data_get($structured, 'schedule.cadence_counts'));
        $this->assertSame('weekly_architecture_audit', data_get($structured, 'schedule.commands.1.flow'));
        $this->assertSame('weekly', data_get($structured, 'schedule.commands.1.cadence'));
        $this->assertSame(1, data_get($structured, 'schedule.commands.1.week_day'));
        $this->assertNotNull(data_get($structured, 'schedule.commands.1.next_run_at'));
    }

    public function test_self_improvement_schedule_tool_rejects_invalid_detail(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 78,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_self_improvement_schedule',
                'arguments' => ['detail' => 'tomorrowish'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_self_improvement_schedule', $structured['tool']);
        $this->assertSame('invalid_detail', $structured['error']);
        $this->assertSame(['health', 'plan', 'commands'], $structured['allowed_detail']);
    }

    public function test_self_improvement_schedule_report_tool_exposes_replay_read_model(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_inbox_items');
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        AtlasLedgerEvent::query()->create([
            'event_id' => '01HMCPSELFIMPROVESCHED0001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp',
            'operator_id' => 'operator_mcp',
            'envelope_id' => 'self_improvement_run:mcp_warning',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'self_improvement_run:mcp_warning',
            'causation_id' => null,
            'event_type' => LedgerEventType::SelfImprovementScheduleObserved->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'schedule_health' => [
                    'health_status' => 'warning',
                    'issues' => ['invalid_self_improvement_flows_configured'],
                    'enabled' => true,
                    'schedulable' => true,
                    'scheduler_registration' => [
                        'status' => 'registered',
                        'registered_command_count' => 1,
                        'skipped_reason' => null,
                    ],
                    'flow_count' => 1,
                    'cadence_counts' => ['daily' => 1],
                    'invalid_flow_count' => 1,
                    'defaulted' => false,
                    'emit' => false,
                    'plan_hash' => 'mcp-schedule-plan-hash',
                    'plan_hash_algorithm' => 'sha256',
                    'time' => '02:00',
                    'timezone' => 'America/Sao_Paulo',
                    'next_run_at' => '2026-05-05T05:00:00.000000Z',
                ],
            ],
            'payload_hash' => hash('sha256', '01HMCPSELFIMPROVESCHED0001'),
            'occurred_at' => now(),
        ]);
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HMCPSELFIMPROVEDONE0001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp',
            'operator_id' => 'operator_mcp',
            'envelope_id' => 'self_improvement_run:mcp_warning',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'self_improvement_run:mcp_warning',
            'causation_id' => null,
            'event_type' => LedgerEventType::OperationCompleted->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'finding_count' => 1,
                'emitted_count' => 1,
                'emitted_inbox_item_ids' => ['00000000-0000-0000-0000-000000000987'],
            ],
            'payload_hash' => hash('sha256', '01HMCPSELFIMPROVEDONE0001'),
            'occurred_at' => now(),
        ]);
        AiInboxItem::unguarded(fn (): AiInboxItem => AiInboxItem::query()->create([
            'id' => '00000000-0000-0000-0000-000000000987',
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'MCP schedule warning proposal',
            'summary' => 'Self-Improvement schedule replay emitted this proposal.',
            'source_type' => 'atlas_self_improvement',
            'initiator' => 'system',
            'payload' => [
                'proposal_contract' => [
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'recommended_action' => 'review_mcp_schedule_repair',
                    ],
                ],
            ],
            'deep_link' => 'atlas://inbox/00000000-0000-0000-0000-000000000987',
        ]));

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 79,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_self_improvement_schedule_report',
                'arguments' => ['hours' => 24],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_self_improvement_schedule_report', $structured['tool']);
        $this->assertSame(24, $structured['hours']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'self_improvement_schedule_replay.schedule_observation_count'));
        $this->assertSame(1, data_get($structured, 'self_improvement_schedule_replay.completed_count'));
        $this->assertSame(1, data_get($structured, 'self_improvement_schedule_replay.emitted_count'));
        $this->assertSame('00000000-0000-0000-0000-000000000987', data_get($structured, 'self_improvement_schedule_replay.emitted_inbox_item_ids.0'));
        $this->assertSame('MCP schedule warning proposal', data_get($structured, 'self_improvement_schedule_replay.emitted_inbox_items.0.title'));
        $this->assertSame('review_mcp_schedule_repair', data_get($structured, 'self_improvement_schedule_replay.emitted_inbox_items.0.review_signal.recommended_action'));
        $this->assertTrue((bool) data_get($structured, 'self_improvement_schedule_replay.recent_events.0.completed'));
        $this->assertSame('00000000-0000-0000-0000-000000000987', data_get($structured, 'self_improvement_schedule_replay.recent_events.0.emitted_inbox_item_ids.0'));
        $this->assertSame('MCP schedule warning proposal', data_get($structured, 'self_improvement_schedule_replay.recent_events.0.emitted_inbox_items.0.title'));
        $this->assertSame(['warning' => 1], data_get($structured, 'self_improvement_schedule_replay.health_status_counts'));
        $this->assertSame('mcp-schedule-plan-hash', data_get($structured, 'self_improvement_schedule_replay.latest_plan_hash'));
        $this->assertTrue((bool) data_get($structured, 'self_improvement_schedule_replay.review_required'));
        $this->assertSame('warning', data_get($structured, 'self_improvement_schedule_replay.review_signal.status'));
        $this->assertSame('medium', data_get($structured, 'self_improvement_schedule_replay.review_signal.severity'));
        $this->assertSame('open_reviewable_self_improvement_schedule_proposal', data_get($structured, 'self_improvement_schedule_replay.review_signal.recommended_action'));
    }

    public function test_ledger_projection_health_tool_exposes_projection_status(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp_projection',
            'operator_id' => 'operator_mcp_projection',
            'envelope_id' => 'env_mcp_projection',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_mcp_projection',
            'causation_id' => null,
            'event_type' => LedgerEventType::ProviderReturned->value,
            'emitter_stage' => 'atlas.provider',
            'emitter_version' => 'atlas.provider.v1',
            'payload' => ['provider' => 'codex_cli', 'model' => 'gpt-5.2'],
            'payload_hash' => hash('sha256', 'mcp-ledger-projection-health'),
            'occurred_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 93,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_ledger_projection_health',
                'arguments' => ['max_lag_seconds' => 300],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_ledger_projection_health', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(300, $structured['max_lag_seconds']);
        $this->assertSame('atlas.ledger_projection_health.v1', data_get($structured, 'ledger_projection_health.schema_version'));
        $this->assertSame('critical', data_get($structured, 'ledger_projection_health.status'));
        $this->assertSame('warning', data_get($structured, 'ledger_projection_health.review_signal.status'));
        $this->assertSame('run_atlas_ai_ledger_project_or_review_projection_tables', data_get($structured, 'ledger_projection_health.review_signal.recommended_action'));
        $this->assertSame('atlas_engineering_runs', data_get($structured, 'ledger_projection_health.projections.1.id'));
        $this->assertSame('projection_unavailable', data_get($structured, 'ledger_projection_health.projections.1.status'));
    }

    public function test_kernel_slo_report_tool_exposes_replay_read_model(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        AtlasLedgerEvent::query()->create([
            'event_id' => '01HMCPKERNELSLO000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp',
            'operator_id' => 'operator_mcp',
            'envelope_id' => 'env_mcp_slo',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_mcp_slo',
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => 'breach',
                'severity' => 'high',
                'violations' => ['stage_failed'],
                'dimensions' => [
                    'domain' => 'programming',
                    'surface_id' => 'atlas_cli_dev',
                    'provider' => 'codex_cli',
                    'model' => 'gpt-5.2',
                ],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 450000,
                    'success' => false,
                    'status' => 'breach',
                    'severity' => 'high',
                    'violations' => ['stage_failed'],
                ],
            ],
            'payload_hash' => hash('sha256', '01HMCPKERNELSLO000000000001'),
            'occurred_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 80,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_kernel_slo_report',
                'arguments' => ['hours' => 24, 'domain' => 'programming', 'provider' => 'codex_cli'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_kernel_slo_report', $structured['tool']);
        $this->assertSame(24, $structured['hours']);
        $this->assertSame(['domain' => 'programming', 'provider' => 'codex_cli'], $structured['filters']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'kernel_slo.observation_count'));
        $this->assertSame('breach', data_get($structured, 'kernel_slo.review_signal.status'));
        $this->assertSame('high', data_get($structured, 'kernel_slo.review_signal.severity'));
        $this->assertSame('open_reviewable_slo_regression_proposal', data_get($structured, 'kernel_slo.review_signal.recommended_action'));
        $this->assertSame(['stage_failed'], $structured['kernel_slo']['stages']['runtime.execute']['violations']);
    }

    public function test_kernel_pipeline_report_tool_exposes_replay_read_model(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        AtlasLedgerEvent::query()->create([
            'event_id' => '01HMCPKERNELPIPE000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp',
            'operator_id' => 'operator_mcp',
            'envelope_id' => 'env_mcp_pipeline',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_mcp_pipeline',
            'causation_id' => null,
            'event_type' => LedgerEventType::KernelPipelineRejected->value,
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
            'emitter_version' => 'atlas.kernel.pipeline.v1',
            'payload' => [
                'status' => 'rejected',
                'violations' => ['kernel_pipeline_contract.required must be true.'],
                'pipeline' => [
                    'pipeline_id' => 'pipe_mcp',
                    'schema_version' => 'atlas.kernel_pipeline.v1',
                    'mode' => 'programming',
                    'stage_count' => 10,
                    'canonical_flow_hash' => 'hash_mcp',
                    'provider_execution_allowed' => false,
                    'runtime_execution_allowed' => false,
                ],
                'surface' => [
                    'surface_id' => 'atlas_cli_dev',
                    'binding_surface' => 'atlas_dev',
                    'command' => 'atlas dev',
                    'input_mode' => 'prompt',
                ],
                'surface_contract' => [
                    'required' => true,
                    'source' => 'KernelPipelineDevPlanBuilder',
                    'surface_must_not_decide' => true,
                ],
                'routing' => [
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'runtime' => 'programming.dev',
                ],
            ],
            'payload_hash' => hash('sha256', '01HMCPKERNELPIPE000000000001'),
            'occurred_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 81,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_kernel_pipeline_report',
                'arguments' => ['hours' => 24, 'status' => 'rejected', 'surface_id' => 'atlas_cli_dev'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_kernel_pipeline_report', $structured['tool']);
        $this->assertSame(['status' => 'rejected', 'surface_id' => 'atlas_cli_dev'], $structured['filters']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'kernel_pipeline.kernel_pipeline_event_count'));
        $this->assertSame('breach', data_get($structured, 'kernel_pipeline.review_signal.status'));
        $this->assertSame('high', data_get($structured, 'kernel_pipeline.review_signal.severity'));
        $this->assertSame('open_reviewable_kernel_pipeline_contract_proposal', data_get($structured, 'kernel_pipeline.review_signal.recommended_action'));
    }

    public function test_repair_loop_report_tool_exposes_replay_read_model(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        AtlasLedgerEvent::query()->create([
            'event_id' => '01HMCPREPAIRLOOP0000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp',
            'operator_id' => 'operator_mcp',
            'envelope_id' => 'env_mcp_repair',
            'receipt_id' => 'receipt_mcp_repair',
            'trace_id' => null,
            'correlation_id' => 'env_mcp_repair',
            'causation_id' => null,
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'atlas.repair.v1',
            'payload' => [
                'failure_classification' => [
                    'failure_domain' => 'compliance.violation',
                ],
                'decision' => [
                    'status' => 'needs_human_review',
                    'strategy' => 'human_review',
                    'next_attempt' => 1,
                    'reasons' => ['failure_domain_requires_human_review'],
                ],
                'repair_executed' => false,
                'decision_hash' => 'repair-mcp-decision',
            ],
            'payload_hash' => hash('sha256', '01HMCPREPAIRLOOP0000000001'),
            'occurred_at' => now(),
        ]);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 82,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_repair_loop_report',
                'arguments' => ['hours' => 24, 'strategy' => 'human_review'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_repair_loop_report', $structured['tool']);
        $this->assertSame(['strategy' => 'human_review'], $structured['filters']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'kernel_repair.repair_event_count'));
        $this->assertSame('warning', data_get($structured, 'kernel_repair.review_signal.status'));
        $this->assertSame('medium', data_get($structured, 'kernel_repair.review_signal.severity'));
        $this->assertSame('open_reviewable_repair_loop_human_review_proposal', data_get($structured, 'kernel_repair.review_signal.recommended_action'));
    }

    public function test_inbox_action_report_tool_exposes_replay_read_model(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->recordInboxActionForMcp(
            inboxItemId: 'mcp-inbox-action-1',
            action: 'review_patch',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'review_mcp_patch',
            diffRefs: [['path' => 'app/Services/Ai/Mobile/MobilePushService.php']],
        );

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 83,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_inbox_action_report',
                'arguments' => ['hours' => 24, 'action' => 'review_patch', 'inbox_item_category' => 'self_improvement'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_inbox_action_report', $structured['tool']);
        $this->assertSame(['action' => 'review_patch', 'inbox_item_category' => 'self_improvement'], $structured['filters']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'inbox_actions.inbox_action_count'));
        $this->assertSame(1, data_get($structured, 'inbox_actions.reviewed_patch_count'));
        $this->assertSame(1, data_get($structured, 'inbox_actions.with_diff_refs_count'));
        $this->assertSame('ok', data_get($structured, 'inbox_actions.review_signal.status'));
        $this->assertSame('none', data_get($structured, 'inbox_actions.review_signal.recommended_action'));
        $this->assertSame('mcp-inbox-action-1', data_get($structured, 'inbox_actions.recent_events.0.inbox_item_id'));
    }

    public function test_inbox_action_report_tool_exposes_rivals_review_scores(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->recordInboxActionForMcp(
            inboxItemId: 'mcp-rivals-review-1',
            action: 'record_rivals_review',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'record_due_rivals_strategy_reviews',
            diffRefs: [],
            rivalsReviewAction: [
                'schema_version' => 'atlas.inbox_action.rivals_review.v1',
                'recorded_review_id' => 'review-mcp-rivals',
                'case_id' => 'case-mcp-rivals',
                'horizon_days' => 30,
                'scores' => ['regret' => 4, 'alignment' => 96, 'agency' => 92],
                'remaining_due_review_count' => 0,
            ],
        );

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 84,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_inbox_action_report',
                'arguments' => ['hours' => 24, 'action' => 'record_rivals_review'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_inbox_action_report', $structured['tool']);
        $this->assertSame(['action' => 'record_rivals_review'], $structured['filters']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'inbox_actions.rivals_review_recorded_count'));
        $this->assertSame(1, data_get($structured, 'inbox_actions.rivals_review_with_scores_count'));
        $this->assertSame('ok', data_get($structured, 'inbox_actions.review_signal.status'));
        $this->assertSame('none', data_get($structured, 'inbox_actions.review_signal.recommended_action'));
        $this->assertSame('review-mcp-rivals', data_get($structured, 'inbox_actions.recent_events.0.rivals_review_id'));
        $this->assertSame(92, data_get($structured, 'inbox_actions.recent_events.0.rivals_agency_score'));
    }

    public function test_inbox_action_report_tool_exposes_provider_cost_rate_actions(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->recordInboxActionForMcp(
            inboxItemId: 'mcp-provider-cost-rate-1',
            action: 'configure_provider_cost_rates',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'configure_provider_cost_rates',
            diffRefs: [],
            rivalsReviewAction: [],
            providerCostRateAction: [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'input_microusd_per_1k' => 120,
                'output_microusd_per_1k' => 480,
                'currency' => 'USD',
                'applied' => true,
            ],
        );

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 85,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_inbox_action_report',
                'arguments' => ['hours' => 24, 'action' => 'configure_provider_cost_rates'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_inbox_action_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'inbox_actions.provider_cost_rate_action_count'));
        $this->assertSame(1, data_get($structured, 'inbox_actions.provider_cost_rate_applied_count'));
        $this->assertSame('ok', data_get($structured, 'inbox_actions.review_signal.status'));
        $this->assertSame('codex_cli', data_get($structured, 'inbox_actions.recent_events.0.provider_cost_rate_provider'));
        $this->assertSame('gpt-5.2', data_get($structured, 'inbox_actions.recent_events.0.provider_cost_rate_model'));
        $this->assertSame(480, data_get($structured, 'inbox_actions.recent_events.0.provider_cost_rate_output_microusd'));
    }

    public function test_inbox_action_report_tool_exposes_retrieval_regression_reviews(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->recordInboxActionForMcp(
            inboxItemId: 'mcp-retrieval-regression-1',
            action: 'review_retrieval_regression',
            actorType: 'operator_cli',
            category: 'memory',
            severity: 'medium',
            recommendedAction: 'open_memory_retrieval_regression_review',
            diffRefs: [],
            rivalsReviewAction: [],
            providerCostRateAction: [],
            retrievalRegressionReviewAction: [
                'schema_version' => 'atlas.inbox_action.memory_retrieval_regression_review.v1',
                'decision' => 'reviewed',
                'reviewed' => true,
                'report_hash' => hash('sha256', 'retrieval-report-mcp'),
                'latest_snapshot_hash' => hash('sha256', 'retrieval-latest-mcp'),
                'previous_snapshot_hash' => hash('sha256', 'retrieval-previous-mcp'),
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
            ],
        );

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 86,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_inbox_action_report',
                'arguments' => ['hours' => 24, 'action' => 'review_retrieval_regression'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_inbox_action_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'inbox_actions.retrieval_regression_review_count'));
        $this->assertSame(1, data_get($structured, 'inbox_actions.retrieval_regression_reviewed_count'));
        $this->assertSame('ok', data_get($structured, 'inbox_actions.review_signal.status'));
        $this->assertSame('reviewed', data_get($structured, 'inbox_actions.recent_events.0.retrieval_regression_decision'));
        $this->assertTrue((bool) data_get($structured, 'inbox_actions.recent_events.0.retrieval_regression_no_runtime_execution'));
        $this->assertTrue((bool) data_get($structured, 'inbox_actions.recent_events.0.retrieval_regression_no_policy_patch'));
    }

    public function test_inbox_action_report_tool_exposes_retrieval_shadow_scope_decision_receipts(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $receiptHash = hash('sha256', 'retrieval-shadow-scope-mcp-receipt');
        $this->recordInboxActionForMcp(
            inboxItemId: 'mcp-retrieval-shadow-scope-1',
            action: 'review_retrieval_shadow_scope',
            actorType: 'operator_cli',
            category: 'memory',
            severity: 'medium',
            recommendedAction: 'review_retrieval_shadow_scope',
            diffRefs: [],
            rivalsReviewAction: [],
            providerCostRateAction: [],
            retrievalRegressionReviewAction: [],
            retrievalShadowScopeReviewAction: [
                'schema_version' => 'atlas.inbox_action.memory_retrieval_shadow_scope_review.v1',
                'decision' => 'needs_more_evidence',
                'reviewed' => true,
                'plan_hash' => hash('sha256', 'retrieval-shadow-scope-mcp-plan'),
                'case_contract_hash' => hash('sha256', 'retrieval-shadow-scope-mcp-case'),
                'review_ap' => 'docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md',
                'privacy_provider_safety_review_hash' => hash('sha256', 'retrieval-shadow-scope-mcp-safety'),
                'privacy_provider_safety_review' => [
                    'schema_version' => 'atlas.memory_retrieval_shadow_scope_privacy_provider_safety_review.v1',
                    'status' => 'passed_blocked',
                    'provider_safe_for_review' => true,
                ],
                'decision_receipt_hash' => $receiptHash,
                'decision_receipt' => [
                    'schema_version' => 'atlas.memory_retrieval_shadow_scope_decision_receipt.v1',
                    'receipt_hash' => $receiptHash,
                    'case_contract_hash' => hash('sha256', 'retrieval-shadow-scope-mcp-case'),
                    'privacy_provider_safety_review_passed' => true,
                    'shadow_execution_allowed_now' => false,
                ],
                'no_external_action' => true,
                'no_runtime_execution' => true,
                'no_policy_patch' => true,
                'no_provider_call' => true,
            ],
        );

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 87,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_inbox_action_report',
                'arguments' => ['hours' => 24, 'action' => 'review_retrieval_shadow_scope'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_inbox_action_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(1, data_get($structured, 'inbox_actions.retrieval_shadow_scope_review_count'));
        $this->assertSame(1, data_get($structured, 'inbox_actions.retrieval_shadow_scope_decision_receipt_count'));
        $this->assertSame(0, data_get($structured, 'inbox_actions.retrieval_shadow_scope_runtime_allowed_count'));
        $this->assertSame('ok', data_get($structured, 'inbox_actions.review_signal.status'));
        $this->assertSame('needs_more_evidence', data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_decision'));
        $this->assertSame($receiptHash, data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_decision_receipt_hash'));
        $this->assertSame(hash('sha256', 'retrieval-shadow-scope-mcp-case'), data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_case_contract_hash'));
        $this->assertSame('passed_blocked', data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_privacy_provider_safety_review_status'));
        $this->assertTrue((bool) data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_privacy_provider_safe_for_review'));
        $this->assertFalse((bool) data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_shadow_execution_allowed_now'));
        $this->assertTrue((bool) data_get($structured, 'inbox_actions.recent_events.0.retrieval_shadow_scope_no_provider_call'));
    }

    public function test_replay_report_tools_preserve_review_signal_when_ledger_is_unavailable(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $cases = [
            'atlas_self_improvement_schedule_report' => [
                'path' => 'self_improvement_schedule_replay.review_signal',
                'action' => 'wait_for_next_self_improvement_cycle',
            ],
            'atlas_kernel_slo_report' => [
                'path' => 'kernel_slo.review_signal',
                'action' => 'wait_for_slo_evidence',
            ],
            'atlas_kernel_pipeline_report' => [
                'path' => 'kernel_pipeline.review_signal',
                'action' => 'wait_for_kernel_pipeline_evidence',
            ],
            'atlas_repair_loop_report' => [
                'path' => 'kernel_repair.review_signal',
                'action' => 'wait_for_repair_loop_evidence',
            ],
            'atlas_inbox_action_report' => [
                'path' => 'inbox_actions.review_signal',
                'action' => 'wait_for_inbox_action_evidence',
            ],
            'atlas_agent_behavior_report' => [
                'path' => 'agent_behavior.review_signal',
                'action' => 'wait_for_agent_behavior_evidence',
            ],
            'atlas_provider_performance_report' => [
                'path' => 'provider_performance.review_signal',
                'action' => 'wait_for_provider_usage_evidence',
            ],
            'atlas_ledger_projection_health' => [
                'path' => 'ledger_projection_health.review_signal',
                'action' => 'wait_for_ledger_initialization',
            ],
        ];

        foreach ($cases as $tool => $expectation) {
            $response = $service->handleJsonRpc([
                'jsonrpc' => '2.0',
                'id' => 'unavailable-'.$tool,
                'method' => 'tools/call',
                'params' => [
                    'name' => $tool,
                    'arguments' => ['hours' => 24],
                ],
            ]);
            $structured = $response['result']['structuredContent'];

            $this->assertFalse($structured['ok'], $tool);
            $this->assertSame($tool, $structured['tool']);
            $this->assertFalse($structured['writes']);
            $this->assertSame('unknown', data_get($structured, $expectation['path'].'.status'), $tool);
            $this->assertSame($expectation['action'], data_get($structured, $expectation['path'].'.recommended_action'), $tool);
        }
    }

    public function test_replay_report_tools_use_canonical_mcp_hours_window(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        foreach ([
            ['tool' => 'atlas_self_improvement_schedule_report', 'input' => -5, 'expected' => 1],
            ['tool' => 'atlas_kernel_slo_report', 'input' => 9999, 'expected' => 720],
            ['tool' => 'atlas_kernel_pipeline_report', 'input' => ['bad'], 'expected' => 24],
            ['tool' => 'atlas_repair_loop_report', 'input' => '12', 'expected' => 12],
            ['tool' => 'atlas_inbox_action_report', 'input' => 72, 'expected' => 72],
            ['tool' => 'atlas_agent_behavior_report', 'input' => 36, 'expected' => 36],
            ['tool' => 'atlas_provider_performance_report', 'input' => 48, 'expected' => 48],
        ] as $case) {
            $response = $service->handleJsonRpc([
                'jsonrpc' => '2.0',
                'id' => 92,
                'method' => 'tools/call',
                'params' => [
                    'name' => $case['tool'],
                    'arguments' => ['hours' => $case['input']],
                ],
            ]);

            $structured = $response['result']['structuredContent'];

            $this->assertSame($case['tool'], $structured['tool']);
            $this->assertSame($case['expected'], $structured['hours']);
            $this->assertFalse($structured['writes']);
        }
    }

    public function test_replay_report_tools_use_canonical_scalar_filter_contract(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        foreach ([
            [
                'tool' => 'atlas_kernel_slo_report',
                'arguments' => ['domain' => ' programming ', 'flow' => '', 'provider' => ['bad'], 'tool_id' => 'phpstan'],
                'expected' => ['domain' => 'programming', 'tool_id' => 'phpstan'],
            ],
            [
                'tool' => 'atlas_kernel_pipeline_report',
                'arguments' => ['status' => ' rejected ', 'surface_id' => '', 'flow' => ['bad'], 'emitter_stage' => 'atlas.test'],
                'expected' => ['status' => 'rejected', 'emitter_stage' => 'atlas.test'],
            ],
            [
                'tool' => 'atlas_repair_loop_report',
                'arguments' => ['status' => ' completed ', 'strategy' => false, 'failure_domain' => '', 'emitter_stage' => ['bad']],
                'expected' => ['status' => 'completed'],
            ],
            [
                'tool' => 'atlas_inbox_action_report',
                'arguments' => ['action' => ' review_patch ', 'actor_type' => ['bad'], 'inbox_item_category' => ' self_improvement ', 'source_type' => ''],
                'expected' => ['action' => 'review_patch', 'inbox_item_category' => 'self_improvement'],
            ],
            [
                'tool' => 'atlas_agent_behavior_report',
                'arguments' => ['provider' => ' codex_cli ', 'finding_code' => ' agent.verification_missing ', 'agent_slug' => ['bad']],
                'expected' => ['provider' => 'codex_cli', 'finding_code' => 'agent.verification_missing'],
            ],
            [
                'tool' => 'atlas_provider_performance_report',
                'arguments' => ['provider' => ' codex_cli ', 'domain' => ' programming ', 'risk' => ['bad'], 'selection_mode' => 'auto'],
                'expected' => ['domain' => 'programming', 'selection_mode' => 'auto', 'provider_cli' => 'codex_cli'],
            ],
        ] as $case) {
            $response = $service->handleJsonRpc([
                'jsonrpc' => '2.0',
                'id' => 93,
                'method' => 'tools/call',
                'params' => [
                    'name' => $case['tool'],
                    'arguments' => $case['arguments'],
                ],
            ]);

            $structured = $response['result']['structuredContent'];

            $this->assertSame($case['tool'], $structured['tool']);
            $this->assertSame($case['expected'], $structured['filters']);
            $this->assertFalse($structured['writes']);
        }
    }

    public function test_provider_performance_report_summarizes_normalized_provider_usage(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->recordProviderReturnedForMcp('codex_cli', 'programming', 'feature', 'succeeded', 1.2);
        $this->recordProviderReturnedForMcp('codex_cli', 'programming', 'feature', 'failed', 2.4, 'rate_limited');
        $this->recordProviderReturnedForMcp('claude_cli', 'programming', 'review', 'succeeded', 3.0);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 94,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_provider_performance_report',
                'arguments' => [
                    'hours' => 24,
                    'provider' => 'codex_cli',
                    'domain' => 'programming',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_provider_performance_report', $structured['tool']);
        $this->assertSame(['domain' => 'programming', 'provider_cli' => 'codex_cli'], $structured['filters']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(2, data_get($structured, 'provider_performance.event_count'));
        $this->assertSame(1, data_get($structured, 'provider_performance.success_count'));
        $this->assertSame(1, data_get($structured, 'provider_performance.failure_count'));
        $this->assertSame(0.5, data_get($structured, 'provider_performance.success_rate'));
        $this->assertSame('codex_cli', data_get($structured, 'provider_performance.groups.0.provider_cli'));
        $this->assertSame('feature', data_get($structured, 'provider_performance.groups.0.task_type'));
    }

    public function test_dynamic_compute_market_report_exposes_read_only_shadow_advice(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        for ($i = 0; $i < 5; $i++) {
            $this->recordProviderReturnedForMcp('codex_cli', 'programming', 'feature', 'succeeded', 160.0);
            $this->recordProviderReturnedForMcp('claude_cli', 'programming', 'feature', 'succeeded', 42.0);
        }

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 194,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_dynamic_compute_market_report',
                'arguments' => [
                    'provider' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'feature',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_dynamic_compute_market_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('atlas.dynamic_compute_market_report.v1', data_get($structured, 'schema_version'));
        $this->assertSame('read_only_no_routing_change', data_get($structured, 'authority'));
        $this->assertSame('codex_cli', data_get($structured, 'input.provider'));
        $this->assertSame('benchmark_lower_latency_alternative', data_get($structured, 'dynamic_compute_market.recommendation'));
        $this->assertSame('run_controlled_provider_benchmark_before_policy_change', data_get($structured, 'dynamic_compute_market.recommended_next_action'));
        $this->assertFalse((bool) data_get($structured, 'dynamic_compute_market.routing_control.changes_provider'));
        $this->assertSame('claude_cli', data_get($structured, 'dynamic_compute_market.benchmark_candidate.provider'));
    }

    public function test_dynamic_compute_market_report_preserves_review_signal_when_ledger_is_unavailable(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 195,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_dynamic_compute_market_report',
                'arguments' => ['provider' => 'codex_cli'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_dynamic_compute_market_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('ledger_unavailable', data_get($structured, 'status'));
        $this->assertSame('collect_ap99_evidence', data_get($structured, 'dynamic_compute_market.recommendation'));
        $this->assertSame('collect_ap99_evidence_before_policy_change', data_get($structured, 'dynamic_compute_market.recommended_next_action'));
        $this->assertFalse((bool) data_get($structured, 'dynamic_compute_market.routing_control.changes_provider'));
        $this->assertFalse((bool) data_get($structured, 'dynamic_compute_market.ap99.available'));
    }

    public function test_provider_release_review_tool_classifies_external_provider_launches_without_writes(): void
    {
        // release_id embeds now()->format('Y-m'); pin the clock so the asserted month stays valid.
        Carbon::setTestNow(Carbon::parse('2026-05-15T12:00:00Z'));

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 196,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_provider_release_review',
                'arguments' => [
                    'provider' => 'anthropic',
                    'title' => 'Anthropic Finance Agents',
                    'url' => 'https://www.anthropic.com/news/finance-agents',
                    'domain' => ['finance'],
                    'capability' => ['agentic_finance_research'],
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_provider_release_review', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('atlas.provider_release_review.v1', data_get($structured, 'schema_version'));
        $this->assertSame('atlas.provider_release.v1', data_get($structured, 'release_envelope.schema_version'));
        $this->assertSame('anthropic', data_get($structured, 'release_envelope.provider'));
        $this->assertSame('anthropic-finance-agents-2026-05', data_get($structured, 'release_envelope.release_id'));
        $this->assertSame('vertical_agents', data_get($structured, 'release_envelope.release_type'));
        $this->assertContains('finance', data_get($structured, 'release_envelope.affected_domains'));
        $this->assertSame('benchmark', data_get($structured, 'recommended_action'));
        $this->assertTrue((bool) data_get($structured, 'rivals_required'));
        $this->assertFalse((bool) data_get($structured, 'decide_signal.changes_routing'));
    }

    public function test_provider_release_review_tool_requires_title(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 197,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_provider_release_review',
                'arguments' => ['provider' => 'openai'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_provider_release_review', $structured['tool']);
        $this->assertSame('title_required', $structured['error']);
        $this->assertFalse($structured['writes']);
    }

    public function test_provider_release_sources_tool_lists_watchlist_without_writes(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 198,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_provider_release_sources',
                'arguments' => [
                    'provider' => 'anthropic',
                    'track' => 'managed_agents',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_provider_release_sources', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('atlas.provider_release.source_registry.v1', $structured['schema_version']);
        $this->assertSame('read_only_registry', $structured['mode']);
        $this->assertSame('watchlist_only_no_release_ingestion', $structured['authority']);
        $this->assertSame('anthropic_news', data_get($structured, 'sources.0.id'));
        $this->assertFalse((bool) data_get($structured, 'guardrails.network_fetching_enabled'));
        $this->assertSame('planned_fail_closed', data_get($structured, 'continuous_ingestion_contract.status'));
    }

    public function test_provider_release_sources_tool_previews_candidate_without_writes(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 199,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_provider_release_sources',
                'arguments' => [
                    'url' => 'https://www.anthropic.com/news/finance-agents',
                    'title' => 'Anthropic Finance Agents',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_provider_release_sources', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('read_only_candidate_preview', $structured['mode']);
        $this->assertSame('candidate', data_get($structured, 'candidate.status'));
        $this->assertSame('anthropic_news', data_get($structured, 'candidate.source.id'));
        $this->assertSame('vertical_agents', data_get($structured, 'candidate.release_type'));
        $this->assertTrue((bool) data_get($structured, 'candidate.source_trust.decide_signal_allowed'));
        $this->assertFalse((bool) data_get($structured, 'guardrails.writes_release_envelope'));
    }

    public function test_agent_behavior_report_summarizes_gate_findings(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $this->recordAgentBehaviorForMcp('mcp-agent-a', 'codex_cli', 'agent.verification_missing', 72);
        $this->recordAgentBehaviorForMcp('mcp-agent-b', 'claude_cli', 'agent.verification_missing', 68);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 95,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_agent_behavior_report',
                'arguments' => ['hours' => 24, 'finding_code' => 'agent.verification_missing'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_agent_behavior_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame(2, data_get($structured, 'agent_behavior.agent_behavior_event_count'));
        $this->assertSame(['agent.verification_missing' => 2], data_get($structured, 'agent_behavior.finding_code_counts'));
        $this->assertSame('warning', data_get($structured, 'agent_behavior.review_signal.status'));
        $this->assertSame('open_reviewable_agent_behavior_quality_proposal', data_get($structured, 'agent_behavior.review_signal.recommended_action'));
    }

    public function test_decision_receipt_report_replays_receipt_chain_for_envelope(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $first = $this->recordDecisionReceiptForMcp('env_mcp_receipt', 'receipt_mcp_1');
        $this->recordDecisionReceiptForMcp('env_mcp_receipt', 'receipt_mcp_2', 'receipt_mcp_1', $first['chain_hash']);

        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 95,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_decision_receipt_report',
                'arguments' => ['envelope' => 'env_mcp_receipt'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_decision_receipt_report', $structured['tool']);
        $this->assertFalse($structured['writes']);
        $this->assertSame('env_mcp_receipt', data_get($structured, 'decision_receipt_replay.envelope_id'));
        $this->assertSame(2, data_get($structured, 'decision_receipt_replay.decision_event_count'));
        $this->assertSame(0, data_get($structured, 'decision_receipt_replay.invalid_count'));
        $this->assertSame('receipt_mcp_2', data_get($structured, 'decision_receipt_replay.latest_receipt_id'));
        $this->assertSame('ok', data_get($structured, 'decision_receipt_replay.review_signal.status'));
    }

    public function test_decision_receipt_report_requires_envelope(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 96,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_decision_receipt_report',
                'arguments' => [],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('atlas_decision_receipt_report', $structured['tool']);
        $this->assertSame('envelope_required', $structured['error']);
        $this->assertFalse($structured['writes']);
    }

    public function test_workspace_info_returns_metadata_for_atlas_tracked_workspace(): void
    {
        AtlasMemoryEntry::create([
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

    private function recordProviderReturnedForMcp(string $provider, string $domain, string $taskType, string $status, float $latency, ?string $failureReason = null): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp_provider_performance',
            'operator_id' => 'operator_mcp_provider_performance',
            'envelope_id' => 'env_'.Str::random(8),
            'receipt_id' => 'rcpt_'.Str::random(8),
            'trace_id' => 'trace_'.Str::random(8),
            'correlation_id' => 'corr_'.Str::random(8),
            'causation_id' => null,
            'event_type' => LedgerEventType::ProviderReturned->value,
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => [
                'schema_version' => ProviderUsagePayload::SCHEMA_VERSION,
                'phase' => 'returned',
                'provider_cli' => $provider,
                'domain' => $domain,
                'task_type' => $taskType,
                'flow' => 'programming.dev',
                'risk' => 'medium',
                'exit_status' => $status,
                'latency_seconds' => $latency,
                'repair_count' => $status === 'succeeded' ? 0 : 1,
                'failure_reason' => $failureReason,
                'selection_mode' => 'auto',
            ],
            'payload_hash' => hash('sha256', $provider.$domain.$taskType.$status.$latency),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $diffRefs
     */
    private function recordInboxActionForMcp(
        string $inboxItemId,
        string $action,
        string $actorType,
        string $category,
        string $severity,
        string $recommendedAction,
        array $diffRefs = [],
        array $rivalsReviewAction = [],
        array $providerCostRateAction = [],
        array $retrievalRegressionReviewAction = [],
        array $retrievalShadowScopeReviewAction = [],
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp_inbox_action',
            'operator_id' => $actorType,
            'envelope_id' => 'inbox_item:'.$inboxItemId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $inboxItemId,
            'causation_id' => null,
            'event_type' => LedgerEventType::InboxActionRecorded->value,
            'emitter_stage' => 'atlas.inbox',
            'emitter_version' => 'atlas.inbox_action.v1',
            'payload' => [
                'schema_version' => 'atlas.inbox_action.v1',
                'action' => $action,
                'inbox_item' => [
                    'id' => $inboxItemId,
                    'type' => 'proposal',
                    'category' => $category,
                    'severity' => $severity,
                    'status' => 'read',
                    'source_type' => 'self_improvement',
                    'source_id' => 'finding-'.$inboxItemId,
                    'dedupe_key' => 'dedupe-'.$inboxItemId,
                ],
                'actor' => [
                    'type' => $actorType,
                    'id' => null,
                ],
                'result' => [
                    'payload' => [
                        'action' => $action,
                        'diff_refs' => $diffRefs,
                    ],
                    'rivals_review_action' => $rivalsReviewAction,
                    'provider_cost_rate_action' => $providerCostRateAction,
                    'retrieval_regression_review_action' => $retrievalRegressionReviewAction,
                    'retrieval_shadow_scope_review_action' => $retrievalShadowScopeReviewAction,
                ],
                'review_signal' => [
                    'status' => 'ok',
                    'severity' => $severity,
                    'recommended_action' => $recommendedAction,
                ],
                'recommended_action' => $recommendedAction,
            ],
            'payload_hash' => hash('sha256', $inboxItemId.$action.$recommendedAction),
            'occurred_at' => now(),
        ]);
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
        AtlasMemoryEntry::create([
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
        AtlasMemoryEntry::create([
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

        $task = AtlasTask::find($structured['task_id']);
        $this->assertNotNull($task);
        $this->assertSame('open', $task->status);
        $this->assertSame('started', $structured['event_type']);

        $event = AtlasTaskEvent::where('task_id', $task->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('started', $event->event_type);
        $this->assertSame('mcp_tool', $event->source);
        $this->assertSame('atlas.task_orchestration.event.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('atlas_open_brain_mcp', data_get($event->payload, 'tool'));
        $this->assertSame(1, data_get($event->payload, 'event_sequence'));
        $this->assertNull(data_get($event->payload, 'previous_event_id'));
        $this->assertNull(data_get($event->payload, 'previous_event_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($event->payload, 'event_hash'));
        $this->assertTrue((bool) data_get($event->payload, 'objective_present'));
        $this->assertTrue((bool) data_get($event->payload, 'no_provider_execution'));
        $this->assertTrue((bool) data_get($event->payload, 'no_runtime_execution'));
        $this->assertNotNull(data_get($event->payload, 'workspace_hash'));
    }

    public function test_task_progress_appends_event(): void
    {
        $task = AtlasTask::create([
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
        $this->assertSame(1, AtlasTaskEvent::where('task_id', $task->id)->count());
        $event = AtlasTaskEvent::where('task_id', $task->id)->first();
        $this->assertSame('atlas.task_orchestration.event.v1', data_get($event?->payload, 'schema_version'));
        $this->assertSame('atlas_open_brain_mcp', data_get($event?->payload, 'tool'));
        $this->assertTrue((bool) data_get($event?->payload, 'no_provider_execution'));
        $this->assertTrue((bool) data_get($event?->payload, 'no_runtime_execution'));
    }

    public function test_task_lifecycle_events_are_hash_chained(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $start = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 141,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_task_start',
                'arguments' => ['title' => 'Chain task'],
            ],
        ]);
        $taskId = $start['result']['structuredContent']['task_id'];

        $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 142,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_task_progress',
                'arguments' => [
                    'task_id' => $taskId,
                    'milestone' => 'first-checkpoint',
                ],
            ],
        ]);

        $events = AtlasTaskEvent::where('task_id', $taskId)->orderBy('occurred_at')->get();
        $this->assertCount(2, $events);
        $this->assertSame(1, data_get($events[0]->payload, 'event_sequence'));
        $this->assertSame(2, data_get($events[1]->payload, 'event_sequence'));
        $this->assertSame($events[0]->id, data_get($events[1]->payload, 'previous_event_id'));
        $this->assertSame(data_get($events[0]->payload, 'event_hash'), data_get($events[1]->payload, 'previous_event_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($events[1]->payload, 'event_hash'));
        $this->assertTrue((bool) data_get($events[1]->payload, 'no_provider_execution'));
        $this->assertTrue((bool) data_get($events[1]->payload, 'no_runtime_execution'));
    }

    public function test_task_complete_sets_status_and_event(): void
    {
        $task = AtlasTask::create([
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
        $event = AtlasTaskEvent::where('task_id', $task->id)->first();
        $this->assertSame('completed', $event?->event_type);
        $this->assertSame('atlas.task_orchestration.event.v1', data_get($event?->payload, 'schema_version'));
        $this->assertSame(['app/X.php', 'tests/Y.php'], data_get($event?->payload, 'files_changed'));
        $this->assertTrue((bool) data_get($event?->payload, 'no_provider_execution'));
        $this->assertTrue((bool) data_get($event?->payload, 'no_runtime_execution'));
    }

    public function test_task_tools_fail_closed_when_task_event_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_task_events');
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 151, 'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_task_start',
                'arguments' => ['title' => 'Should not persist without event table'],
            ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('task_orchestration_unavailable', $structured['error']);
        $this->assertSame(['atlas_task_events'], $structured['missing_tables']);
        $this->assertFalse((bool) $structured['writes']);
        $this->assertSame(0, AtlasTask::query()->count());
    }

    public function test_memory_archive_sets_status_archived(): void
    {
        $entry = AtlasMemoryEntry::create([
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
        $entry1 = AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Decision A', 'body' => 'A',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);
        $entry2 = AtlasMemoryEntry::create([
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

        $relation = AtlasMemoryEntryRelation::find($structured['relation_id']);
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
        $oldEntry = AtlasMemoryEntry::create([
            'memory_type' => 'decision', 'scope_type' => 'global',
            'title' => 'Use SQLite', 'body' => 'SQLite é o DB',
            'status' => 'active', 'privacy_class' => 'normal',
            'external_ai_allowed' => true, 'redaction_status' => 'clean',
            'recorded_at' => now(),
        ]);
        $newEntry = AtlasMemoryEntry::create([
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
        $entry = AtlasMemoryEntry::create([
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
        $entry = AtlasMemoryEntry::create([
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
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $entry = AtlasMemoryEntry::create([
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
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::OperationBlocked->value,
            'emitter_stage' => 'atlas.memory_provider_privacy',
        ]);

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
        AtlasMemoryEntry::create([
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

    /**
     * @return array<string,mixed>
     */
    private function recordAgentBehaviorForMcp(string $suffix, string $provider, string $findingCode, int $score): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => 'evt_'.$suffix,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp_agent_behavior',
            'operator_id' => 'operator_mcp_agent_behavior',
            'envelope_id' => 'env_'.$suffix,
            'receipt_id' => null,
            'trace_id' => 'trace_'.$suffix,
            'correlation_id' => 'trace_'.$suffix,
            'causation_id' => 'quality_'.$suffix,
            'event_type' => LedgerEventType::GateEvaluated->value,
            'emitter_stage' => 'atlas.agent_behavior_quality_gate',
            'emitter_version' => 'atlas.agent_behavior.v1',
            'payload' => [
                'gate_id' => 'atlas.agent_behavior',
                'schema_version' => 'atlas.agent_behavior.gate_evaluation.v1',
                'status' => 'needs_review',
                'score' => $score,
                'provider' => $provider,
                'model' => 'model-test',
                'agent_slug' => 'desenvolvedor',
                'contract_id' => 'atlas-ai.agent-behavior.v1',
                'contract_hash' => 'contract-hash-test',
                'flags' => ['verification_missing'],
                'suggested_actions' => ['request_verification_or_tests'],
                'agent_behavior_findings' => [[
                    'code' => $findingCode,
                    'severity' => 'p2',
                    'metadata' => [
                        'contract_id' => 'atlas-ai.agent-behavior.v1',
                        'contract_hash' => 'contract-hash-test',
                    ],
                ]],
            ],
            'payload_hash' => hash('sha256', $suffix),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function recordDecisionReceiptForMcp(
        string $envelopeId,
        string $receiptId,
        ?string $parentReceiptId = null,
        ?string $parentChainHash = null,
    ): array {
        $payload = [
            'schema_version' => 'atlas.decide.v2',
            'receipt_id' => $receiptId,
            'envelope_id' => $envelopeId,
            'issued_at' => now()->toJSON(),
            'expires_at' => now()->addSeconds(30)->toJSON(),
            'dry_run' => false,
            'signed_by' => 'atlas-decide-v2',
            'provider_selection' => ['provider' => 'codex_cli', 'model' => 'gpt-5.2'],
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'budget' => ['max_cost_usd' => 1.0],
            'quality_gates' => ['tests'],
            'required_gates' => ['tests'],
            'required_evidence' => ['summary'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => hash('sha256', 'input-'.$receiptId),
            'parent_receipt_id' => $parentReceiptId,
            'parent_chain_hash' => $parentChainHash,
        ];
        $payload['receipt_hash'] = DecisionReceiptHash::hash([
            'receipt_id' => $payload['receipt_id'],
            'envelope_id' => $payload['envelope_id'],
            'schema_version' => $payload['schema_version'],
            'issued_at' => $payload['issued_at'],
            'expires_at' => $payload['expires_at'],
            'dry_run' => $payload['dry_run'],
            'signed_by' => $payload['signed_by'],
            'inputs_hash' => $payload['inputs_hash'],
            'parent_receipt_id' => $payload['parent_receipt_id'],
        ]);
        $payload['chain_hash'] = DecisionReceiptHash::hash([
            'parent_chain_hash' => $payload['parent_chain_hash'],
            'receipt_hash' => $payload['receipt_hash'],
        ]);

        AtlasLedgerEvent::query()->create([
            'event_id' => 'evt_'.$receiptId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_mcp_decision_receipt',
            'operator_id' => 'operator_mcp_decision_receipt',
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::DecisionIssued->value,
            'emitter_stage' => 'atlas.decide',
            'emitter_version' => 'atlas-decide-v2',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $receiptId),
            'occurred_at' => now(),
        ]);

        return $payload;
    }

    // ---------------- AOBG N2.F4 blackboard MCP tools ----------------

    public function test_claim_task_tool_creates_a_provider_safe_active_claim(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 401, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_claim_task', 'arguments' => [
                'engine' => 'claude_code', 'target' => 'app/Services/Foo.php', 'kind' => 'file',
            ]],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_claim_task', $structured['tool']);
        $this->assertTrue($structured['provider_bound']);
        $this->assertSame('active', $structured['status']);
        $this->assertSame('claude_code', $structured['claim']['engine']);
    }

    public function test_claim_task_tool_requires_engine_and_target(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 402, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_claim_task', 'arguments' => ['engine' => 'codex']],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertFalse($structured['ok']);
        $this->assertSame('engine_and_target_required', $structured['error']);
    }

    public function test_blackboard_status_tool_lists_active_claims_and_a_second_engine_conflicts(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // claude_code claims a file; codex then collides on the same file.
        $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 403, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_claim_task', 'arguments' => [
                'engine' => 'claude_code', 'target' => 'shared.php',
            ]],
        ]);
        $conflict = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 404, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_claim_task', 'arguments' => [
                'engine' => 'codex', 'target' => 'shared.php',
            ]],
        ])['result']['structuredContent'];
        $this->assertSame('conflict', $conflict['status']);
        $this->assertSame('claude_code', $conflict['conflict']['engine']);

        // The status tool lists the active claim, provider-safe + workspace-scoped.
        $status = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 405, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_blackboard_status', 'arguments' => []],
        ])['result']['structuredContent'];
        $this->assertTrue($status['ok']);
        $this->assertTrue($status['provider_bound']);
        $this->assertSame(1, $status['count']);
        $this->assertSame('claude_code', $status['claims'][0]['engine']);

        // With a target filter + except_engine, codex sees claude_code's claim as a
        // cross-engine conflict ("who else is editing this?").
        $who = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 406, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_blackboard_status', 'arguments' => [
                'target' => 'shared.php', 'except_engine' => 'codex',
            ]],
        ])['result']['structuredContent'];
        $this->assertSame(1, $who['count']);
        $this->assertSame('claude_code', $who['claims'][0]['engine']);
    }

    private function createBlackboardTable(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $migration->up();
    }
}
