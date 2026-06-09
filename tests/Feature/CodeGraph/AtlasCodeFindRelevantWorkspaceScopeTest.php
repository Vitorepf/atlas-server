<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · W-3 — proves the `atlas_code_find_relevant` MCP tool became workspace-scoped.
 *
 * The bug it closes: `codeFindRelevant()` returned the `workspace` only as cosmetic
 * metadata while `EngineeringCodeIntelligenceService::symbols()` queried
 * atlas_engineering_code_symbols across ALL workspaces — so a code-find resolved to
 * 'atlas-server' leaked symbols indexed from other workspaces (e.g. test fixtures), and
 * a query could not be pinned to one workspace. The fix resolves the workspace path to a
 * stable id via {@see CodeGraphWorkspaceIdentity} (mirroring the proven W-1 path used by
 * CodeGraphContextRetriever) and threads it into symbols() as a filter — applied ONLY when
 * the W-1 `workspace_id` column exists.
 *
 * Two proofs:
 *   1. W-1-keyed read-model → identical symbol names in two workspaces resolve to exactly
 *      the in-scope one, in BOTH directions (default→atlas-server, explicit→foreign).
 *   2. pre-W-1 read-model (no workspace_id column) → the filter is skipped and behaviour is
 *      unchanged (global), so the change is default-safe for legacy schemas.
 *
 * Boots only the code-intelligence tables in a helper (the repo's established pattern, see
 * the sibling {@see AtlasCodeGraphContextCommandTest}) — full RefreshDatabase is unreliable
 * here because a core migration is pgsql-only SQL. The tool is exercised through the real
 * JSON-RPC `tools/call` seam (mirrors {@see \Tests\Feature\Ai\CodeGraph\CodeGraphWorkspaceAwareReadersTest}).
 */
final class AtlasCodeFindRelevantWorkspaceScopeTest extends TestCase
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
        $this->dropTables();

        // Emulate the MCP server's default workspace anchoring: AtlasOpenBrainMcpCommand::
        // configureWorkspace() sets atlas.ai.workdir to base_path() before serving, so a tool
        // called with NO explicit workspace resolves to the primary 'atlas-server' (instead of
        // whatever stale/ambient workdir the test environment carries). The tool's workspace()
        // helper reads this config as its no-arg fallback.
        config()->set('atlas.ai.workdir', base_path());
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Boot the code-intelligence read-model. When $w1Keyed is true the W-1 migration adds
     * the `workspace_id` column (the post-keying schema); when false the table stays exactly
     * as it shipped before W-1 (no workspace column).
     */
    private function bootTables(bool $w1Keyed): void
    {
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        if ($w1Keyed) {
            (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        }
    }

    /**
     * Insert one active class symbol. Pass $workspace = null for the pre-W-1 schema (the
     * column does not exist, so the key is omitted). source_hash is derived from the file
     * so two same-named rows never collide on the (symbol_type, source_hash) unique.
     */
    private function insertSymbol(?string $workspace, string $name, string $file): void
    {
        $row = [
            'id' => (string) Str::uuid(),
            'symbol_type' => 'class',
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'status' => 'active',
            'source_hash' => substr(hash('sha256', (string) $workspace.$name.$file), 0, 64),
        ];
        if ($workspace !== null) {
            $row['workspace_id'] = $workspace;
        }

        DB::table('atlas_engineering_code_symbols')->insert($row);
    }

    /**
     * Invoke an MCP tool through the JSON-RPC `tools/call` seam and return its structured
     * content (the tool's own payload).
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);

        return $response['result']['structuredContent'];
    }

    public function test_code_find_is_scoped_to_the_resolved_workspace(): void
    {
        $this->bootTables(true);

        // Resolve a real foreign path EXACTLY as the tool does, then seed the foreign row
        // under THAT resolved id so the test mirrors production identity resolution.
        $identity = app(CodeGraphWorkspaceIdentity::class);
        $foreignPath = sys_get_temp_dir().'/atlas-code-find-foreign-ws-'.uniqid();
        @mkdir($foreignPath, 0777, true);
        $foreignId = $identity->resolve($foreignPath);

        $this->assertNotSame('atlas-server', $foreignId, 'a foreign path must get its own workspace id');

        // The SAME matchable symbol name lives in two workspaces; only the in-scope row
        // may surface, and the file_path proves WHICH workspace's row was returned.
        $this->insertSymbol('atlas-server', 'App\\Shared\\PaymentGateway', 'app/Shared/PaymentGateway.php');
        $this->insertSymbol($foreignId, 'App\\Shared\\PaymentGateway', 'pkg/PaymentGateway.php');

        // Default (no workspace arg) → primary atlas-server: exactly one hit, the atlas-server row.
        $primary = $this->callTool('atlas_code_find_relevant', ['query' => 'PaymentGateway']);
        $this->assertTrue($primary['ok']);
        $this->assertSame('atlas-server', $primary['workspace_id']);
        $this->assertSame(1, $primary['count'], 'only the atlas-server symbol is in scope by default');
        $this->assertSame('app/Shared/PaymentGateway.php', $primary['symbols'][0]['file_path']);

        // Explicit foreign workspace path → it sees ONLY its own row (isolation both ways).
        $foreign = $this->callTool('atlas_code_find_relevant', [
            'query' => 'PaymentGateway',
            'workspace' => $foreignPath,
        ]);
        $this->assertTrue($foreign['ok']);
        $this->assertSame($foreignId, $foreign['workspace_id']);
        $this->assertSame(1, $foreign['count']);
        $this->assertSame('pkg/PaymentGateway.php', $foreign['symbols'][0]['file_path']);

        @rmdir($foreignPath);
    }

    public function test_pre_w1_read_model_is_not_scoped_and_keeps_global_behaviour(): void
    {
        // No W-1 migration → the symbols table has no workspace_id column.
        $this->bootTables(false);
        $this->assertFalse(
            Schema::hasColumn('atlas_engineering_code_symbols', 'workspace_id'),
            'this case must exercise a genuinely pre-W-1 (un-keyed) read-model',
        );

        // Two same-named rows, no workspace keying possible.
        $this->insertSymbol(null, 'App\\Shared\\PaymentGateway', 'app/Shared/PaymentGateway.php');
        $this->insertSymbol(null, 'App\\Shared\\PaymentGateway', 'pkg/PaymentGateway.php');

        // The resolver still resolves an id, but symbols() must skip the filter (no column)
        // so the result is the prior global behaviour — BOTH rows surface, unchanged.
        $out = $this->callTool('atlas_code_find_relevant', ['query' => 'PaymentGateway']);
        $this->assertTrue($out['ok']);
        $this->assertSame('atlas-server', $out['workspace_id']);
        $this->assertSame(2, $out['count'], 'a pre-W-1 table is not workspace-keyed → the filter is skipped, both rows return');
    }
}
