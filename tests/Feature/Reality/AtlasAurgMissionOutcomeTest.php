<?php

declare(strict_types=1);

namespace Tests\Feature\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S2.F1 — CLOSED MISSION LOOP write-back (the hands feed the brain).
 *
 * Locks {@see AtlasRealityGraphIngestionService::recordMissionOutcome()}:
 *  - a delivered outcome accrues a mission node + evidence node + a `generated`
 *    edge between them, all under the 'mission' source_kind (its own prune scope);
 *  - cite-or-omit `references` edges to TOUCHED modules (exact root 1.0 /
 *    under-root 0.7, the same ladder as memory→code) and to cited, existing
 *    memory nodes (1.0) — unknown citations emit nothing;
 *  - PRIVACY: branch ref + ids/hashes only, never source code; never a merge;
 *  - IDEMPOTENT: re-recording the same outcome upserts (no dup nodes/edges);
 *  - HONEST-SKIP: absent store / disabled flag ⇒ recorded=false, no throw.
 *
 * sqlite-only; boots only the needed tables (the proven AURG house pattern).
 */
final class AtlasAurgMissionOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
    }

    public function test_records_mission_and_evidence_nodes_with_a_generated_edge(): void
    {
        $out = $this->service()->recordMissionOutcome([
            'id' => 'mission-1',
            'request' => 'add a MissionProof helper class',
            'branch' => 'atlas/materialize/mission-1',
            'delivered' => true,
            'provider' => 'codex_cli',
            'receipt' => str_repeat('a', 64),
            'files' => ['app/Generated/MissionProof.php'],
            'measure' => ['status' => 'passed'],
        ]);

        $this->assertTrue($out['recorded']);

        $mission = AtlasAurgNode::query()->whereKey('mission:mission:mission-1')->first();
        $this->assertNotNull($mission);
        $this->assertSame('mission', (string) $mission->source_kind);
        $this->assertSame('mission', (string) $mission->kind);
        $this->assertTrue((bool) $mission->provider_safe);
        $this->assertFalse((bool) $mission->sensitive);
        // Branch ref recorded — never a merge; no source code in meta.
        $this->assertSame('atlas/materialize/mission-1', $mission->meta['branch'] ?? null);
        $this->assertTrue((bool) ($mission->meta['never_merged'] ?? false));
        $this->assertArrayNotHasKey('content', (array) $mission->meta);
        $this->assertArrayNotHasKey('diff', (array) $mission->meta);

        $evidence = AtlasAurgNode::query()->whereKey('mission:evidence:mission-1')->first();
        $this->assertNotNull($evidence);
        $this->assertSame('evidence', (string) $evidence->kind);
        $this->assertSame('passed', $evidence->meta['status'] ?? null);

        // mission --generated--> evidence (1.0, by construction).
        $gen = AtlasAurgEdge::query()
            ->where('from_node_id', 'mission:mission:mission-1')
            ->where('to_node_id', 'mission:evidence:mission-1')
            ->where('kind', 'generated')
            ->first();
        $this->assertNotNull($gen);
        $this->assertSame(1.0, (float) $gen->confidence);
        $this->assertSame('mission_outcome', (string) $gen->source);
    }

    public function test_references_touched_modules_cite_or_omit(): void
    {
        // The brain has the link target module (root_path = app/Services/Ai/Reality).
        $this->service()->sync(['code']);

        $out = $this->service()->recordMissionOutcome([
            'id' => 'mission-2',
            'request' => 'touch the reality service',
            'branch' => 'atlas/materialize/mission-2',
            'delivered' => true,
            'files' => [
                'app/Services/Ai/Reality/NewThing.php', // under the module root → 0.7
                'app/Nowhere/Unmatched.php',            // matches no module → omitted
            ],
        ]);
        $this->assertTrue($out['recorded']);

        $edge = AtlasAurgEdge::query()
            ->where('from_node_id', 'mission:mission:mission-2')
            ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($edge, 'expected mission→module references edge for the touched file');
        $this->assertSame(0.7, (float) $edge->confidence);
        $this->assertSame('app/Services/Ai/Reality/NewThing.php', $edge->meta['matched_path'] ?? null);

        // The unmatched path produced NO edge (cite-or-omit).
        $this->assertSame(
            1,
            (int) AtlasAurgEdge::query()->where('from_node_id', 'mission:mission:mission-2')->where('kind', 'references')->count(),
        );
    }

    public function test_references_cited_memories_and_omits_unknown_ids(): void
    {
        // A real memory node in the brain (the cited, existing target).
        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'A prior learning',
            'body' => 'the mission builds on this',
            'recorded_at' => now(),
        ]);
        $this->service()->sync(['memory']);
        $memoryNodeId = 'memory:memory_entry:'.$entry->id;
        $this->assertTrue(AtlasAurgNode::query()->whereKey($memoryNodeId)->exists());

        $out = $this->service()->recordMissionOutcome([
            'id' => 'mission-3',
            'request' => 'cite a memory',
            'branch' => 'atlas/materialize/mission-3',
            'delivered' => true,
            'memory_refs' => [(string) $entry->id, 'nonexistent-memory-id'],
        ]);
        $this->assertTrue($out['recorded']);

        $edge = AtlasAurgEdge::query()
            ->where('from_node_id', 'mission:mission:mission-3')
            ->where('to_node_id', $memoryNodeId)
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($edge);
        $this->assertSame(1.0, (float) $edge->confidence);
        $this->assertSame((string) $entry->id, $edge->meta['matched_memory_id'] ?? null);

        // The unknown id emitted nothing (only the one real memory ref edge).
        $this->assertSame(
            1,
            (int) AtlasAurgEdge::query()
                ->where('from_node_id', 'mission:mission:mission-3')
                ->where('kind', 'references')
                ->count(),
        );
    }

    public function test_recording_the_same_outcome_is_idempotent(): void
    {
        $payload = [
            'id' => 'mission-idem',
            'request' => 'idempotent mission',
            'branch' => 'atlas/materialize/mission-idem',
            'delivered' => true,
            'files' => ['app/Services/Ai/Reality/Idem.php'],
        ];
        $this->service()->sync(['code']);

        $this->service()->recordMissionOutcome($payload);
        $nodesAfterFirst = (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count();
        $edgesAfterFirst = (int) AtlasAurgEdge::query()->where('source', 'mission_outcome')->count();

        // Re-record the identical outcome — must NOT duplicate.
        $this->service()->recordMissionOutcome($payload);

        $this->assertSame($nodesAfterFirst, (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count());
        $this->assertSame($edgesAfterFirst, (int) AtlasAurgEdge::query()->where('source', 'mission_outcome')->count());
        $this->assertSame(2, $nodesAfterFirst, 'one mission node + one evidence node');
    }

    public function test_next_query_can_see_the_recorded_mission_via_a_provider_bound_path(): void
    {
        // Record an outcome that references an existing module → the loop closes:
        // a later query reaches the module FROM the mission node (cross-layer).
        $this->service()->sync(['code']);
        $this->service()->recordMissionOutcome([
            'id' => 'mission-compound',
            'request' => 'a mission that compounds for the next one',
            'branch' => 'atlas/materialize/mission-compound',
            'delivered' => true,
            'files' => ['app/Services/Ai/Reality'], // exact module root → 1.0
        ]);

        $query = new \App\Services\Ai\Reality\AtlasRealityGraphQueryService;
        $result = $query->query('mission that compounds', ['provider_bound' => true]);

        $missionSeen = false;
        foreach ((array) $result['nodes'] as $node) {
            if (($node['id'] ?? null) === 'mission:mission:mission-compound') {
                $missionSeen = true;
                $this->assertTrue((bool) $node['provider_safe']);
            }
        }
        $this->assertTrue($missionSeen, 'the next provider-bound query must SEE the prior mission (compounding)');
    }

    public function test_honest_skip_when_store_missing_and_when_disabled(): void
    {
        // Disabled flag ⇒ recorded=false, no throw.
        config()->set('atlas.aurg.enabled', false);
        $disabled = $this->service()->recordMissionOutcome(['id' => 'm', 'request' => 'r', 'delivered' => true]);
        $this->assertFalse($disabled['recorded']);
        $this->assertSame('aurg_disabled', $disabled['reason']);

        // Absent store ⇒ recorded=false, no throw (fail-open boundary).
        config()->set('atlas.aurg.enabled', true);
        Schema::drop('atlas_aurg_edges');
        Schema::drop('atlas_aurg_nodes');
        $missing = $this->service()->recordMissionOutcome(['id' => 'm', 'request' => 'r', 'delivered' => true]);
        $this->assertFalse($missing['recorded']);
        $this->assertSame('store_missing', $missing['reason']);
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

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
            'migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
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

        // One code module so the mission→code linker has a real target.
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
    }
}
