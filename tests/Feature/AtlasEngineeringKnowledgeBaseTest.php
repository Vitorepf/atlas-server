<?php

namespace Tests\Feature;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Models\AtlasTask;
use App\Models\AtlasToolRun;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\Runtime\WorkspaceProfile;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringContextPackService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasEngineeringKnowledgeBaseTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_sync_indexes_canonical_docs_and_exposes_cli_and_api(): void
    {
        $service = app(EngineeringKnowledgeBaseService::class);

        $payload = $service->sync(['prune' => true]);

        $this->assertTrue($payload['ok']);
        $this->assertGreaterThanOrEqual(5, data_get($payload, 'summary.created'));
        $this->assertDatabaseHas('atlas_engineering_knowledge_items', [
            'slug' => 'engineering-knowledge-base-overview',
            'category' => 'architecture',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('atlas:engineering:knowledge', [
            'action' => 'list',
            '--category' => 'architecture',
            '--json' => true,
        ]);
        $listPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertContains(
            'engineering-knowledge-base-overview',
            collect(data_get($listPayload, 'items', []))->pluck('slug')->all(),
        );

        $showExitCode = Artisan::call('atlas:engineering:knowledge', [
            'action' => 'show',
            'item' => 'engineering-knowledge-base-overview',
            '--json' => true,
        ]);
        $showPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $showExitCode);
        $this->assertSame('engineering-knowledge-base-overview', data_get($showPayload, 'knowledge_item.slug'));

        $knowledgeResponse = $this->getJson('/engineering/knowledge?q=overview&limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('summary.status', 'ready');

        $this->assertContains(
            'engineering-knowledge-base-overview',
            collect($knowledgeResponse->json('items'))->pluck('slug')->all(),
        );

        $this->getJson('/engineering/knowledge/items/engineering-knowledge-base-overview', $this->headers)
            ->assertOk()
            ->assertJsonPath('knowledge_item.category', 'architecture')
            ->assertJsonPath('knowledge_item.source_type', 'canonical_doc');

        $this->postJson('/engineering/knowledge/sync', [
            'dry_run' => true,
            'prune' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('ok', true);

        $this->getJson('/engineering/knowledge/context?category=tool_runtime&limit=3', $this->headers)
            ->assertOk()
            ->assertJsonPath('knowledge_refs.0.category', 'tool_runtime')
            ->assertJsonPath('knowledge_refs.0.reason', 'matched_engineering_context');

        Artisan::call('atlas:engineering:knowledge', [
            'action' => 'context',
            '--category' => 'tool_runtime',
            '--limit' => 3,
            '--json' => true,
        ]);
        $contextPayload = json_decode(Artisan::output(), true);

        $this->assertSame('tool_runtime', data_get($contextPayload, 'knowledge_refs.0.category'));
    }

    public function test_docs_health_reports_bootstrap_contract_and_split_required_docs(): void
    {
        $exitCode = Artisan::call('atlas:engineering:knowledge', [
            'action' => 'docs-health',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', data_get($payload, 'status'));
        $this->assertSame(0, data_get($payload, 'summary.required_missing_count'));
        $this->assertSame(0, data_get($payload, 'summary.frontmatter_violation_count'));
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md',
            collect(data_get($payload, 'required_docs', []))->pluck('path')->all(),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
            collect(data_get($payload, 'required_docs', []))->pluck('path')->all(),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md',
            collect(data_get($payload, 'required_docs', []))->pluck('path')->all(),
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md',
            collect(data_get($payload, 'oversized_docs', []))->pluck('path')->all(),
        );
    }

    public function test_engineering_context_pack_includes_knowledge_refs(): void
    {
        app(EngineeringKnowledgeBaseService::class)->sync();
        $task = AtlasTask::query()->create([
            'title' => 'Melhorar o Harness',
            'description' => 'Manutencao profissional do Engineering Harness.',
            'metadata' => [],
        ]);

        $profiler = $this->createMock(WorkspaceProfiler::class);
        $profiler->method('profile')->willReturn(new WorkspaceProfile(
            workspace: base_path(),
            repoRoot: base_path(),
            branch: 'main',
            head: 'abc123',
            stack: ['php', 'laravel'],
            testCommands: ['php artisan test'],
            importantFiles: ['app/Services/Engineering/EngineeringHarnessRunnerService.php'],
        ));

        $service = new EngineeringContextPackService(
            $profiler,
            app(AtlasMemoryRegistryService::class),
            app(EngineeringKnowledgeBaseService::class),
        );

        $payload = $service->build($task, null, base_path(), [
            'goal' => 'Manter Harness',
            'tags' => ['quality'],
            'likely_files' => [],
        ], [
            'blueprint_id' => 'test',
        ], []);

        $this->assertNotEmpty($payload['knowledge_refs']);
        $this->assertContains('engineering_knowledge', collect($payload['prompt_sections'])->pluck('kind')->all());
        $this->assertContains('docs/engineering-knowledge-base/README.md', $payload['selected_files']);
        $this->assertSame('atlas_engineering_knowledge_item', data_get($payload, 'knowledge_refs.0.type'));
    }

    public function test_engineering_context_pack_includes_recent_tool_evidence_refs(): void
    {
        $workspace = base_path();
        $task = AtlasTask::query()->create([
            'title' => 'Usar evidencia operacional recente',
            'description' => 'O context pack deve carregar sinais recentes do Tool Runtime.',
            'metadata' => [],
        ]);

        $toolRun = AtlasToolRun::query()->create([
            'tool_slug' => 'atlas_code_intelligence',
            'surface' => 'engineering_code_intelligence',
            'workspace_hash' => hash('sha256', realpath($workspace) ?: $workspace),
            'workspace' => $workspace,
            'run_context_type' => 'engineering_run',
            'run_context_id' => 'run-with-code-intel',
            'status' => 'failed',
            'required' => true,
            'failure_policy' => 'blocking',
            'policy_decision' => 'allowed',
            'duration_ms' => 42,
            'summary_json' => ['module_count' => 22],
            'normalized_result_json' => [],
            'policy_decision_json' => [],
            'metadata_json' => [],
        ]);
        $toolRun->findings()->create([
            'title' => 'Code intelligence drift detected',
            'message' => 'Context pack should expose blocking tool evidence.',
            'severity' => 'high',
            'file_path' => 'app/Services/Engineering/EngineeringContextPackService.php',
            'line' => 42,
            'blocks_resolved' => true,
            'metadata_json' => [],
        ]);

        $profiler = $this->createMock(WorkspaceProfiler::class);
        $profiler->method('profile')->willReturn(new WorkspaceProfile(
            workspace: $workspace,
            repoRoot: $workspace,
            branch: 'main',
            head: 'abc123',
            stack: ['php', 'laravel'],
            testCommands: ['php artisan test'],
            importantFiles: [],
        ));

        $payload = (new EngineeringContextPackService(
            $profiler,
            app(AtlasMemoryRegistryService::class),
            app(EngineeringKnowledgeBaseService::class),
        ))->build($task, null, $workspace, [
            'goal' => 'Usar evidence refs',
            'likely_files' => [],
        ], [
            'blueprint_id' => 'tool-evidence-refs',
        ], []);

        $this->assertSame('atlas_tool_run', data_get($payload, 'tool_evidence_refs.0.type'));
        $this->assertSame('atlas_code_intelligence', data_get($payload, 'tool_evidence_refs.0.tool_slug'));
        $this->assertSame('failed', data_get($payload, 'tool_evidence_refs.0.status'));
        $this->assertSame(1, data_get($payload, 'tool_evidence_refs.0.blocking_finding_count'));
        $this->assertSame(
            'app/Services/Engineering/EngineeringContextPackService.php',
            data_get($payload, 'tool_evidence_refs.0.blocking_findings.0.file_path'),
        );
        $this->assertContains('tool_evidence', collect($payload['prompt_sections'])->pluck('kind')->all());
        $this->assertContains('app/Services/Engineering/EngineeringContextPackService.php', $payload['selected_files']);
    }

    public function test_code_intelligence_indexes_modules_symbols_routes_commands_migrations_tests_and_doc_links(): void
    {
        $workspace = storage_path('framework/testing/code-intel-'.Str::uuid());
        File::ensureDirectoryExists($workspace.'/app/Services/Engineering');
        File::ensureDirectoryExists($workspace.'/app/Console/Commands');
        File::ensureDirectoryExists($workspace.'/app/Http/Controllers');
        File::ensureDirectoryExists($workspace.'/routes');
        File::ensureDirectoryExists($workspace.'/database/migrations');
        File::ensureDirectoryExists($workspace.'/tests/Feature');

        File::put($workspace.'/app/Services/Engineering/FooService.php', <<<'PHP'
<?php

namespace App\Services\Engineering;

class FooService
{
    public function handle(): void
    {
    }
}
PHP);
        File::put($workspace.'/app/Console/Commands/AtlasEngineeringFooCommand.php', <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AtlasEngineeringFooCommand extends Command
{
    protected $signature = 'atlas:engineering:foo {--json}';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
PHP);
        File::put($workspace.'/app/Http/Controllers/EngineeringFooController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

class EngineeringFooController
{
    public function show(): void
    {
    }
}
PHP);
        File::put($workspace.'/routes/api.php', <<<'PHP'
<?php

use App\Http\Controllers\EngineeringFooController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/mobile')->group(function (): void {
    Route::get('/foo', [EngineeringFooController::class, 'show']);
});
PHP);
        File::put($workspace.'/database/migrations/2026_05_02_010001_create_foo_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foo_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
    }
};
PHP);
        File::put($workspace.'/tests/Feature/AtlasEngineeringFooTest.php', <<<'PHP'
<?php

class AtlasEngineeringFooTest
{
    public function test_foo_route_is_indexed(): void
    {
    }
}
PHP);

        try {
            AtlasEngineeringKnowledgeItem::query()->create([
                'id' => (string) Str::uuid(),
                'slug' => 'foo-engineering-doc',
                'title' => 'Foo Engineering Doc',
                'category' => 'architecture',
                'status' => 'active',
                'priority' => 90,
                'source_type' => 'canonical_doc',
                'canonical_path' => 'docs/engineering-knowledge-base/foo.md',
                'source_hash' => hash('sha256', 'foo-source'),
                'content_hash' => hash('sha256', 'foo-content'),
                'summary' => 'Documenta o fluxo Foo de engenharia.',
                'body_excerpt' => 'Foo usa service, command, route, migration e teste.',
                'tags_json' => ['engineering', 'code-intelligence'],
                'related_paths_json' => [
                    'app/Services/Engineering/FooService.php',
                    'app/Console/Commands/AtlasEngineeringFooCommand.php',
                    'routes/api.php',
                ],
                'capabilities_json' => ['engineering_harness_services', 'code_intelligence_index'],
                'decisions_json' => [],
                'maintenance_json' => [],
                'metadata' => [],
                'indexed_at' => now(),
            ]);

            $service = app(EngineeringCodeIntelligenceService::class);
            $runContextId = (string) Str::uuid();
            $payload = $service->index([
                'workspace' => $workspace,
                'prune' => true,
                'run_context_type' => 'engineering_run',
                'run_context_id' => $runContextId,
            ]);

            $this->assertTrue($payload['ok']);
            $this->assertGreaterThanOrEqual(5, data_get($payload, 'summary.module_count'));
            $this->assertGreaterThanOrEqual(10, data_get($payload, 'summary.symbol_count'));
            $this->assertGreaterThanOrEqual(1, data_get($payload, 'summary.doc_link_count'));
            $this->assertDatabaseHas('atlas_engineering_code_modules', [
                'slug' => 'engineering_harness_services',
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('atlas_engineering_code_symbols', [
                'symbol_type' => 'cli_command',
                'symbol_name' => 'atlas:engineering:foo',
            ]);
            $this->assertDatabaseHas('atlas_engineering_code_symbols', [
                'symbol_type' => 'route',
                'symbol_name' => 'GET /v1/mobile/foo',
            ]);
            $this->assertDatabaseHas('atlas_engineering_code_symbols', [
                'symbol_type' => 'migration_table',
                'symbol_name' => 'create:foo_items',
            ]);
            $this->assertDatabaseHas('atlas_engineering_code_symbols', [
                'symbol_type' => 'test_method',
                'symbol_name' => 'AtlasEngineeringFooTest::test_foo_route_is_indexed',
            ]);
            $this->assertDatabaseHas('atlas_engineering_doc_links', [
                'target_path' => 'app/Services/Engineering/FooService.php',
                'status' => 'current',
            ]);
            $this->assertDatabaseHas('atlas_tool_runs', [
                'tool_slug' => 'atlas_code_intelligence',
                'surface' => 'engineering_code_intelligence',
                'run_context_type' => 'engineering_run',
                'run_context_id' => $runContextId,
                'status' => 'passed',
            ]);

            $codeRefs = $service->contextRefs(['contract' => ['tags' => ['engineering']]], 5);
            $this->assertNotEmpty($codeRefs);
            $this->assertSame('atlas_engineering_code_module', $codeRefs[0]['type']);

            $this->postJson('/engineering/knowledge/code/index', [
                'workspace' => $workspace,
                'dry_run' => true,
                'prune' => true,
                'run_context_type' => 'api_context',
                'run_context_id' => 'code-index-api',
            ], $this->headers)
                ->assertOk()
                ->assertJsonPath('dry_run', true)
                ->assertJsonPath('ok', true);
            $this->assertDatabaseHas('atlas_tool_runs', [
                'tool_slug' => 'atlas_code_intelligence',
                'run_context_type' => 'api_context',
                'run_context_id' => 'code-index-api',
                'status' => 'skipped',
            ]);

            $this->getJson('/engineering/knowledge/code/modules?q=engineering&limit=10', $this->headers)
                ->assertOk()
                ->assertJsonFragment(['slug' => 'engineering_harness_services']);

            $this->getJson('/engineering/knowledge/code/modules/engineering_harness_services', $this->headers)
                ->assertOk()
                ->assertJsonPath('module.slug', 'engineering_harness_services');

            $this->getJson('/engineering/knowledge/code/symbols?symbol_type=cli_command&limit=10', $this->headers)
                ->assertOk()
                ->assertJsonFragment(['symbol_name' => 'atlas:engineering:foo']);

            $this->getJson('/engineering/knowledge/code/symbols?module=engineering_harness_cli&symbol_type=cli_command&limit=10', $this->headers)
                ->assertOk()
                ->assertJsonFragment(['symbol_name' => 'atlas:engineering:foo']);

            $exitCode = Artisan::call('atlas:engineering:knowledge', [
                'action' => 'index-code',
                '--workspace' => $workspace,
                '--dry-run' => true,
                '--run-context-type' => 'cli_context',
                '--run-context-id' => 'code-index-cli',
                '--json' => true,
            ]);
            $cliPayload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertTrue((bool) data_get($cliPayload, 'dry_run'));
            $this->assertGreaterThanOrEqual(5, data_get($cliPayload, 'summary.module_count'));
            $this->assertDatabaseHas('atlas_tool_runs', [
                'tool_slug' => 'atlas_code_intelligence',
                'run_context_type' => 'cli_context',
                'run_context_id' => 'code-index-cli',
                'status' => 'skipped',
            ]);

            Artisan::call('atlas:engineering:knowledge', [
                'action' => 'modules',
                '--q' => 'engineering',
                '--json' => true,
            ]);
            $modulesPayload = json_decode(Artisan::output(), true);

            $this->assertContains(
                'engineering_harness_services',
                collect($modulesPayload['modules'] ?? [])->pluck('slug')->all(),
            );

            $exitCode = Artisan::call('atlas:engineering:knowledge', [
                'action' => 'show-module',
                'item' => 'engineering_harness_services',
                '--json' => true,
            ]);
            $modulePayload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('engineering_harness_services', data_get($modulePayload, 'module.slug'));

            $freshAudit = $service->audit(['workspace' => $workspace, 'limit' => 10]);
            $this->assertSame('fresh', $freshAudit['status']);
            $this->assertSame(0, data_get($freshAudit, 'summary.drift.total'));

            File::put($workspace.'/app/Services/Engineering/FooService.php', <<<'PHP'
<?php

namespace App\Services\Engineering;

class FooService
{
    public function handle(): void
    {
    }

    public function repair(): void
    {
    }
}
PHP);

            $driftAudit = $service->audit([
                'workspace' => $workspace,
                'limit' => 10,
                'run_context_type' => 'engineering_run',
                'run_context_id' => $runContextId,
            ]);
            $this->assertSame('drift_detected', $driftAudit['status']);
            $this->assertGreaterThan(0, data_get($driftAudit, 'summary.drift.total'));
            $this->assertSame(
                'engineering_harness_services',
                data_get($driftAudit, 'drift.modules.changed.0.slug'),
            );
            $this->assertGreaterThanOrEqual(1, data_get($driftAudit, 'summary.drift.symbols.added'));
            $auditToolRun = AtlasToolRun::query()
                ->where('tool_slug', 'atlas_code_intelligence')
                ->where('run_context_type', 'engineering_run')
                ->where('run_context_id', $runContextId)
                ->where('status', 'failed')
                ->latest()
                ->firstOrFail();
            $this->assertSame('audit', data_get($auditToolRun->metadata_json, 'operation'));

            $this->getJson('/engineering/knowledge/code/audit?workspace='.rawurlencode($workspace).'&limit=10&run_context_type=api_context&run_context_id=code-audit-api', $this->headers)
                ->assertOk()
                ->assertJsonPath('dry_run', true)
                ->assertJsonPath('writes', false)
                ->assertJsonPath('status', 'drift_detected');
            $this->assertDatabaseHas('atlas_tool_runs', [
                'tool_slug' => 'atlas_code_intelligence',
                'run_context_type' => 'api_context',
                'run_context_id' => 'code-audit-api',
                'status' => 'failed',
            ]);

            $exitCode = Artisan::call('atlas:engineering:knowledge', [
                'action' => 'audit-code',
                '--workspace' => $workspace,
                '--limit' => 10,
                '--run-context-type' => 'cli_context',
                '--run-context-id' => 'code-audit-cli',
                '--json' => true,
            ]);
            $auditPayload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('drift_detected', data_get($auditPayload, 'status'));
            $this->assertFalse((bool) data_get($auditPayload, 'writes'));
            $this->assertDatabaseHas('atlas_tool_runs', [
                'tool_slug' => 'atlas_code_intelligence',
                'run_context_type' => 'cli_context',
                'run_context_id' => 'code-audit-cli',
                'status' => 'failed',
            ]);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    private function createTables(): void
    {
        $this->dropTables();

        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('domain')->default('atlas');
            $table->uuid('project_id')->nullable();
            $table->uuid('project_step_id')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('title', 220);
            $table->string('category', 80);
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('source_type', 80)->default('canonical_doc');
            $table->text('canonical_path');
            $table->string('source_hash', 64);
            $table->string('content_hash', 64);
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('tags_json')->default('[]');
            $table->json('related_paths_json')->default('[]');
            $table->json('capabilities_json')->default('[]');
            $table->json('decisions_json')->default('[]');
            $table->json('maintenance_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('name', 220);
            $table->string('layer', 80)->index();
            $table->string('root_path', 500)->nullable()->index();
            $table->string('primary_language', 40)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('owner', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('symbol_count')->default(0);
            $table->unsignedInteger('route_count')->default(0);
            $table->unsignedInteger('command_count')->default(0);
            $table->unsignedInteger('migration_count')->default(0);
            $table->unsignedInteger('test_count')->default(0);
            $table->string('source_hash', 64)->index();
            $table->string('docs_hash', 64)->nullable()->index();
            $table->json('tags_json')->default('[]');
            $table->json('related_docs_json')->default('[]');
            $table->json('related_tests_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable()->index();
            $table->string('symbol_type', 60)->index();
            $table->string('symbol_name', 300)->index();
            $table->string('file_path', 500)->index();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->string('language', 40)->nullable()->index();
            $table->text('signature')->nullable();
            $table->string('namespace', 220)->nullable();
            $table->string('parent_symbol', 300)->nullable()->index();
            $table->string('visibility', 40)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->string('source_hash', 64)->index();
            $table->json('related_doc_ids_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['symbol_type', 'source_hash'], 'uniq_atlas_eng_code_symbol_source');
        });

        Schema::create('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('knowledge_item_id')->nullable()->index();
            $table->uuid('module_id')->nullable()->index();
            $table->uuid('symbol_id')->nullable()->index();
            $table->string('link_type', 60)->index();
            $table->string('status', 40)->default('current')->index();
            $table->string('canonical_path', 500)->index();
            $table->string('target_path', 500)->nullable()->index();
            $table->string('doc_hash', 64)->nullable()->index();
            $table->string('target_hash', 64)->nullable()->index();
            $table->string('link_hash', 64)->unique();
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->string('type', 40)->default('validator');
            $table->string('category', 80);
            $table->text('description')->nullable();
            $table->string('homepage', 240)->nullable();
            $table->string('license_posture', 80)->default('open_source');
            $table->string('cost_posture', 80)->default('free_local');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedSmallInteger('default_timeout_seconds')->default(120);
            $table->string('default_failure_policy', 40)->default('advisory');
            $table->string('risk_level', 24)->default('low');
            $table->string('status', 32)->default('active');
            $table->string('execution_tier', 24)->default('T1');
            $table->string('expected_cost', 40)->default('local_fast');
            $table->string('default_trigger', 80)->default('manual_or_policy');
            $table->string('authority_role', 40)->default('primary');
            $table->string('authority_group', 80)->nullable();
            $table->string('detected_version', 120)->nullable();
            $table->json('capabilities_json')->default('[]');
            $table->json('runtime_json')->default('{}');
            $table->json('detect_json')->default('{}');
            $table->json('outputs_json')->default('[]');
            $table->json('risks_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id');
            $table->string('workspace_hash', 64);
            $table->string('execution_layer', 40);
            $table->string('status', 32);
            $table->string('version', 120)->nullable();
            $table->string('binary_path_hash', 64)->nullable();
            $table->string('node_modules_path_hash', 64)->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 40)->default('global');
            $table->string('scope_id', 120)->nullable();
            $table->string('tool_slug', 120);
            $table->boolean('enabled')->default(true);
            $table->json('required_when_json')->default('[]');
            $table->string('failure_policy', 40)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->json('thresholds_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->nullable();
            $table->string('tool_slug', 120);
            $table->string('surface', 80)->default('cli');
            $table->string('workspace_hash', 64)->nullable();
            $table->text('workspace')->nullable();
            $table->string('run_context_type', 80)->nullable();
            $table->string('run_context_id', 120)->nullable();
            $table->string('status', 32);
            $table->boolean('required')->default(false);
            $table->string('failure_policy', 40)->default('advisory');
            $table->string('policy_decision', 40)->default('allowed');
            $table->string('command_hash', 64)->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->uuid('stdout_artifact_id')->nullable();
            $table->uuid('stderr_artifact_id')->nullable();
            $table->json('summary_json')->default('{}');
            $table->json('normalized_result_json')->default('{}');
            $table->json('policy_decision_json')->default('{}');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('type', 60);
            $table->text('path');
            $table->string('filename', 180);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64);
            $table->boolean('is_redacted')->default(true);
            $table->json('preview_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('rule_id', 180)->nullable();
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('severity', 24)->default('medium');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->boolean('blocks_resolved')->default(false);
            $table->string('waiver_id', 120)->nullable();
            $table->string('status', 32)->default('open');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_tool_findings');
        Schema::dropIfExists('atlas_tool_artifacts');
        Schema::dropIfExists('atlas_tool_runs');
        Schema::dropIfExists('atlas_tool_policies');
        Schema::dropIfExists('atlas_tool_installations');
        Schema::dropIfExists('atlas_tool_definitions');
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');
        Schema::dropIfExists('atlas_engineering_knowledge_items');
        Schema::dropIfExists('atlas_tasks');
    }
}
