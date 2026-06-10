<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class EngineeringCodeIntelligenceSymbolDriftWorkspaceScopeTest extends TestCase
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
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_symbol_drift_is_scoped_to_current_workspace_when_read_model_is_workspace_keyed(): void
    {
        $this->assertTrue(Schema::hasColumn('atlas_engineering_code_symbols', 'workspace_id'));

        $service = app(EngineeringCodeIntelligenceService::class);
        $reflection = new ReflectionClass($service);
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->default();
        $reflection->getProperty('workspaceId')->setValue($service, $workspaceId);

        $scannedSymbol = [
            'module_slug' => 'app_misc',
            'symbol_type' => 'class',
            'symbol_name' => 'App\\Services\\InScopeContext',
            'file_path' => 'app/Services/InScopeContext.php',
            'line_start' => 7,
            'language' => 'php',
            'source_hash' => hash('sha256', 'in-scope-context-symbol'),
        ];

        $this->insertPersistedSymbol($workspaceId, $scannedSymbol);
        $this->insertPersistedSymbol('foreign-workspace', [
            ...$scannedSymbol,
            'symbol_name' => 'Foreign\\OutOfScopeContext',
            'file_path' => 'foreign/OutOfScopeContext.php',
            'source_hash' => hash('sha256', 'foreign-context-symbol'),
        ]);

        $method = $reflection->getMethod('symbolDrift');
        $payload = $method->invoke($service, [$scannedSymbol], 10);

        $this->assertSame(['added' => 0, 'removed' => 0], $payload['counts']);
        $this->assertSame([], $payload['added']);
        $this->assertSame([], $payload['removed']);
        $this->assertSame([], $payload['by_type']['added']);
        $this->assertSame([], $payload['by_type']['removed']);
    }

    public function test_summary_and_snapshot_cache_are_scoped_to_current_workspace(): void
    {
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->default();
        $foreignWorkspaceId = 'foreign-workspace';

        $this->insertModule($workspaceId, 'app_misc', 'app/InScope.php');
        $this->insertModule($foreignWorkspaceId, 'foreign_misc', 'foreign/OutOfScope.php');
        $this->insertPersistedSymbol($workspaceId, [
            'module_slug' => 'app_misc',
            'symbol_type' => 'class',
            'symbol_name' => 'App\\InScope',
            'file_path' => 'app/InScope.php',
            'line_start' => 1,
            'language' => 'php',
            'source_hash' => hash('sha256', 'in-scope-summary-symbol'),
        ]);
        $this->insertPersistedSymbol($foreignWorkspaceId, [
            'module_slug' => 'foreign_misc',
            'symbol_type' => 'class',
            'symbol_name' => 'Foreign\\OutOfScope',
            'file_path' => 'foreign/OutOfScope.php',
            'line_start' => 1,
            'language' => 'php',
            'source_hash' => hash('sha256', 'foreign-summary-symbol'),
        ]);
        $this->insertDocLink($workspaceId, 'docs/in-scope.md', 'app/InScope.php', 'in-scope-doc-link');
        $this->insertDocLink($foreignWorkspaceId, 'docs/out-of-scope.md', 'foreign/OutOfScope.php', 'foreign-doc-link');
        $this->insertSnapshot($workspaceId, 'app/InScope.php', 'in-scope-snapshot');
        $this->insertSnapshot($foreignWorkspaceId, 'foreign/OutOfScope.php', 'foreign-snapshot');

        $service = app(EngineeringCodeIntelligenceService::class);
        $reflection = new ReflectionClass($service);
        $reflection->getProperty('workspaceId')->setValue($service, $workspaceId);

        $summary = $service->summary();
        $this->assertSame(1, $summary['module_count']);
        $this->assertSame(1, $summary['symbol_count']);
        $this->assertSame(1, $summary['doc_link_count']);
        $this->assertSame(['misc' => 1], $summary['layers']);

        $snapshots = $reflection->getMethod('loadFileSnapshots')->invoke($service, true);
        $this->assertArrayHasKey('app/InScope.php', $snapshots);
        $this->assertArrayNotHasKey('foreign/OutOfScope.php', $snapshots);
    }

    /**
     * @param  array<string,mixed>  $symbol
     */
    private function insertPersistedSymbol(string $workspaceId, array $symbol): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol_type' => (string) $symbol['symbol_type'],
            'symbol_name' => (string) $symbol['symbol_name'],
            'file_path' => (string) $symbol['file_path'],
            'line_start' => $symbol['line_start'] ?? null,
            'language' => $symbol['language'] ?? null,
            'status' => 'active',
            'docs_status' => 'undocumented',
            'source_hash' => (string) $symbol['source_hash'],
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertModule(string $workspaceId, string $slug, string $rootPath): void
    {
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'slug' => $slug,
            'name' => Str::headline($slug),
            'layer' => 'misc',
            'root_path' => $rootPath,
            'primary_language' => 'php',
            'status' => 'active',
            'owner' => 'atlas',
            'docs_status' => 'documented',
            'file_count' => 1,
            'symbol_count' => 1,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'source_hash' => hash('sha256', $workspaceId.'|'.$slug),
            'tags_json' => '[]',
            'related_docs_json' => '[]',
            'related_tests_json' => '[]',
            'metadata' => '{}',
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDocLink(string $workspaceId, string $canonicalPath, string $targetPath, string $hashSeed): void
    {
        DB::table('atlas_engineering_doc_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'link_type' => 'symbol_path',
            'status' => 'current',
            'canonical_path' => $canonicalPath,
            'target_path' => $targetPath,
            'target_hash' => hash('sha256', $targetPath),
            'link_hash' => hash('sha256', $hashSeed),
            'metadata' => '{}',
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSnapshot(string $workspaceId, string $filePath, string $hashSeed): void
    {
        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'file_path' => $filePath,
            'module_slug' => 'app_misc',
            'language' => 'php',
            'source_hash' => hash('sha256', $hashSeed),
            'file_size' => 10,
            'symbols_json' => '[]',
            'relations_json' => '{}',
            'status' => 'active',
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
