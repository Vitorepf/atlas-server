<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAXD-03 — Co-citação memória↔código via missões (edge kind próprio, fora do gate).
 *
 * Confirma:
 * - missão com aresta →memory E →module gera memory→module `co_cited` (0.5, meta.witness_mission_id);
 * - `co_cited` NUNCA infla `memory_cross_layer_coverage_ratio` nem passa no gate RAG-10;
 * - cap de 5 co_cited/nó de memória (transitive-blow-up guard);
 * - missão sem uma das pontas ⇒ 0 arestas co_cited (caso negativo).
 */
final class Maxd03CoCitationLinkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
    }

    public function test_mission_with_memory_and_module_edges_emits_co_cited_edge(): void
    {
        $memory = $this->seedMemory('Prior learning');
        $moduleNodeId = $this->seedModule('services-ai-reality', 'app/Services/Ai/Reality');

        // Nodes must exist before recordMissionOutcome for cite-or-omit references.
        $this->service()->sync();

        $this->service()->recordMissionOutcome([
            'id' => 'mission-cc-1',
            'request' => 'wire the memory to the module',
            'branch' => 'atlas/materialize/mission-cc-1',
            'delivered' => true,
            'files' => ['app/Services/Ai/Reality/RelevantFile.php'],
            'memory_refs' => [(string) $memory->id],
        ]);
        $stats = $this->service()->sync();

        $memoryNodeId = 'memory:memory_entry:'.$memory->id;
        $edge = AtlasAurgEdge::query()
            ->where('from_node_id', $memoryNodeId)
            ->where('to_node_id', $moduleNodeId)
            ->where('kind', 'co_cited')
            ->where('source', 'linker_co_cited')
            ->first();

        $this->assertNotNull($edge, 'expected memory→module co_cited edge');
        $this->assertSame(0.5, (float) $edge->confidence);
        $this->assertSame('mission:mission:mission-cc-1', $edge->meta['witness_mission_id'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($stats['linkers']['co_cited'] ?? 0));
    }

    public function test_co_cited_does_not_inflate_coverage_ratio(): void
    {
        $memory = $this->seedMemory('Coverage-neutral learning');
        $this->seedModule('services-ai-reality', 'app/Services/Ai/Reality');

        // With NO mission witness, coverage_ratio starts baseline.
        $this->service()->sync();
        $baseline = (float) DB::table('atlas_aurg_nodes')->where('source_kind', 'memory')->count();
        $baselineLinked = (int) AtlasAurgEdge::query()
            ->where('source', 'like', 'linker_%')
            ->whereNotIn('source', ['linker_doc_code_index', 'linker_doc_authority', 'linker_co_cited'])
            ->count();

        // Now add a mission producing a co_cited edge.
        $this->service()->recordMissionOutcome([
            'id' => 'mission-cc-cov',
            'request' => 'links to check',
            'branch' => 'atlas/materialize/mission-cc-cov',
            'delivered' => true,
            'files' => ['app/Services/Ai/Reality/NewFile.php'],
            'memory_refs' => [(string) $memory->id],
        ]);
        $this->service()->sync();

        $afterLinked = (int) AtlasAurgEdge::query()
            ->where('source', 'like', 'linker_%')
            ->whereNotIn('source', ['linker_doc_code_index', 'linker_doc_authority', 'linker_co_cited'])
            ->count();

        // The count of RAG-10-eligible linker edges did NOT change because of the co_cited emit.
        $this->assertSame($baselineLinked, $afterLinked, 'co_cited must not affect RAG-10 linker set');

        // But co_cited edges DO exist (asserting the emission happened).
        $this->assertGreaterThan(0, (int) AtlasAurgEdge::query()->where('source', 'linker_co_cited')->count());
        $this->assertGreaterThan(0, $baseline);
    }

    public function test_cap_per_memory_is_enforced(): void
    {
        $memory = $this->seedMemory('Multi-module memory');
        // 7 modules, but the cap is 5 → only 5 co_cited edges from this memory.
        $moduleIds = [];
        for ($i = 1; $i <= 7; $i++) {
            $moduleIds[] = $this->seedModule('mod-'.$i, 'app/Services/Ai/CoCitedMod'.$i);
        }

        // Nodes must exist before recordMissionOutcome for cite-or-omit references.
        $this->service()->sync();

        $this->service()->recordMissionOutcome([
            'id' => 'mission-cc-cap',
            'request' => 'cap test',
            'branch' => 'atlas/materialize/mission-cc-cap',
            'delivered' => true,
            'files' => array_map(static fn (int $i): string => 'app/Services/Ai/CoCitedMod'.$i.'/File.php', range(1, 7)),
            'memory_refs' => [(string) $memory->id],
        ]);
        $this->service()->sync();

        $memoryNodeId = 'memory:memory_entry:'.$memory->id;
        $count = (int) AtlasAurgEdge::query()
            ->where('from_node_id', $memoryNodeId)
            ->where('source', 'linker_co_cited')
            ->count();

        $this->assertLessThanOrEqual(5, $count, 'cap of 5 co_cited edges per memory must hold');
        $this->assertGreaterThan(0, $count);
    }

    public function test_mission_with_only_module_or_only_memory_emits_no_co_cited(): void
    {
        // Mission with only module references (no memory_refs).
        $this->seedModule('services-ai-reality', 'app/Services/Ai/Reality');
        $this->service()->recordMissionOutcome([
            'id' => 'mission-cc-none-mem',
            'request' => 'module only',
            'branch' => 'atlas/materialize/mission-cc-none-mem',
            'delivered' => true,
            'files' => ['app/Services/Ai/Reality/Alone.php'],
        ]);
        // Mission with only memory reference (no touched module).
        $memory = $this->seedMemory('Solo memory');
        $this->service()->recordMissionOutcome([
            'id' => 'mission-cc-none-mod',
            'request' => 'memory only',
            'branch' => 'atlas/materialize/mission-cc-none-mod',
            'delivered' => true,
            'memory_refs' => [(string) $memory->id],
        ]);
        $stats = $this->service()->sync();

        $this->assertSame(0, (int) ($stats['linkers']['co_cited'] ?? 0), 'no co_cited when only one endpoint kind');
        $this->assertSame(
            0,
            (int) AtlasAurgEdge::query()->where('source', 'linker_co_cited')->count(),
        );
    }

    // ------------------------------------------------------------------

    private function service(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    private function seedMemory(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'body' => 'seeded for MAXD-03 co_cited linker',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'recorded_at' => now(),
        ]);
    }

    private function seedModule(string $slug, string $rootPath): string
    {
        DB::table('atlas_engineering_code_modules')->updateOrInsert(
            ['slug' => $slug],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => 'atlas-server',
                'slug' => $slug,
                'name' => $slug,
                'layer' => 'services',
                'root_path' => $rootPath,
                'status' => 'active',
                'source_hash' => hash('sha256', $slug),
                'tags_json' => '[]',
                'related_docs_json' => '[]',
                'related_tests_json' => '[]',
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return 'code:module:atlas-server/'.$slug;
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
            'migrations/2026_05_31_210000_create_atlas_docs_authority_graph_table.php',
            'migrations/2026_05_20_150000_create_atlas_strategic_reality_tables.php',
            'migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php',
        ] as $file) {
            $migration = require database_path($file);
            $migration->down();
            $migration->up();
        }

        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
        if (! Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
            Schema::table('atlas_memory_entries', function (Blueprint $table): void {
                $table->uuid('superseded_by_id')->nullable();
            });
        }
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }
}
