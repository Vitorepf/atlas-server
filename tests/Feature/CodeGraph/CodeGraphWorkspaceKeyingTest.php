<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * AP-815 · W-1 — proves the code-intelligence read-model is workspace-keyed:
 * the real migration chain runs on sqlite, the same slug coexists across two
 * workspaces, and the identity resolver is stable.
 *
 * Boots only the needed tables in setUp (the repo's established pattern — full
 * RefreshDatabase is unreliable here because a core migration is pgsql-only SQL).
 */
final class CodeGraphWorkspaceKeyingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        // Original (pre-W-1) schema, from the real create migrations. The create migration
        // already defines uniq_atlas_eng_code_symbol_source, so the later repair migration
        // (which re-creates it) is intentionally NOT replayed here.
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function runW1Migration(): void
    {
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    public function test_migration_adds_workspace_id_and_backfills_existing_rows(): void
    {
        // A pre-existing module written before W-1.
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => (string) Str::uuid(),
            'slug' => 'legacy-module',
            'name' => 'Legacy',
            'layer' => 'runtime',
            'source_hash' => str_repeat('a', 64),
        ]);

        $this->assertFalse(Schema::hasColumn('atlas_engineering_code_modules', 'workspace_id'));

        $this->runW1Migration();

        foreach ([
            'atlas_engineering_code_modules',
            'atlas_engineering_code_symbols',
            'atlas_engineering_doc_links',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'workspace_id'), "{$table} should be workspace-keyed");
        }

        $this->assertSame(
            'atlas-server',
            DB::table('atlas_engineering_code_modules')->where('slug', 'legacy-module')->value('workspace_id'),
            'existing rows must backfill to the primary workspace',
        );
    }

    public function test_same_slug_coexists_across_workspaces_but_collides_within_one(): void
    {
        $this->runW1Migration();

        $insert = function (string $workspace, string $slug): void {
            DB::table('atlas_engineering_code_modules')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace,
                'slug' => $slug,
                'name' => $slug,
                'layer' => 'runtime',
                'source_hash' => substr(hash('sha256', $workspace.$slug), 0, 64),
            ]);
        };

        // Same slug in two different workspaces must NOT collide.
        $insert('atlas-server', 'app-services-engineering');
        $insert('blackink', 'app-services-engineering');

        $this->assertSame(
            2,
            DB::table('atlas_engineering_code_modules')->where('slug', 'app-services-engineering')->count(),
            'the same slug must live independently in two workspaces',
        );

        // But the same (workspace_id, slug) must still be unique.
        $collided = false;
        try {
            $insert('atlas-server', 'app-services-engineering');
        } catch (Throwable) {
            $collided = true;
        }

        $this->assertTrue($collided, 'a duplicate (workspace_id, slug) must violate the composite unique index');
    }

    public function test_monorepo_sub_workspace_ids(): void
    {
        $identity = app(CodeGraphWorkspaceIdentity::class);

        $this->assertSame('atlas-server::packages-api', $identity->sub('atlas-server', 'packages/api'));
        $this->assertSame('atlas-server::packages-web', $identity->sub('atlas-server', 'packages/web'));
        // Distinct sub-packages key independently…
        $this->assertNotSame($identity->sub('atlas-server', 'packages/api'), $identity->sub('atlas-server', 'packages/web'));
        // …but an empty sub-package degrades to the base workspace id.
        $this->assertSame('atlas-server', $identity->sub('atlas-server', ''));
        // Blank base falls back to the primary default.
        $this->assertSame('atlas-server::libs-core', $identity->sub('', 'libs/core'));
    }

    public function test_identity_resolver_is_stable(): void
    {
        $identity = app(CodeGraphWorkspaceIdentity::class);

        $this->assertSame('atlas-server', $identity->default());
        $this->assertSame('atlas-server', $identity->resolve(base_path()), 'the running app is the primary workspace');
        $this->assertSame('atlas-server', $identity->resolve(null));

        $external = $identity->resolve('/tmp/some-other-project-xyz');
        $this->assertNotSame('atlas-server', $external, 'an external path must get its own id');
        $this->assertSame(
            $external,
            $identity->resolve('/tmp/some-other-project-xyz'),
            'resolution must be deterministic',
        );
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $external);
    }
}
