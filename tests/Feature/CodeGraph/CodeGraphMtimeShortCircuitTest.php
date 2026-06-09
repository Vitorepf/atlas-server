<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 C2 — proves the indexer persists mtime + raw file_hash so a warm re-index can
 * skip reading/hashing unchanged files. Without these, the mtime short-circuit can never
 * engage (the bug: persistFileSnapshots/upsert used a fixed column list that dropped them).
 */
final class CodeGraphMtimeShortCircuitTest extends TestCase
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
        (require database_path('migrations/2026_06_09_121000_add_mtime_and_file_hash_to_code_file_snapshots.php'))->up();

        $this->root = sys_get_temp_dir().'/ap815_c2_'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->root.'/src', 0777, true, true);
        File::put($this->root.'/src/Beta.php', "<?php\nnamespace Demo;\nclass Beta { public function go(): void {} }\n");
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

    public function test_index_persists_mtime_and_file_hash_for_short_circuit(): void
    {
        app(EngineeringCodeIntelligenceService::class)->index(['workspace' => $this->root]);

        $snap = DB::table('atlas_engineering_code_file_snapshots')
            ->where('file_path', 'like', '%Beta.php')
            ->first();

        $this->assertNotNull($snap, 'a snapshot row must be written');
        $this->assertNotNull($snap->mtime, 'C2: mtime must be persisted for the short-circuit');
        $this->assertNotNull($snap->file_hash, 'C2: raw file_hash must be persisted');
        $this->assertSame(
            hash('sha256', File::get($this->root.'/src/Beta.php')),
            $snap->file_hash,
            'the stored file_hash must be the raw sha256(content)',
        );
    }
}
