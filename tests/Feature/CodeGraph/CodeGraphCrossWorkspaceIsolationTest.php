<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * AP-815 · D4 — REGRESSION LOCK for cross-workspace DESTRUCTIVE-operation isolation.
 *
 * The incident this test locks: indexing/pruning a SECOND project (workspace B) once
 * archived the PRIMARY workspace's (atlas-server) doc-links — 208k rows wiped — because
 * `archiveStaleDocLinks` + `syncDocLinks` were not workspace-scoped. Both are now scoped:
 *
 *   - `archiveStaleDocLinks()` (W-1) constrains the prune to `workspace_id = $this->workspaceId`.
 *   - `syncDocLinks()` (W-5) returns early for any NON-primary workspace, so an external
 *     project never replicates atlas-server's KB doc-links into its own workspace.
 *
 * These tests assert workspace A's row counts are BYTE-IDENTICAL before/after B's pruning
 * index, and that B never receives a copy of A's KB doc-links. They FAIL the instant either
 * destructive path stops being workspace-scoped.
 *
 * Boots only the needed tables in setUp (RefreshDatabase is unreliable for these), COPYING
 * the pattern from CodeGraphIndexAllCommandTest.
 */
final class CodeGraphCrossWorkspaceIsolationTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
        'atlas_engineering_knowledge_items',
    ];

    /** The primary workspace id — workspace A masquerades as the running app's graph. */
    private const WORKSPACE_A = 'atlas-server';

    private string $tmpB = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        (require database_path('migrations/2026_05_02_009000_create_atlas_engineering_knowledge_items_table.php'))->up();
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        config(['atlas.code_graph.real_edges' => true]);
        config(['atlas.code_graph.default_workspace_id' => self::WORKSPACE_A]);

        // A fresh CodeGraphWorkspaceIdentity per test (its per-path cache must not leak the
        // primary->path mapping between tests in a reused worker process).
        $this->app->forgetInstance(CodeGraphWorkspaceIdentity::class);

        // Workspace B: a temp fixture repo with a .git marker + a couple of real .php files
        // under src/ (so discoverFiles picks them up and B's index does real work).
        $this->tmpB = sys_get_temp_dir().'/ap815_d4_wsB_'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->tmpB.'/.git', 0777, true, true);
        File::put(
            $this->tmpB.'/.git/config',
            "[remote \"origin\"]\n\turl = git@github.com:fixture/workspace-b.git\n",
        );
        File::makeDirectory($this->tmpB.'/src', 0777, true, true);
        File::put(
            $this->tmpB.'/src/Alpha.php',
            "<?php\nnamespace FixtureB;\nclass Alpha { public function run(): void {} }\n",
        );
        File::put(
            $this->tmpB.'/src/Beta.php',
            "<?php\nnamespace FixtureB;\nclass Beta { public function go(): int { return 1; } }\n",
        );
    }

    protected function tearDown(): void
    {
        if ($this->tmpB !== '') {
            File::deleteDirectory($this->tmpB);
        }
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    /**
     * The core incident regression: a PRUNING index of workspace B must leave every one of
     * workspace A's counts (modules / active symbols / active doc-links / file snapshots)
     * exactly as they were. If any prune (modules, symbols, file_snapshots, doc_links) ever
     * stops being workspace-scoped, A's count drops and this test fails.
     */
    public function test_indexing_workspace_b_with_prune_does_not_touch_workspace_a(): void
    {
        $this->seedWorkspaceA();

        // B must resolve to its OWN, non-primary workspace id (git-remote slug here).
        $identity = app(CodeGraphWorkspaceIdentity::class);
        $widB = $identity->resolve($this->tmpB);
        $this->assertNotSame(self::WORKSPACE_A, $widB, 'workspace B must resolve to a distinct, non-primary workspace_id');

        $before = $this->workspaceACounts();
        // Sanity: the seed actually planted rows in every table (an empty seed would make the
        // "unchanged" assertion vacuously pass).
        $this->assertGreaterThan(0, $before['modules']);
        $this->assertGreaterThan(0, $before['symbols']);
        $this->assertGreaterThan(0, $before['doc_links']);
        $this->assertGreaterThan(0, $before['file_snapshots']);

        // Index workspace B WITH PRUNE — the destructive run that used to wipe A.
        $result = app(EngineeringCodeIntelligenceService::class)->index([
            'workspace' => $this->tmpB,
            'prune' => true,
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false), 'B index should succeed');
        $this->assertGreaterThan(0, (int) ($result['symbol_count'] ?? 0), 'B should have indexed real symbols from src/');

        // B genuinely wrote its own rows under its own workspace_id (so the prune below ran
        // against a populated table, not a no-op).
        $this->assertGreaterThan(
            0,
            DB::table('atlas_engineering_code_symbols')->where('workspace_id', $widB)->where('status', 'active')->count(),
            'workspace B should own active symbols of its own',
        );

        $after = $this->workspaceACounts();

        $this->assertSame($before['modules'], $after['modules'], 'B prune archived workspace A modules — prune is not workspace-scoped');
        $this->assertSame($before['symbols'], $after['symbols'], 'B prune archived workspace A active symbols — symbol prune is not workspace-scoped');
        $this->assertSame($before['doc_links'], $after['doc_links'], 'B prune archived workspace A active doc-links — THE 208k incident: archiveStaleDocLinks is not workspace-scoped');
        $this->assertSame($before['file_snapshots'], $after['file_snapshots'], 'B prune archived workspace A file snapshots — snapshot prune is not workspace-scoped');

        // Byte-identical, defensively: nothing in A flipped to archived.
        $this->assertSame(0, DB::table('atlas_engineering_doc_links')->where('workspace_id', self::WORKSPACE_A)->where('status', 'archived')->count(), 'no workspace A doc-link should be archived by B');
        $this->assertSame(0, DB::table('atlas_engineering_code_symbols')->where('workspace_id', self::WORKSPACE_A)->where('status', 'archived')->count(), 'no workspace A symbol should be archived by B');
    }

    /**
     * W-5: an external workspace must NOT receive a replicated copy of the primary's KB
     * doc-links. `syncDocLinks` is primary-only, so B's doc_link count stays 0 even though
     * the knowledge-item table is populated with links that match B's modules by path.
     */
    public function test_workspace_b_does_not_receive_a_replicated_copy_of_kb_doc_links(): void
    {
        $this->seedWorkspaceA();

        // A KB item whose related path points at B's source — if syncDocLinks were NOT
        // primary-gated, B's index would mint doc-links for it under B's workspace_id.
        DB::table('atlas_engineering_knowledge_items')->insert($this->knowledgeItemRow('src/Alpha.php'));

        $widB = app(CodeGraphWorkspaceIdentity::class)->resolve($this->tmpB);

        app(EngineeringCodeIntelligenceService::class)->index([
            'workspace' => $this->tmpB,
            'prune' => true,
        ]);

        $this->assertSame(
            0,
            DB::table('atlas_engineering_doc_links')->where('workspace_id', $widB)->count(),
            'workspace B must own ZERO doc-links — syncDocLinks is primary-only (no KB replication into external workspaces)',
        );

        // And A's doc-links are untouched by B's run.
        $this->assertSame(
            $this->activeDocLinkCount(self::WORKSPACE_A),
            DB::table('atlas_engineering_doc_links')->where('workspace_id', self::WORKSPACE_A)->whereNull('archived_at')->where('status', 'current')->count(),
        );
    }

    /**
     * Direct, isolated lock on the INNER scoping of archiveStaleDocLinks(): even when the
     * service is operating as a non-primary workspace, archiving stale doc-links must touch
     * ONLY that workspace's rows. This bites on the W-1 `where('workspace_id', ...)` inside
     * the method itself — independent of the W-5 early-return that guards the public path.
     */
    public function test_archive_stale_doc_links_is_workspace_scoped(): void
    {
        // Workspace A: 5 stale-but-active ('current') doc-links indexed in the past.
        $past = now()->subDay();
        for ($i = 0; $i < 5; $i++) {
            DB::table('atlas_engineering_doc_links')->insert($this->docLinkRow(self::WORKSPACE_A, "docs/a-{$i}.md", $past));
        }
        // Workspace B owns its own current doc-link too.
        DB::table('atlas_engineering_doc_links')->insert($this->docLinkRow('fixture-workspace-b', 'docs/b-0.md', $past));

        $this->assertSame(5, $this->activeDocLinkCount(self::WORKSPACE_A));

        // Drive archiveStaleDocLinks AS workspace B (private state + private method via reflection).
        $service = app(EngineeringCodeIntelligenceService::class);
        $ref = new ReflectionClass($service);

        $widProp = $ref->getProperty('workspaceId');
        $widProp->setAccessible(true);
        $widProp->setValue($service, 'fixture-workspace-b');

        $archive = $ref->getMethod('archiveStaleDocLinks');
        $archive->setAccessible(true);
        $archive->invoke($service, Carbon::instance(now()));

        // A's 5 doc-links survive (B's archive was scoped to B); B's own is archived.
        $this->assertSame(5, $this->activeDocLinkCount(self::WORKSPACE_A), 'archiveStaleDocLinks wiped workspace A rows — its workspace scope was lost (the 208k incident)');
        $this->assertSame(0, $this->activeDocLinkCount('fixture-workspace-b'), 'B should have archived its own stale doc-link');
    }

    // --- seeding helpers ---------------------------------------------------

    /**
     * Plant workspace A = atlas-server with active modules, symbols, file snapshots and KB
     * doc-links. Doc-links + symbols are indexed in the PAST so a non-scoped prune (which
     * archives rows with indexed_at < pruneStartedAt) would catch them — making the leak
     * detectable rather than masked by a future timestamp.
     */
    private function seedWorkspaceA(): void
    {
        $past = now()->subDay();

        $moduleRows = [];
        $symbolRows = [];
        $snapshotRows = [];
        $docLinkRows = [];

        for ($m = 0; $m < 3; $m++) {
            $slug = "atlas-module-{$m}";
            $rootPath = "app/Module{$m}";
            $moduleRows[] = [
                'id' => (string) Str::uuid(),
                'workspace_id' => self::WORKSPACE_A,
                'slug' => $slug,
                'name' => "Atlas Module {$m}",
                'layer' => 'service',
                'root_path' => $rootPath,
                'primary_language' => 'php',
                'status' => 'active',
                'docs_status' => 'documented',
                'source_hash' => hash('sha256', "module-{$m}"),
                'tags_json' => '[]',
                'related_docs_json' => '[]',
                'related_tests_json' => '[]',
                'metadata' => '{}',
                'indexed_at' => $past,
                'archived_at' => null,
                'created_at' => $past,
                'updated_at' => $past,
            ];

            for ($s = 0; $s < 4; $s++) {
                $symbolRows[] = [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => self::WORKSPACE_A,
                    'module_id' => null,
                    'symbol_type' => 'method',
                    'symbol_name' => "doThing{$m}_{$s}",
                    'file_path' => "{$rootPath}/Service{$s}.php",
                    'language' => 'php',
                    'status' => 'active',
                    'docs_status' => 'documented',
                    'source_hash' => hash('sha256', "symbol-{$m}-{$s}"),
                    'related_doc_ids_json' => '[]',
                    'metadata' => '{}',
                    'indexed_at' => $past,
                    'archived_at' => null,
                    'created_at' => $past,
                    'updated_at' => $past,
                ];
            }

            $snapshotRows[] = [
                'id' => (string) Str::uuid(),
                'workspace_id' => self::WORKSPACE_A,
                'file_path' => "{$rootPath}/Service0.php",
                'module_slug' => $slug,
                'language' => 'php',
                'source_hash' => hash('sha256', "snapshot-{$m}"),
                'file_size' => 128,
                'symbols_json' => '[]',
                'relations_json' => '{}',
                'status' => 'active',
                'indexed_at' => $past,
                'archived_at' => null,
                'created_at' => $past,
                'updated_at' => $past,
            ];

            $docLinkRows[] = $this->docLinkRow(self::WORKSPACE_A, "docs/module-{$m}.md", $past, $rootPath);
        }

        DB::table('atlas_engineering_code_modules')->insert($moduleRows);
        DB::table('atlas_engineering_code_symbols')->insert($symbolRows);
        DB::table('atlas_engineering_code_file_snapshots')->insert($snapshotRows);
        DB::table('atlas_engineering_doc_links')->insert($docLinkRows);
    }

    /**
     * @return array<string,string|mixed>
     */
    private function docLinkRow(string $workspaceId, string $canonicalPath, Carbon $indexedAt, ?string $targetPath = null): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'knowledge_item_id' => null,
            'module_id' => null,
            'symbol_id' => null,
            'link_type' => 'module_path',
            'status' => 'current',
            'canonical_path' => $canonicalPath,
            'target_path' => $targetPath ?? 'app/Target',
            'doc_hash' => hash('sha256', $canonicalPath),
            'target_hash' => null,
            'link_hash' => hash('sha256', $workspaceId.'|'.$canonicalPath),
            'metadata' => '{}',
            'indexed_at' => $indexedAt,
            'archived_at' => null,
            'created_at' => $indexedAt,
            'updated_at' => $indexedAt,
        ];
    }

    /**
     * @return array<string,string|mixed>
     */
    private function knowledgeItemRow(string $relatedPath): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid(),
            'slug' => 'fixture-kb-item',
            'title' => 'Fixture KB Item',
            'category' => 'engineering',
            'status' => 'active',
            'priority' => 50,
            'source_type' => 'canonical_doc',
            'canonical_path' => 'docs/engineering-knowledge-base/fixture.md',
            'source_hash' => hash('sha256', 'fixture-source'),
            'content_hash' => hash('sha256', 'fixture-content'),
            'summary' => null,
            'body_excerpt' => null,
            'tags_json' => '[]',
            'related_paths_json' => json_encode([$relatedPath], JSON_THROW_ON_ERROR),
            'capabilities_json' => '[]',
            'decisions_json' => '[]',
            'maintenance_json' => '[]',
            'metadata' => '{}',
            'indexed_at' => $now,
            'last_verified_at' => null,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    // --- assertion helpers -------------------------------------------------

    /**
     * @return array{modules:int,symbols:int,doc_links:int,file_snapshots:int}
     */
    private function workspaceACounts(): array
    {
        return [
            'modules' => DB::table('atlas_engineering_code_modules')
                ->where('workspace_id', self::WORKSPACE_A)->where('status', 'active')->count(),
            'symbols' => DB::table('atlas_engineering_code_symbols')
                ->where('workspace_id', self::WORKSPACE_A)->where('status', 'active')->whereNull('archived_at')->count(),
            'doc_links' => $this->activeDocLinkCount(self::WORKSPACE_A),
            'file_snapshots' => DB::table('atlas_engineering_code_file_snapshots')
                ->where('workspace_id', self::WORKSPACE_A)->where('status', 'active')->count(),
        ];
    }

    private function activeDocLinkCount(string $workspaceId): int
    {
        return DB::table('atlas_engineering_doc_links')
            ->where('workspace_id', $workspaceId)
            ->whereNull('archived_at')
            ->where('status', 'current')
            ->count();
    }
}
