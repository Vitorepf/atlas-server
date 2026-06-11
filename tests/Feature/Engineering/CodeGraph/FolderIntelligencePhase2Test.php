<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering\CodeGraph;

use App\Jobs\AssembleWorkspaceFolderIntelligenceJob;
use App\Services\AtlasCode\WorkspaceFolderIntelligenceService;
use App\Services\AtlasCode\WorkspaceIntelligenceAssemblyService;
use App\Services\AtlasCode\WorkspaceIntelligenceStatusReader;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AP-818 Folder Intelligence Fase 2 — focused proofs per slice.
 *
 * Suite default is sqlite :memory: + array cache + sync queue (phpunit.xml);
 * following the proven sibling convention (CodeGraphEdgeBuilderTest), only the
 * two read-model tables the slices touch are booted by hand — RefreshDatabase
 * fatals on the raw-Postgres DDL elsewhere in the migration set.
 */
final class FolderIntelligencePhase2Test extends TestCase
{
    /** @var array<int,string> temp dirs to clean up */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        foreach ($this->tempDirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }

        parent::tearDown();
    }

    // ── F2.0 ────────────────────────────────────────────────────────────────

    public function test_v1_payload_is_byte_compatible_when_flag_off(): void
    {
        config()->set('atlas.code_folder_intelligence.v2_enabled', false);

        $repo = $this->makeGitRepo('repo-v1');
        $intel = app(WorkspaceFolderIntelligenceService::class)->inspect($repo);

        $this->assertSame(WorkspaceFolderIntelligenceService::SCHEMA_VERSION, $intel['schema_version']);
        $this->assertArrayNotHasKey('workspace_id', $intel);
        $this->assertArrayNotHasKey('intelligence', $intel);
    }

    public function test_v2_attaches_workspace_id_and_intelligence_status(): void
    {
        config()->set('atlas.code_folder_intelligence.v2_enabled', true);

        $repo = $this->makeGitRepo('repo-v2');
        $intel = app(WorkspaceFolderIntelligenceService::class)->inspect($repo);

        $this->assertSame(WorkspaceFolderIntelligenceService::SCHEMA_VERSION_V2, $intel['schema_version']);
        $this->assertSame('git_repository', $intel['classification']);
        $this->assertNotSame('', (string) ($intel['workspace_id'] ?? ''));
        $this->assertSame('portrait_only', $intel['intelligence']['intelligence_status']);
    }

    public function test_v2_umbrella_resolves_members_and_aggregate(): void
    {
        config()->set('atlas.code_folder_intelligence.v2_enabled', true);

        $umbrella = $this->makeTempDir('umbrella');
        $this->makeGitRepo('umbrella/alpha');
        $this->makeGitRepo('umbrella/beta');

        $intel = app(WorkspaceFolderIntelligenceService::class)->inspect($umbrella);

        $this->assertSame('umbrella', $intel['classification']);
        $this->assertCount(2, $intel['children']);
        foreach ($intel['children'] as $child) {
            $this->assertNotSame('', (string) ($child['workspace_id'] ?? ''));
            $this->assertArrayHasKey('intelligence_status', $child);
        }
        $this->assertSame(2, $intel['intelligence']['members_total']);
        $this->assertSame('portrait_only', $intel['intelligence']['intelligence_status']);
    }

    public function test_status_reader_marker_lifecycle(): void
    {
        $reader = app(WorkspaceIntelligenceStatusReader::class);

        $this->assertSame('portrait_only', $reader->status('ws-marker')['intelligence_status']);

        $reader->markQueued('ws-marker');
        $this->assertSame('assembly_pending', $reader->status('ws-marker')['intelligence_status']);

        $reader->markRunning('ws-marker');
        $this->assertSame('indexing', $reader->status('ws-marker')['intelligence_status']);

        $reader->markFailed('ws-marker');
        $this->assertSame('failed', $reader->status('ws-marker')['intelligence_status']);

        $reader->clearMarker('ws-marker');
        $this->assertSame('portrait_only', $reader->status('ws-marker')['intelligence_status']);

        $this->assertTrue($reader->needsAssembly('ws-marker'), 'workspace nunca indexado precisa de assembly');
    }

    public function test_scoped_status_slices_umbrella_graph_per_child(): void
    {
        $this->seedModule('umb', 'alpha_app', 'alpha/app', 100);
        $this->seedModule('umb', 'alpha_tests', 'alpha/tests', 40);
        $this->seedModule('umb', 'beta_src', 'beta/src', 7);

        $reader = app(WorkspaceIntelligenceStatusReader::class);

        $alpha = $reader->scopedStatus('umb', 'alpha');
        $beta = $reader->scopedStatus('umb', 'beta');
        $gamma = $reader->scopedStatus('umb', 'gamma');

        $this->assertSame(['indexed', 140], [$alpha['intelligence_status'], $alpha['symbols']]);
        $this->assertSame(['indexed', 7], [$beta['intelligence_status'], $beta['symbols']]);
        $this->assertSame('portrait_only', $gamma['intelligence_status']);
    }

    // ── F2.1 ────────────────────────────────────────────────────────────────

    public function test_queue_assembly_dispatches_unique_long_queue_job(): void
    {
        Queue::fake();

        $repo = $this->makeGitRepo('repo-queue');
        $result = app(WorkspaceIntelligenceAssemblyService::class)->queueAssembly($repo);

        $this->assertTrue($result['queued']);
        Queue::assertPushed(AssembleWorkspaceFolderIntelligenceJob::class, function (AssembleWorkspaceFolderIntelligenceJob $job) use ($repo): bool {
            return $job->workspacePath === $repo
                && $job->connection === 'database-long'
                && $job->queue === 'folder-intel'
                && $job->uniqueId() === sha1($repo)
                && $job->tries === 1;
        });

        // O estado já nasce honesto na resposta do upsert.
        $status = app(WorkspaceIntelligenceStatusReader::class)->status($result['workspace_id']);
        $this->assertSame('assembly_pending', $status['intelligence_status']);
    }

    public function test_queue_assembly_refuses_missing_path(): void
    {
        Queue::fake();

        $result = app(WorkspaceIntelligenceAssemblyService::class)->queueAssembly('/nao/existe/'.uniqid());

        $this->assertFalse($result['queued']);
        Queue::assertNothingPushed();
    }

    // ── F2.2 ────────────────────────────────────────────────────────────────

    public function test_multi_workspace_pack_isolates_outside_workspaces(): void
    {
        $this->seedSymbol('ws-a', 'AlphaWorkspaceAssembly', 'app/Alpha.php');
        $this->seedSymbol('ws-b', 'BetaWorkspaceAssembly', 'app/Beta.php');
        $this->seedSymbol('ws-c', 'GammaWorkspaceAssembly', 'app/Gamma.php');

        $retriever = app(CodeGraphContextRetriever::class);
        $pack = $retriever->packForWorkspaces('workspace assembly', ['ws-a', 'ws-b'], 800);

        $ids = array_map(static fn (array $c): string => (string) $c['id'], $pack['included']);
        $this->assertNotEmpty($ids);
        $this->assertContains('sym:AlphaWorkspaceAssembly', $ids);
        $this->assertContains('sym:BetaWorkspaceAssembly', $ids);
        $this->assertNotContains('sym:GammaWorkspaceAssembly', $ids, 'workspace fora do escopo NUNCA vaza');

        foreach ($pack['included'] as $candidate) {
            $this->assertContains($candidate['workspace_id'] ?? null, ['ws-a', 'ws-b'], 'pack umbrella é auditável por membro');
        }
    }

    public function test_single_workspace_pack_shape_is_unchanged(): void
    {
        $this->seedSymbol('ws-a', 'AlphaWorkspaceAssembly', 'app/Alpha.php');

        $retriever = app(CodeGraphContextRetriever::class);
        $single = $retriever->packFor('workspace assembly', 'ws-a', 800);
        $viaList = $retriever->packForWorkspaces('workspace assembly', ['ws-a'], 800);

        $this->assertSame($single, $viaList, 'lista de 1 id é byte-idêntica ao caminho provado');
        $this->assertArrayNotHasKey('workspace_id', $single['included'][0] ?? [], 'shape single não muda');
    }

    public function test_empty_workspace_list_yields_empty_pack(): void
    {
        $pack = app(CodeGraphContextRetriever::class)->packForWorkspaces('workspace assembly', ['', '  '], 800);

        $this->assertSame(0, $pack['count']);
        $this->assertSame([], $pack['included']);
    }

    // ── F2.3 ────────────────────────────────────────────────────────────────

    public function test_index_portrait_returns_real_named_facts(): void
    {
        $this->seedModule('ws-p', 'core', 'app', 50);
        $this->seedSymbol('ws-p', 'GET /things', 'routes/api.php', 'route');
        $this->seedSymbol('ws-p', 'create:things', 'database/migrations/x.php', 'migration_table');
        $this->seedSymbol('ws-p', 'atlas:things:sync', 'app/Console/Commands/T.php', 'cli_command');

        $portrait = app(WorkspaceIntelligenceStatusReader::class)->indexPortrait('ws-p');

        $this->assertNotNull($portrait);
        $this->assertSame('core', $portrait['top_modules'][0]['slug']);
        $this->assertContains('GET /things', $portrait['samples']['routes']);
        $this->assertContains('create:things', $portrait['samples']['migrations']);
        $this->assertContains('atlas:things:sync', $portrait['samples']['commands']);
        $this->assertArrayHasKey('route', $portrait['symbol_types']);
    }

    public function test_index_portrait_is_null_when_never_indexed(): void
    {
        $this->assertNull(app(WorkspaceIntelligenceStatusReader::class)->indexPortrait('ws-vazio'));
    }

    // ── F2.5 ────────────────────────────────────────────────────────────────

    public function test_context_scope_ids_degrades_to_single_for_non_umbrella(): void
    {
        $ids = app(WorkspaceFolderIntelligenceService::class)->contextScopeIds('workspace-sem-profile');

        $this->assertSame(['workspace-sem-profile'], $ids);
    }

    // ── infra ───────────────────────────────────────────────────────────────

    private function bootSchema(): void
    {
        Schema::create('atlas_engineering_code_modules', function ($table): void {
            $table->id();
            $table->string('slug');
            $table->string('name')->nullable();
            $table->string('root_path')->default('');
            $table->integer('symbol_count')->default(0);
            $table->string('status')->default('active');
            $table->string('workspace_id')->default('');
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_symbols', function ($table): void {
            $table->id();
            $table->string('symbol_type')->default('class');
            $table->string('symbol_name');
            $table->string('file_path')->default('');
            $table->string('signature')->nullable();
            $table->string('status')->default('active');
            $table->string('workspace_id')->default('');
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    private function dropSchema(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');
    }

    private function seedModule(string $workspaceId, string $slug, string $rootPath, int $symbols): void
    {
        DB::table('atlas_engineering_code_modules')->insert([
            'slug' => $slug,
            'name' => $slug,
            'root_path' => $rootPath,
            'symbol_count' => $symbols,
            'status' => 'active',
            'workspace_id' => $workspaceId,
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSymbol(string $workspaceId, string $name, string $filePath, string $type = 'class'): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $filePath,
            'signature' => $name,
            'status' => 'active',
            'workspace_id' => $workspaceId,
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeTempDir(string $suffix): string
    {
        // Nesting REAL: 'umbrella/alpha' vira um filho de verdade dentro de
        // 'umbrella' — é isso que a classificação de guarda-chuva enxerga.
        $root = sys_get_temp_dir().'/atlas-folder-intel-test-'.getmypid();
        $dir = $root.'/'.$suffix;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (! in_array($root, $this->tempDirs, true)) {
            $this->tempDirs[] = $root;
        }

        return $dir;
    }

    private function makeGitRepo(string $suffix): string
    {
        $dir = $this->makeTempDir($suffix);
        (new Process(['git', 'init', '--quiet', $dir]))->run();

        return $dir;
    }
}
