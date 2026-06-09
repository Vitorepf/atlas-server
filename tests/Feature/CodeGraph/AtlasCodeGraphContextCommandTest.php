<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · I-2 — proves `atlas:ctx` is a working, fail-safe keyword context reader:
 * a seeded symbol is found and packed (with its 'sym:' id) under the budget; a
 * non-matching query and an empty query both yield an empty pack at exit 0; the pack
 * is workspace-scoped (a foreign workspace's symbol never leaks); inactive symbols are
 * invisible; and a tiny budget truncates rather than over-packs — all without ever
 * erroring out for the caller.
 *
 * The --json path is driven via Artisan::call() + Artisan::output() (the repo's
 * established command-output pattern, e.g. AtlasCliCompletionCommandTest) which both
 * asserts the exit code and captures the printed pack; the table path is exercised via
 * the fluent $this->artisan(...) expectation API.
 *
 * Boots only the needed code-intelligence tables in setUp (the repo's established
 * pattern — full RefreshDatabase is unreliable here because a core migration is
 * pgsql-only SQL).
 */
final class AtlasCodeGraphContextCommandTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * Insert an active symbol into the W-1-keyed read-model.
     */
    private function symbol(string $workspace, string $type, string $name, string $file, ?string $signature = null): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'signature' => $signature,
            'status' => 'active',
            'source_hash' => substr(hash('sha256', $workspace.$name.$file), 0, 64),
        ]);
    }

    /**
     * Run `atlas:ctx --json`, assert exit 0, and return the decoded pack envelope plus
     * the raw output for substring assertions.
     *
     * @param  array<string,mixed>  $params
     * @return array{exit:int, raw:string, json:array<string,mixed>}
     */
    private function callCtx(array $params): array
    {
        $params['--json'] = true;
        $exit = Artisan::call('atlas:ctx', $params);
        $raw = Artisan::output();

        $this->assertSame(0, $exit, 'atlas:ctx must always exit 0 (best-effort recall, never a gate)');

        $decoded = json_decode(trim($raw), true);
        $this->assertIsArray($decoded, 'the --json pack must be valid JSON');

        return ['exit' => $exit, 'raw' => $raw, 'json' => $decoded];
    }

    public function test_happy_path_finds_and_packs_a_matching_symbol(): void
    {
        $this->symbol('atlas-server', 'class', 'App\\Services\\WorkspaceIdentityResolver', 'app/Services/WorkspaceIdentityResolver.php', 'class WorkspaceIdentityResolver');
        $this->symbol('atlas-server', 'class', 'App\\Services\\Unrelated', 'app/Services/Unrelated.php');

        $result = $this->callCtx([
            'query' => 'WorkspaceIdentity',
            '--budget' => 4000,
        ]);
        $out = $result['json'];

        $this->assertSame('atlas-server', $out['workspace_id']);
        $this->assertGreaterThanOrEqual(1, $out['included_count'], 'at least the matching symbol must be in the pack');

        // The matching symbol id is present in the raw JSON output (backslashes appear
        // JSON-escaped, so compare against the JSON-encoded form of the id)...
        $matchId = 'sym:App\\Services\\WorkspaceIdentityResolver';
        $unrelatedId = 'sym:App\\Services\\Unrelated';
        $this->assertStringContainsString(trim(json_encode($matchId), '"'), $result['raw']);
        // ...and the unrelated one is not (it never matched the keyword).
        $this->assertStringNotContainsString(trim(json_encode($unrelatedId), '"'), $result['raw']);

        // The decoded pack (backslashes already unescaped by json_decode) carries the id.
        $ids = array_map(static fn (array $n): string => (string) ($n['id'] ?? ''), $out['pack']['included']);
        $this->assertContains($matchId, $ids);
        // Token cost is honestly accounted (signature length / 4, floored at 1).
        $this->assertGreaterThanOrEqual(1, (int) $out['estimated_tokens']);
        $this->assertLessThanOrEqual((int) $out['budget'], (int) $out['estimated_tokens']);
    }

    public function test_non_matching_query_returns_an_empty_pack_at_exit_zero(): void
    {
        $this->symbol('atlas-server', 'class', 'App\\Services\\Alpha', 'app/Services/Alpha.php');

        $out = $this->callCtx(['query' => 'zzzznevermatchesanythingzzzz'])['json'];

        $this->assertSame(0, $out['included_count'], 'no symbol matches → empty pack');
        $this->assertSame([], $out['pack']['included']);
        $this->assertFalse($out['truncated'], 'an empty pack is not truncated — nothing was excluded');
    }

    public function test_empty_query_short_circuits_to_an_empty_pack(): void
    {
        $this->symbol('atlas-server', 'class', 'App\\Services\\Alpha', 'app/Services/Alpha.php');

        // Only too-short / blank tokens → no usable terms → no DB scan, empty pack.
        $out = $this->callCtx(['query' => '   a  ! '])['json'];

        $this->assertSame([], $out['terms'], 'a one-char token is below the minimum term length');
        $this->assertSame(0, $out['included_count']);
    }

    public function test_pack_is_scoped_to_the_resolved_workspace(): void
    {
        // The --workspace option is resolved through CodeGraphWorkspaceIdentity exactly
        // as the command does: a real foreign path resolves to a stable, distinct id.
        // Seed the foreign row under THAT resolved id so the test mirrors production.
        $identity = app(CodeGraphWorkspaceIdentity::class);
        $foreignPath = sys_get_temp_dir().'/atlas-ctx-foreign-ws-'.uniqid();
        @mkdir($foreignPath, 0777, true);
        $foreignId = $identity->resolve($foreignPath);

        $this->assertNotSame('atlas-server', $foreignId, 'a foreign path must get its own workspace id');

        // Same matchable name in two workspaces; only the in-scope one must surface.
        $this->symbol('atlas-server', 'class', 'App\\Shared\\PaymentGateway', 'app/Shared/PaymentGateway.php');
        $this->symbol($foreignId, 'class', 'App\\Shared\\PaymentGateway', 'pkg/PaymentGateway.php');

        // Default workspace = atlas-server: one hit, the foreign row is invisible.
        $primary = $this->callCtx(['query' => 'PaymentGateway'])['json'];
        $this->assertSame('atlas-server', $primary['workspace_id']);
        $this->assertSame(1, $primary['included_count'], 'only the atlas-server symbol is in scope by default');

        // Explicit foreign workspace path: it sees its own single row (isolation both ways).
        $foreign = $this->callCtx(['query' => 'PaymentGateway', '--workspace' => $foreignPath])['json'];
        $this->assertSame($foreignId, $foreign['workspace_id']);
        $this->assertSame(1, $foreign['included_count']);

        @rmdir($foreignPath);
    }

    public function test_inactive_symbols_are_excluded(): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'symbol_type' => 'class',
            'symbol_name' => 'App\\Archived\\DeprecatedRanker',
            'file_path' => 'app/Archived/DeprecatedRanker.php',
            'language' => 'php',
            'status' => 'archived', // not active → must not appear
            'source_hash' => str_repeat('d', 64),
        ]);

        $out = $this->callCtx(['query' => 'DeprecatedRanker'])['json'];

        $this->assertSame(0, $out['included_count'], 'archived symbols are not retrievable context');
    }

    public function test_tiny_budget_truncates_rather_than_overpacking(): void
    {
        // Several matches, but a 1-token budget can fit none → empty + truncated.
        $this->symbol('atlas-server', 'class', 'App\\Cluster\\NodeOne', 'app/Cluster/NodeOne.php', 'class NodeOne extends Base implements Contract');
        $this->symbol('atlas-server', 'class', 'App\\Cluster\\NodeTwo', 'app/Cluster/NodeTwo.php', 'class NodeTwo extends Base implements Contract');

        $out = $this->callCtx(['query' => 'Cluster', '--budget' => 1])['json'];

        $this->assertSame(1, $out['budget']);
        $this->assertSame(0, $out['included_count'], 'no candidate fits a 1-token budget');
        $this->assertLessThanOrEqual(1, (int) $out['estimated_tokens']);
        $this->assertTrue($out['truncated'], 'candidates existed but were excluded by the budget');
    }

    public function test_table_output_runs_clean_without_json(): void
    {
        $this->symbol('atlas-server', 'class', 'App\\Services\\WidgetFactory', 'app/Services/WidgetFactory.php', 'class WidgetFactory');

        $this->artisan('atlas:ctx', ['query' => 'WidgetFactory'])
            ->assertExitCode(0)
            ->expectsOutputToContain('included=1');
    }
}
