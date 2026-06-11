<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\AtlasProviderProjectionService;
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
        $this->createDocLinksTable();
        $this->createWorkspaceProfilesTable();
        config()->set('atlas.aobg.auto_onboard', false);
        config()->set('atlas.ai.workdir', base_path());

        $atlasProjects = require config_path('atlas_projects.php');
        config()->set('atlas_projects.default_slug', $atlasProjects['default_slug'] ?? 'atlas');
        config()->set('atlas_projects.profiles', $atlasProjects['profiles'] ?? []);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_workspace_profiles');
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

    public function test_activate_registers_workspace_writes_provider_bootstrap_and_indexes(): void
    {
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $workspacePath = sys_get_temp_dir().'/aobg-activate-'.Str::random(6);
        $appPath = $workspacePath.'/frontend';
        @mkdir($appPath, 0777, true);
        $canonicalWorkspacePath = realpath($workspacePath) ?: $workspacePath;
        file_put_contents($appPath.'/package.json', json_encode([
            'scripts' => ['test' => 'vitest run', 'build' => 'vite build'],
        ], JSON_PRETTY_PRINT));

        $workspaceId = $identity->resolve($workspacePath);
        $calledWith = null;
        $service = $this->service()->setIndexRunner(function (string $path, string $wsId) use (&$calledWith): array {
            $calledWith = ['path' => $path, 'workspace_id' => $wsId];
            $this->seedCodeSymbol('ActivatedSymbol', $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->activate(['cwd' => $workspacePath]);

        $this->assertSame($canonicalWorkspacePath, $calledWith['path']);
        $this->assertSame($workspaceId, $calledWith['workspace_id']);
        $this->assertTrue($result['ok']);
        $this->assertSame('activated', $result['action']);
        $this->assertTrue($result['triggered_index']);
        $this->assertTrue($result['status']['indexed']);
        $this->assertSame(1, $result['status']['symbols']);
        $this->assertDatabaseHas('atlas_workspace_profiles', [
            'slug' => $workspaceId,
            'workspace_path' => $canonicalWorkspacePath,
            'source' => 'aobg_workspace_activation',
        ]);
        $this->assertFileExists($workspacePath.'/.mcp.json');
        $this->assertFileExists($workspacePath.'/.claude/settings.json');
        $this->assertFileExists($workspacePath.'/AGENTS.md');
        $this->assertFileExists($workspacePath.'/CLAUDE.md');
        $this->assertStringContainsString('atlas_provider_projection_v1', (string) file_get_contents($workspacePath.'/AGENTS.md'));
        $this->assertStringContainsString('Atlas Open Brain Gateway', (string) file_get_contents($workspacePath.'/AGENTS.md'));
        $this->assertSame('provider_bootstrap_ready', $result['provider_bootstrap']['reason']);

        $this->deleteTree($workspacePath);
    }

    public function test_activate_adopts_provider_projection_and_preserves_human_notes(): void
    {
        $workspacePath = sys_get_temp_dir().'/aobg-human-notes-'.Str::random(6);
        @mkdir($workspacePath, 0777, true);
        file_put_contents(
            $workspacePath.'/AGENTS.md',
            "# AGENTS.md — product notes\n\n"
            ."Human rule that must survive activation.\n\n"
            .AtlasProviderProjectionService::AOBG_MANAGED_START."\n"
            ."## Atlas Open Brain Gateway\n"
            ."- Old activation block.\n"
            .AtlasProviderProjectionService::AOBG_MANAGED_END."\n",
        );

        $service = $this->service()->setIndexRunner(function (string $path, string $wsId): array {
            $this->seedCodeSymbol('HumanNotesActivatedSymbol', $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->activate(['workspace' => $workspacePath]);
        $contents = (string) file_get_contents($workspacePath.'/AGENTS.md');
        $projectionStatus = $this->app->make(AtlasProviderProjectionService::class)->status('all', [
            'workspace' => $workspacePath,
        ], [
            'workspace' => $workspacePath,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('provider_bootstrap_ready', $result['provider_bootstrap']['reason']);
        $this->assertSame('adopted', $result['provider_bootstrap']['files']['agents']['projection']['action']);
        $this->assertStringContainsString('atlas_provider_projection_v1', $contents);
        $this->assertStringContainsString('Human rule that must survive activation.', $contents);
        $this->assertSame(1, substr_count($contents, AtlasProviderProjectionService::AOBG_MANAGED_START));
        $this->assertSame('passed', $projectionStatus['status']);
        $this->assertSame(2, $projectionStatus['summary']['ready']);

        $this->deleteTree($workspacePath);
    }

    public function test_activate_preserves_configured_production_safety_profile(): void
    {
        $workspacePath = sys_get_temp_dir().'/aobg-prod-'.Str::random(6);
        @mkdir($workspacePath.'/app', 0777, true);
        $canonicalWorkspacePath = realpath($workspacePath) ?: $workspacePath;
        file_put_contents($workspacePath.'/app/package.json', json_encode([
            'scripts' => ['test' => 'vitest run'],
        ], JSON_PRETTY_PRINT));
        config()->set('atlas_projects.profiles', [[
            'id' => 'safety-product',
            'slug' => 'safety-product',
            'name' => 'Safety Product',
            'kind' => 'product',
            'workspace_path' => $canonicalWorkspacePath,
            'repo_root' => $canonicalWorkspacePath,
            'production_status' => 'production',
            'stack_summary' => 'Configured production workspace',
            'commands' => [],
            'test_commands' => [],
            'build_commands' => [],
            'dev_server_command' => null,
            'critical_areas' => [],
            'docs_status' => 'incomplete',
            'default_risk' => 'high',
            'deployment_notes' => 'Configured production guardrail.',
            'surfaces_enabled' => ['atlas_ai', 'code'],
        ]]);

        $service = $this->service()->setIndexRunner(function (string $path, string $wsId): array {
            $this->seedCodeSymbol('ProductionActivatedSymbol', $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->activate(['cwd' => $workspacePath]);

        $this->assertTrue($result['ok']);
        $this->assertSame('safety-product', $result['workspace_id']);
        $this->assertSame('production', $result['profile']['workspace']['production_status']);
        $this->assertSame('incomplete', $result['profile']['workspace']['docs_status']);
        $this->assertSame('high', $result['profile']['workspace']['default_risk']);
        $this->assertSame(['atlas_ai', 'code'], $result['profile']['workspace']['surfaces_enabled']);
        $this->assertSame(['cd app && npm run test'], $result['profile']['workspace']['test_commands']);

        $this->deleteTree($workspacePath);
    }

    public function test_activate_promotes_discovered_child_workspace_with_parent_policy(): void
    {
        $rootPath = sys_get_temp_dir().'/aobg-parent-'.Str::random(6);
        $childPath = $rootPath.'/blackink-app';
        @mkdir($childPath, 0777, true);
        $canonicalRootPath = realpath($rootPath) ?: $rootPath;
        $canonicalChildPath = realpath($childPath) ?: $childPath;
        file_put_contents($childPath.'/package.json', json_encode([
            'scripts' => ['test' => 'vitest run', 'build' => 'vite build'],
        ], JSON_PRETTY_PRINT));

        $this->insertWorkspaceProfile([
            'slug' => 'blackink',
            'name' => 'Blackink',
            'workspace_path' => $canonicalRootPath,
            'repo_root' => $canonicalRootPath,
            'production_status' => 'production',
            'docs_status' => 'incomplete',
            'default_risk' => 'high',
            'deployment_notes' => 'Parent product policy.',
            'surfaces_enabled' => ['atlas_ai', 'code'],
            'source' => 'operator',
        ]);
        $this->insertWorkspaceProfile([
            'slug' => 'blackink-app',
            'name' => 'Blackink App',
            'workspace_path' => $canonicalChildPath,
            'repo_root' => $canonicalChildPath,
            'production_status' => 'development',
            'stack_summary' => 'code graph ws=legacy-id (499 symbols)',
            'docs_status' => 'unknown',
            'default_risk' => 'medium',
            'surfaces_enabled' => ['atlas_ai', 'cartografia', 'code', 'atencao'],
            'source' => 'atlas-code-graph-index-all',
        ]);

        $service = $this->service()->setIndexRunner(function (string $path, string $wsId): array {
            $this->seedCodeSymbol('ChildActivatedSymbol', $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->activate(['workspace' => $childPath]);

        $this->assertTrue($result['ok']);
        $this->assertSame('blackink-app', $result['workspace_id']);
        $this->assertTrue($result['profile']['inherited_parent_policy']);
        $this->assertSame('production', $result['profile']['workspace']['production_status']);
        $this->assertSame('incomplete', $result['profile']['workspace']['docs_status']);
        $this->assertSame('high', $result['profile']['workspace']['default_risk']);
        $this->assertSame(['atlas_ai', 'code'], $result['profile']['workspace']['surfaces_enabled']);
        $this->assertSame('node', $result['profile']['workspace']['stack_summary']);
        $this->assertSame(['npm run test'], $result['profile']['workspace']['test_commands']);
        $this->assertSame(['npm run build'], $result['profile']['workspace']['build_commands']);
        $this->assertDatabaseHas('atlas_workspace_profiles', [
            'slug' => 'blackink-app',
            'source' => 'aobg_workspace_activation',
            'production_status' => 'production',
            'default_risk' => 'high',
        ]);

        $this->deleteTree($rootPath);
    }

    public function test_activate_infers_static_website_node_test_command_without_package_json(): void
    {
        $workspacePath = sys_get_temp_dir().'/aobg-static-web-'.Str::random(6);
        @mkdir($workspacePath.'/tests', 0777, true);
        file_put_contents($workspacePath.'/index.html', '<!doctype html><title>Static</title>');
        file_put_contents($workspacePath.'/referral-tracking.js', 'export const ok = true;');
        file_put_contents($workspacePath.'/tests/referral-tracking.test.mjs', 'import assert from "node:assert/strict"; assert.equal(1, 1);');

        $service = $this->service()->setIndexRunner(function (string $path, string $wsId): array {
            $this->seedCodeSymbol('StaticActivatedSymbol', $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->activate(['workspace' => $workspacePath]);

        $this->assertTrue($result['ok']);
        $this->assertSame('static-web, javascript', $result['profile']['workspace']['stack_summary']);
        $this->assertSame(['node --test tests/referral-tracking.test.mjs'], $result['profile']['workspace']['test_commands']);

        $this->deleteTree($workspacePath);
    }

    public function test_activate_all_sweeps_registered_existing_workspaces(): void
    {
        config()->set('atlas_projects.profiles', []);

        $one = sys_get_temp_dir().'/aobg-all-one-'.Str::random(6);
        $two = sys_get_temp_dir().'/aobg-all-two-'.Str::random(6);
        @mkdir($one, 0777, true);
        @mkdir($two, 0777, true);
        $canonicalOne = realpath($one) ?: $one;
        $canonicalTwo = realpath($two) ?: $two;

        $this->insertWorkspaceProfile([
            'slug' => 'all-one',
            'name' => 'All One',
            'workspace_path' => $canonicalOne,
            'repo_root' => $canonicalOne,
            'source' => 'operator',
        ]);
        $this->insertWorkspaceProfile([
            'slug' => 'all-two',
            'name' => 'All Two',
            'workspace_path' => $canonicalTwo,
            'repo_root' => $canonicalTwo,
            'source' => 'operator',
        ]);

        $calls = [];
        $service = $this->service()->setIndexRunner(function (string $path, string $wsId) use (&$calls): array {
            $calls[] = ['path' => $path, 'workspace_id' => $wsId];
            $this->seedCodeSymbol('BulkSymbol'.count($calls), $wsId);

            return ['ok' => true, 'reason' => 'indexed', 'exit_code' => 0];
        });

        $result = $service->activateAll();

        $this->assertTrue($result['ok']);
        $this->assertSame('activate_all', $result['action']);
        $this->assertSame(2, $result['summary']['total_profiles']);
        $this->assertSame(2, $result['summary']['activated']);
        $this->assertSame(2, $result['summary']['indexed']);
        $this->assertCount(2, $calls);
        $this->assertSame(['all-one', 'all-two'], array_column($calls, 'workspace_id'));

        $this->deleteTree($one);
        $this->deleteTree($two);
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

    public function test_workspace_map_reports_routes_migrations_tests_and_commands(): void
    {
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $workspacePath = sys_get_temp_dir().'/aobg-map-'.Str::random(6);
        @mkdir($workspacePath, 0777, true);
        $workspaceId = $identity->resolve($workspacePath);

        $this->seedCodeSymbol('GET api/users', $workspaceId, [
            'symbol_type' => 'route',
            'file_path' => 'routes/api.php',
            'signature' => "Route::get('/users', [UserController::class, 'index']);",
            'metadata' => ['http_method' => 'GET', 'uri' => 'api/users', 'controller' => 'UserController', 'method' => 'index'],
        ]);
        $this->seedCodeSymbol('atlas:test', $workspaceId, [
            'symbol_type' => 'cli_command',
            'file_path' => 'app/Console/Commands/TestCommand.php',
            'signature' => 'atlas:test {--json}',
            'metadata' => ['command_signature' => 'atlas:test {--json}'],
        ]);
        $this->seedCodeSymbol('create:users', $workspaceId, [
            'symbol_type' => 'migration_table',
            'file_path' => 'database/migrations/2026_01_01_000000_create_users_table.php',
            'signature' => "Schema::create('users')",
            'metadata' => ['operation' => 'create', 'table' => 'users'],
        ]);
        $this->seedCodeSymbol('UserTest::test_user_can_login', $workspaceId, [
            'symbol_type' => 'test_method',
            'file_path' => 'tests/Feature/UserTest.php',
            'signature' => 'public function test_user_can_login(): void',
            'metadata' => ['method' => 'test_user_can_login'],
        ]);
        $this->seedDocLink($workspaceId, 'module_path');
        $this->seedDocLink($workspaceId, 'symbol_path');
        $this->seedDocLink($workspaceId, 'symbol_path', ['status' => 'archived']);
        $this->seedDocLink('other-workspace', 'symbol_path');

        $map = $this->service()->map(['workspace' => $workspacePath, 'limit' => 5]);
        $mcp = $this->app->make(AtlasOpenBrainMcpService::class);
        $list = $mcp->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/list', 'params' => []]);
        $mcpMap = $mcp->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_workspace_map', 'arguments' => ['workspace' => $workspacePath, 'limit' => 5]],
        ])['result']['structuredContent'];

        $this->assertTrue($map['ok']);
        $this->assertSame('atlas.aobg.workspace_map.v1', $map['map_schema']);
        $this->assertSame($workspaceId, $map['workspace_id']);
        $this->assertSame(4, $map['inventory']['symbol_count']);
        $this->assertSame(1, $map['inventory']['route_count']);
        $this->assertSame(1, $map['inventory']['command_count']);
        $this->assertSame(1, $map['inventory']['migration_count']);
        $this->assertSame(1, $map['inventory']['test_count']);
        $this->assertSame(2, $map['inventory']['doc_link_count']);
        $this->assertSame(['module_path' => 1, 'symbol_path' => 1], $map['inventory']['doc_link_types']);
        $this->assertSame('GET api/users', $map['examples']['routes'][0]['name']);
        $this->assertSame('users', $map['examples']['migrations'][0]['metadata']['table']);
        $this->assertContains('atlas_workspace_map', array_column($list['result']['tools'], 'name'));
        $this->assertSame(1, $mcpMap['inventory']['route_count']);
        $this->assertSame('atlas_workspace_map', $mcpMap['tool']);

        $this->deleteTree($workspacePath);
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

    private function createDocLinksTable(): void
    {
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::create('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 160)->default('atlas-server')->index();
            $table->string('link_type', 80)->index();
            $table->string('status', 32)->default('current')->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    private function createWorkspaceProfilesTable(): void
    {
        Schema::dropIfExists('atlas_workspace_profiles');
        Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('kind', 80)->default('product');
            $table->string('workspace_path', 1000)->nullable();
            $table->string('repo_root', 1000)->nullable();
            $table->string('production_status', 80)->default('development')->index();
            $table->text('stack_summary')->nullable();
            $table->json('commands')->nullable();
            $table->json('test_commands')->nullable();
            $table->json('build_commands')->nullable();
            $table->string('dev_server_command', 1000)->nullable();
            $table->json('critical_areas')->nullable();
            $table->string('docs_status', 80)->default('unknown')->index();
            $table->string('default_risk', 40)->default('medium')->index();
            $table->text('deployment_notes')->nullable();
            $table->json('surfaces_enabled')->nullable();
            $table->string('source', 80)->default('operator')->index();
            $table->string('status', 40)->default('active')->index();
            $table->timestamps();
        });
    }

    private function seedCodeSymbol(string $name, string $workspaceId, array $overrides = []): void
    {
        DB::table('atlas_engineering_code_symbols')->insert(array_merge([
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
        ], array_merge($overrides, [
            'metadata' => json_encode($overrides['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ])));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function seedDocLink(string $workspaceId, string $linkType, array $overrides = []): void
    {
        DB::table('atlas_engineering_doc_links')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'link_type' => $linkType,
            'status' => 'current',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertWorkspaceProfile(array $overrides): void
    {
        $payload = array_merge([
            'id' => (string) Str::uuid(),
            'slug' => 'workspace-'.Str::random(8),
            'name' => 'Workspace',
            'kind' => 'product',
            'workspace_path' => '',
            'repo_root' => '',
            'production_status' => 'development',
            'stack_summary' => '',
            'commands' => [],
            'test_commands' => [],
            'build_commands' => [],
            'dev_server_command' => null,
            'critical_areas' => [],
            'docs_status' => 'unknown',
            'default_risk' => 'medium',
            'deployment_notes' => '',
            'surfaces_enabled' => ['atlas_ai', 'cartografia', 'code', 'atencao'],
            'source' => 'operator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        foreach (['commands', 'test_commands', 'build_commands', 'critical_areas', 'surfaces_enabled'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $payload[$key] = json_encode($payload[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        DB::table('atlas_workspace_profiles')->insert($payload);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($target) && ! is_link($target)) {
                $this->deleteTree($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }
}
