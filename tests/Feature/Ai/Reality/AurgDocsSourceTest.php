<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AurgDocsSourceTest extends TestCase
{
    private string $docPath = 'docs/engineering-knowledge-base/atlas-aurg-docs-source-test.md';

    private string $memoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        $this->seedSources();
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->docPath));

        parent::tearDown();
    }

    public function test_docs_source_ingests_canonical_engineering_docs_and_links_resolved_refs(): void
    {
        $stats = $this->service()->sync(['docs', 'code', 'memory']);

        $docNodeId = 'doc:doc:'.$this->docPath;
        $docNode = AtlasAurgNode::query()->whereKey($docNodeId)->first();
        $this->assertNotNull($docNode);
        $this->assertSame('doc', $docNode->source_kind);
        $this->assertSame('doc', $docNode->kind);
        $this->assertGreaterThanOrEqual(1, $stats['sources']['docs']['nodes']);
        $this->assertContains('app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php', $docNode->meta['paths'] ?? []);
        $this->assertNotContains('app/Services/Ai/Reality/DoesNotExist.php', $docNode->meta['paths'] ?? []);
        $this->assertContains($this->memoryId, $docNode->meta['memory_refs'] ?? []);

        $this->assertTrue(AtlasAurgEdge::query()
            ->where('from_node_id', $docNodeId)
            ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
            ->where('kind', 'references')
            ->where('source', 'linker_doc_code')
            ->exists(), 'expected doc→code edge from resolved file path');

        $this->assertTrue(AtlasAurgEdge::query()
            ->where('from_node_id', $docNodeId)
            ->where('to_node_id', 'memory:memory_entry:'.$this->memoryId)
            ->where('kind', 'references')
            ->where('source', 'linker_doc_memory')
            ->exists(), 'expected doc→memory edge from resolved memory ref');
    }

    private function service(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
            'migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php',
        ] as $file) {
            $migration = require database_path($file);
            $migration->down();
            $migration->up();
        }

        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    private function seedSources(): void
    {
        $memory = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Docs source memory ref',
            'body' => 'A memory intentionally cited by a canonical doc fixture.',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'status' => 'active',
            'source_type' => 'manual',
            'metadata' => [],
            'tags' => [],
            'recorded_at' => now(),
        ]);
        $this->memoryId = (string) $memory->id;

        DB::table('atlas_engineering_code_modules')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'slug' => 'services-ai-reality',
            'name' => 'Ai Reality',
            'layer' => 'services',
            'root_path' => 'app/Services/Ai/Reality',
            'status' => 'active',
            'source_hash' => hash('sha256', 'services-ai-reality'),
            'tags_json' => '[]',
            'related_docs_json' => '[]',
            'related_tests_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        file_put_contents(base_path($this->docPath), implode("\n", [
            '# AURG docs source test',
            '',
            'Resolved code path: app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php',
            'Unresolved code path: app/Services/Ai/Reality/DoesNotExist.php',
            'Memory ref: '.$this->memoryId,
        ]));
    }
}
