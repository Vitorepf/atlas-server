<?php

namespace Tests\Feature;

use App\Models\AtlasTask;
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
        $this->assertSame('engineering-knowledge-base-overview', data_get($listPayload, 'items.0.slug'));

        $this->getJson('/engineering/knowledge?q=Knowledge&limit=5', $this->headers)
            ->assertOk()
            ->assertJsonPath('summary.status', 'ready')
            ->assertJsonPath('items.0.slug', 'engineering-knowledge-base-overview');

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

        $this->getJson('/engineering/knowledge/context?category=capability_matrix&limit=3', $this->headers)
            ->assertOk()
            ->assertJsonPath('knowledge_refs.0.category', 'capability_matrix')
            ->assertJsonPath('knowledge_refs.0.reason', 'matched_engineering_context');

        Artisan::call('atlas:engineering:knowledge', [
            'action' => 'context',
            '--category' => 'capability_matrix',
            '--limit' => 3,
            '--json' => true,
        ]);
        $contextPayload = json_decode(Artisan::output(), true);

        $this->assertSame('capability_matrix', data_get($contextPayload, 'knowledge_refs.0.category'));
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
            \App\Models\AtlasEngineeringKnowledgeItem::query()->create([
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
            $payload = $service->index(['workspace' => $workspace, 'prune' => true]);

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

            $codeRefs = $service->contextRefs(['contract' => ['tags' => ['engineering']]], 5);
            $this->assertNotEmpty($codeRefs);
            $this->assertSame('atlas_engineering_code_module', $codeRefs[0]['type']);

            $this->postJson('/engineering/knowledge/code/index', [
                'workspace' => $workspace,
                'dry_run' => true,
                'prune' => true,
            ], $this->headers)
                ->assertOk()
                ->assertJsonPath('dry_run', true)
                ->assertJsonPath('ok', true);

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
                '--json' => true,
            ]);
            $cliPayload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertTrue((bool) data_get($cliPayload, 'dry_run'));
            $this->assertGreaterThanOrEqual(5, data_get($cliPayload, 'summary.module_count'));

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
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');
        Schema::dropIfExists('atlas_engineering_knowledge_items');
        Schema::dropIfExists('atlas_tasks');
    }
}
