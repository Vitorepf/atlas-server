<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AOBG N1.F3 — multi-project: the gateway works in ANY project, AUTO-SCOPED.
 *
 * Locks the F3 contract over a sqlite, COST-FREE fixture (no provider call, no real
 * index run — the heavy index is mocked via the injected runner seam). The symbols
 * read-model is the W-1 workspace_id-keyed code-intelligence table, the SAME table
 * F1's pack scopes to, so the no-cross-leak guarantee is exercised on the real path.
 *
 * Asserts: status scopes to the resolved workspace ONLY (project B never reflects A);
 * an un-indexed cwd reports needs_onboarding=true; auto_onboard OFF does NOT run the
 * index (the runner is never called); ON triggers it (the runner IS called) and the
 * post-index status flips to indexed; the MCP tool reports honestly + is in the
 * inventory; the CLI exits 0.
 */
final class AtlasAobgWorkspaceOnboardingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCodeSymbolsTable();
        config()->set('atlas.aobg.auto_onboard', false);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        parent::tearDown();
    }

    public function test_status_is_workspace_scoped_and_never_leaks_another_workspace(): void
    {
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $primaryId = $identity->default();
        $otherPath = sys_get_temp_dir().'/aobg-f3-'.Str::random(6);
        @mkdir($otherPath, 0777, true);
        $otherId = $identity->resolve($otherPath);

        $this->assertNotSame($primaryId, $otherId, 'two workspaces must resolve to distinct ids');

        // Seed only the PRIMARY workspace with symbols. Project B stays empty.
        $this->seedCodeSymbol('FooService', $primaryId);
        $this->seedCodeSymbol('BarService', $primaryId);

        $service = $this->service();

        $statusA = $service->status(); // default → primary
        $statusB = $service->status(['cwd' => $otherPath]); // the other project

        @rmdir($otherPath);

        // Project A (primary) is indexed with EXACTLY its two symbols.
        $this->assertSame($primaryId, $statusA['workspace_id']);
        $this->assertTrue($statusA['indexed']);
        $this->assertSame(2, $statusA['symbols']);
        $this->assertFalse($statusA['needs_onboarding']);

        // Project B sees ZERO of A's symbols (no cross-leak) → needs onboarding.
        $this->assertSame($otherId, $statusB['workspace_id']);
        $this->assertFalse($statusB['indexed']);
        $this->assertSame(0, $statusB['symbols']);
        $this->assertTrue($statusB['needs_onboarding']);
        $this->assertStringContainsString('index-code', $statusB['onboard_command']);
    }

    public function test_registered_atlas_slug_uses_profile_path_and_scope(): void
    {
        $this->seedCodeSymbol('AtlasUmbrellaSymbol', 'atlas');

        $status = $this->service()->status(['workspace' => 'atlas']);

        $this->assertSame('atlas', $status['workspace_id']);
        $this->assertSame(realpath(dirname(base_path())), $status['workspace_path']);
        $this->assertTrue($status['indexed']);
        $this->assertSame(1, $status['symbols']);
        $this->assertFalse($status['needs_onboarding']);
        $this->assertStringContainsString('index-code', $status['onboard_command']);
        $this->assertStringContainsString((string) realpath(dirname(base_path())), $status['onboard_command']);
    }

    public function test_unindexed_cwd_reports_needs_onboarding_and_auto_onboard_off_does_not_run_index(): void
    {
        config()->set('atlas.aobg.auto_onboard', false);

        $otherPath = sys_get_temp_dir().'/aobg-f3-off-'.Str::random(6);
        @mkdir($otherPath, 0777, true);

        $ran = false;
        $service = $this->service()->setIndexRunner(function () use (&$ran): array {
            $ran = true; // must NEVER be reached with the gate OFF

            return ['ok' => true, 'reason' => 'indexed'];
        });

        $result = $service->onboard(['cwd' => $otherPath]);

        @rmdir($otherPath);

        $this->assertFalse($ran, 'auto_onboard OFF must NOT run the heavy index');
        $this->assertSame('offer_only', $result['action']);
        $this->assertFalse($result['triggered_index']);
        $this->assertFalse($result['auto_onboard']);
        $this->assertTrue($result['status']['needs_onboarding']);
        // The index command is still OFFERED so an operator can run it.
        $this->assertStringContainsString('index-code', $result['status']['onboard_command']);
        $this->assertStringContainsString($otherPath, $result['status']['onboard_command']);
    }

    public function test_auto_onboard_on_triggers_the_index_and_flips_status(): void
    {
        config()->set('atlas.aobg.auto_onboard', true);

        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $otherPath = sys_get_temp_dir().'/aobg-f3-on-'.Str::random(6);
        @mkdir($otherPath, 0777, true);
        $otherId = $identity->resolve($otherPath);

        // The runner simulates the real index by seeding the workspace's symbols, so the
        // post-index re-read status flips to indexed — WITHOUT running a heavy real index.
        $calledWith = null;
        $service = $this->service()->setIndexRunner(function (string $path, string $wsId) use (&$calledWith): array {
            $calledWith = ['path' => $path, 'workspace_id' => $wsId];
            $this->seedCodeSymbol('OnboardedSymbol', $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->onboard(['cwd' => $otherPath]);

        @rmdir($otherPath);

        $this->assertNotNull($calledWith, 'auto_onboard ON must run the index');
        $this->assertSame($otherId, $calledWith['workspace_id']);
        $this->assertTrue($result['triggered_index']);
        $this->assertTrue($result['auto_onboard']);
        $this->assertSame('onboarded', $result['action']);
        // The post-index status reflects reality: now indexed, no longer needs onboarding.
        $this->assertTrue($result['status']['indexed']);
        $this->assertSame(1, $result['status']['symbols']);
        $this->assertFalse($result['status']['needs_onboarding']);
        $this->assertTrue($result['ok']);
    }

    public function test_already_indexed_workspace_does_not_re_run_index(): void
    {
        config()->set('atlas.aobg.auto_onboard', true);
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $this->seedCodeSymbol('FooService', $identity->default());

        $ran = false;
        $service = $this->service()->setIndexRunner(function () use (&$ran): array {
            $ran = true;

            return ['ok' => true];
        });

        $result = $service->onboard(); // primary, already indexed

        $this->assertFalse($ran, 'an already-indexed workspace must not re-run the index');
        $this->assertSame('already_indexed', $result['action']);
        $this->assertFalse($result['triggered_index']);
        $this->assertTrue($result['status']['indexed']);
    }

    public function test_degrades_honestly_when_symbols_table_missing(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');

        $status = $this->service()->status();

        // No table → honest "not indexed", never a throw, never a fabricated count.
        $this->assertFalse($status['indexed']);
        $this->assertSame(0, $status['symbols']);
        $this->assertNull($status['last_index']);
        $this->assertTrue($status['needs_onboarding']);
    }

    public function test_mcp_tool_reports_workspace_status_and_is_in_inventory(): void
    {
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $this->seedCodeSymbol('FooService', $identity->default());

        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // Listed in the inventory.
        $list = $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $this->assertContains('atlas_workspace_status', array_column($list['result']['tools'], 'name'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_workspace_status', 'arguments' => []],
        ]);
        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_workspace_status', $structured['tool']);
        $this->assertSame($identity->default(), $structured['workspace_id']);
        $this->assertTrue($structured['indexed']);
        $this->assertSame(1, $structured['symbols']);
        $this->assertFalse($structured['needs_onboarding']);

        // An un-indexed cwd → honest needs_onboarding=true via the SAME tool.
        $otherPath = sys_get_temp_dir().'/aobg-f3-mcp-'.Str::random(6);
        @mkdir($otherPath, 0777, true);
        $unindexed = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_workspace_status', 'arguments' => ['cwd' => $otherPath]],
        ]);
        @rmdir($otherPath);
        $this->assertTrue($unindexed['result']['structuredContent']['needs_onboarding']);
        $this->assertSame(0, $unindexed['result']['structuredContent']['symbols']);
    }

    public function test_cli_command_runs_status_and_onboard_json(): void
    {
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $this->seedCodeSymbol('FooService', $identity->default());

        $this->artisan('atlas:aobg:workspace', ['action' => 'status', '--json' => true])
            ->assertSuccessful();

        // Default action (status) + markdown render also exits 0.
        $this->artisan('atlas:aobg:workspace')->assertSuccessful();

        // Onboard with auto_onboard OFF (offer-only) is a clean exit 0, never a failure.
        config()->set('atlas.aobg.auto_onboard', false);
        $otherPath = sys_get_temp_dir().'/aobg-f3-cli-'.Str::random(6);
        @mkdir($otherPath, 0777, true);
        $this->artisan('atlas:aobg:workspace', ['action' => 'onboard', '--cwd' => $otherPath, '--json' => true])
            ->assertSuccessful();
        @rmdir($otherPath);
    }

    // ------------------------------------------------------------------
    // fixtures (all sqlite, cost-free — no provider call, no real index)
    // ------------------------------------------------------------------

    private function service(): AtlasAobgWorkspaceOnboardingService
    {
        return $this->app->make(AtlasAobgWorkspaceOnboardingService::class);
    }

    /** Symbols table WITH the W-1 workspace_id column (so scoping is exercised). */
    private function createCodeSymbolsTable(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable()->index();
            $table->string('symbol_type', 60)->index();
            $table->string('symbol_name', 300)->index();
            $table->string('file_path', 500)->index();
            $table->string('language', 40)->nullable()->index();
            $table->text('signature')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('source_hash', 64)->index();
            $table->string('workspace_id', 160)->default('atlas-server')->index();
            $table->timestamp('indexed_at')->nullable();
            $table->json('related_doc_ids_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function seedCodeSymbol(string $name, string $workspaceId): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'symbol_type' => 'class',
            'symbol_name' => $name,
            'file_path' => 'app/Services/Ai/'.$name.'.php',
            'language' => 'php',
            'signature' => 'class '.$name,
            'status' => 'active',
            'source_hash' => hash('sha256', $workspaceId.$name),
            'workspace_id' => $workspaceId,
            'indexed_at' => now(),
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
