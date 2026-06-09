<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 — index-all: discovers git repos under a root and indexes each as its OWN
 * isolated workspace. Boots only the needed tables (RefreshDatabase is unreliable here).
 */
final class CodeGraphIndexAllCommandTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots', 'ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models',
    ];

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        config(['atlas.code_graph.real_edges' => true]);

        $this->root = sys_get_temp_dir().'/ap815_indexall_'.substr(md5(uniqid('', true)), 0, 8);
        foreach (['alpha-repo', 'beta-repo'] as $r) {
            File::makeDirectory($this->root.'/'.$r.'/.git', 0777, true, true);
            File::makeDirectory($this->root.'/'.$r.'/src', 0777, true, true);
            File::put($this->root.'/'.$r.'/src/Demo.php', "<?php\nnamespace Demo;\nclass Thing { public function run(): void {} }\n");
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            File::deleteDirectory($this->root);
        }
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    public function test_indexes_each_repo_as_isolated_workspace(): void
    {
        $exit = Artisan::call('atlas:code-graph:index-all', ['root' => $this->root, '--json' => true, '--skip-secret-scan' => true]);
        $this->assertSame(0, $exit);

        $out = json_decode(Artisan::output(), true);
        $this->assertSame('ok', $out['status']);
        $this->assertSame(2, $out['repos_total'], 'both fake repos discovered');

        $wids = array_map(static fn (array $w): string => $w['workspace_id'], $out['workspaces']);
        $this->assertCount(2, array_unique($wids), 'each repo got its own distinct workspace_id');

        foreach ($out['workspaces'] as $w) {
            $this->assertSame('ok', $w['status']);
            $this->assertGreaterThan(0, $w['symbols'], 'each repo indexed real symbols from src/');
        }

        $workspacesInDb = DB::table('atlas_engineering_code_symbols')->where('status', 'active')->distinct()->pluck('workspace_id')->all();
        $this->assertCount(2, $workspacesInDb, 'two isolated workspaces coexist in the symbols table');
    }

    public function test_root_with_no_repos_is_safe(): void
    {
        $empty = $this->root.'/empty';
        File::makeDirectory($empty, 0777, true, true);

        $exit = Artisan::call('atlas:code-graph:index-all', ['root' => $empty, '--json' => true]);
        $this->assertSame(0, $exit);
        $out = json_decode(Artisan::output(), true);
        $this->assertSame('no_repos', $out['status']);
    }
}
