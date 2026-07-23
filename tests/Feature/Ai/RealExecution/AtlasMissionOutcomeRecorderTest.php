<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RealExecution;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\RealExecution\AtlasMissionOutcomeRecorder;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S2.F2 — EXECUTION feeds the BRAIN (hands → cognition, the compounding close).
 *
 * Locks the dedicated {@see AtlasMissionOutcomeRecorder} that records a delivered
 * outcome back INTO the AURG store. It delegates the upsert + cite-or-omit linking
 * to {@see AtlasRealityGraphIngestionService} (single source of truth — no dup
 * upsert logic), so these tests prove the F2-OWNED behaviour:
 *  - a fake delivery result {branch, files, materialization:{...}, delivered:true}
 *    becomes a mission node + evidence node + generated/proves(via the delegate)/
 *    references edges with correct provenance;
 *  - re-recording the same outcome is idempotent (no dup nodes/edges);
 *  - a touched file with NO module node emits NO references edge (cite-or-omit);
 *  - PRIVACY: branch ref + ids/hashes only, never source code / diff;
 *  - FAIL-OPEN: an absent store, a disabled flag, or no ingestion ⇒ recorded=false,
 *    NEVER a throw — a brain outage never breaks a delivery.
 *
 * sqlite-only, cost-free: no provider, no real branch — a plain delivery-shaped
 * array stands in for the orchestrator output (the LLM/materializer step is stubbed).
 */
final class AtlasMissionOutcomeRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.mission.record_outcome_enabled', true);
    }

    public function test_records_mission_and_evidence_nodes_with_generated_edge_from_a_delivery_result(): void
    {
        // A fake (cost-free) orchestrator-shaped delivery result — no provider, no branch git.
        $delivery = $this->fakeDeliveryResult([
            'id' => 'rec-1',
            'request' => 'add a MissionProof helper class',
            'branch' => 'atlas/materialize/rec-1',
            'delivered' => true,
            'provider' => 'fake_for_test',
            'receipt' => str_repeat('a', 64),
            'delivery' => ['files' => ['app/Generated/MissionProof.php']],
            'materialization' => ['measure' => ['passed' => true]],
        ]);

        $out = $this->recorder()->recordFromDelivery($delivery);

        $this->assertTrue($out['recorded']);
        $this->assertSame('mission:mission:rec-1', $out['mission_node']);
        $this->assertSame('mission:evidence:rec-1', $out['evidence_node']);

        $mission = AtlasAurgNode::query()->whereKey('mission:mission:rec-1')->first();
        $this->assertNotNull($mission);
        $this->assertSame('mission', (string) $mission->source_kind);
        $this->assertSame('mission', (string) $mission->kind);
        $this->assertTrue((bool) $mission->provider_safe);
        $this->assertFalse((bool) $mission->sensitive);
        // PRIVACY: branch ref recorded — never a merge; no source code in meta.
        $this->assertSame('atlas/materialize/rec-1', $mission->meta['branch'] ?? null);
        $this->assertTrue((bool) ($mission->meta['never_merged'] ?? false));
        $this->assertArrayNotHasKey('content', (array) $mission->meta);
        $this->assertArrayNotHasKey('diff', (array) $mission->meta);

        $evidence = AtlasAurgNode::query()->whereKey('mission:evidence:rec-1')->first();
        $this->assertNotNull($evidence);
        $this->assertSame('evidence', (string) $evidence->kind);
        // The pass/fail boolean rode the brain as a status — never the test output.
        $this->assertSame('passed', $evidence->meta['status'] ?? null);

        // mission --generated--> evidence (1.0, by construction).
        $gen = AtlasAurgEdge::query()
            ->where('from_node_id', 'mission:mission:rec-1')
            ->where('to_node_id', 'mission:evidence:rec-1')
            ->where('kind', 'generated')
            ->first();
        $this->assertNotNull($gen);
        $this->assertSame(1.0, (float) $gen->confidence);
        $this->assertSame('mission_outcome', (string) $gen->source);
    }

    public function test_references_touched_modules_and_cited_memories_with_correct_provenance(): void
    {
        // Seed the link targets: a module (app/Services/Ai/Reality) + a memory node.
        $this->ingestion()->sync(['code']);
        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'A prior learning',
            'body' => 'the mission builds on this',
            'recorded_at' => now(),
        ]);
        $this->ingestion()->sync(['memory']);
        $memoryNodeId = 'memory:memory_entry:'.$entry->id;
        $this->assertTrue(AtlasAurgNode::query()->whereKey($memoryNodeId)->exists());

        $delivery = $this->fakeDeliveryResult([
            'id' => 'rec-2',
            'request' => 'touch the reality service and cite a memory',
            'branch' => 'atlas/materialize/rec-2',
            'delivered' => true,
            'delivery' => ['files' => [
                'app/Services/Ai/Reality/NewThing.php', // under the module root → 0.7
                'app/Nowhere/Unmatched.php',            // matches no module → omitted
            ]],
        ]);

        $out = $this->recorder()->recordFromDelivery($delivery, [(string) $entry->id, 'nonexistent-id']);
        $this->assertTrue($out['recorded']);

        // mission --references--> module (under-root = 0.7), cites the matched path.
        $modEdge = AtlasAurgEdge::query()
            ->where('from_node_id', 'mission:mission:rec-2')
            ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($modEdge, 'expected mission→module references edge for the touched file');
        $this->assertSame(0.7, (float) $modEdge->confidence);
        $this->assertSame('app/Services/Ai/Reality/NewThing.php', $modEdge->meta['matched_path'] ?? null);

        // mission --references--> memory (1.0), cites the matched id.
        $memEdge = AtlasAurgEdge::query()
            ->where('from_node_id', 'mission:mission:rec-2')
            ->where('to_node_id', $memoryNodeId)
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($memEdge);
        $this->assertSame(1.0, (float) $memEdge->confidence);
        $this->assertSame((string) $entry->id, $memEdge->meta['matched_memory_id'] ?? null);

        // CITE-OR-OMIT: unmatched path + unknown memory id emitted nothing — only
        // the 2 real references edges (module + memory) exist.
        $this->assertSame(
            2,
            (int) AtlasAurgEdge::query()
                ->where('from_node_id', 'mission:mission:rec-2')
                ->where('kind', 'references')
                ->count(),
        );
    }

    public function test_touched_file_with_no_module_node_emits_no_references_edge(): void
    {
        // No code sync ⇒ no module nodes at all.
        $delivery = $this->fakeDeliveryResult([
            'id' => 'rec-omit',
            'request' => 'touch a file the brain does not know',
            'branch' => 'atlas/materialize/rec-omit',
            'delivered' => true,
            'delivery' => ['files' => ['app/Totally/Unknown/Thing.php']],
        ]);

        $out = $this->recorder()->recordFromDelivery($delivery);
        $this->assertTrue($out['recorded']);

        // The mission+evidence nodes exist, but NO references edge (cite-or-omit).
        $this->assertSame(
            0,
            (int) AtlasAurgEdge::query()
                ->where('from_node_id', 'mission:mission:rec-omit')
                ->where('kind', 'references')
                ->count(),
        );
    }

    public function test_recording_the_same_delivery_is_idempotent(): void
    {
        $this->ingestion()->sync(['code']);
        $delivery = $this->fakeDeliveryResult([
            'id' => 'rec-idem',
            'request' => 'idempotent mission',
            'branch' => 'atlas/materialize/rec-idem',
            'delivered' => true,
            'delivery' => ['files' => ['app/Services/Ai/Reality/Idem.php']],
        ]);

        $this->recorder()->recordFromDelivery($delivery);
        $nodesAfterFirst = (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count();
        $edgesAfterFirst = (int) AtlasAurgEdge::query()->where('source', 'mission_outcome')->count();

        // Re-record the identical delivery — must NOT duplicate.
        $this->recorder()->recordFromDelivery($delivery);

        $this->assertSame($nodesAfterFirst, (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count());
        $this->assertSame($edgesAfterFirst, (int) AtlasAurgEdge::query()->where('source', 'mission_outcome')->count());
        $this->assertSame(2, $nodesAfterFirst, 'one mission node + one evidence node');
    }

    public function test_explicit_record_payload_path_also_writes(): void
    {
        // The lower-level record() takes an already-shaped outcome (the seam the
        // ingestion delegate consumes) — proves the recorder is not only the
        // delivery-shaping convenience.
        $out = $this->recorder()->record([
            'id' => 'rec-explicit',
            'request' => 'explicit outcome payload',
            'branch' => 'atlas/materialize/rec-explicit',
            'delivered' => true,
            'measure' => ['ok' => false],
        ]);

        $this->assertTrue($out['recorded']);
        $evidence = AtlasAurgNode::query()->whereKey('mission:evidence:rec-explicit')->first();
        $this->assertNotNull($evidence);
        $this->assertSame('failed', $evidence->meta['status'] ?? null);
    }

    public function test_outcome_from_delivery_is_payload_free_by_construction(): void
    {
        // Even when the delivery result carries extra noise, the projection keeps
        // only ids/hashes/labels/paths/branch + a pass/fail boolean.
        $outcome = $this->recorder()->outcomeFromDelivery([
            'id' => 'rec-pf',
            'request' => 'payload free',
            'branch' => 'atlas/materialize/rec-pf',
            'delivered' => true,
            'provider' => 'codex_cli',
            'receipt' => str_repeat('b', 64),
            'delivery' => ['files' => ['a/b.php', 42, 'c/d.php']], // non-strings dropped
            'materialization' => [
                'measure' => ['passed' => true, 'output_tail' => 'SECRET TEST OUTPUT'],
            ],
        ], ['mem-1', 99]);

        $this->assertSame(['a/b.php', 'c/d.php'], $outcome['files']);
        $this->assertSame(['ok' => true], $outcome['measure']); // output_tail dropped
        $this->assertSame(['mem-1'], $outcome['memory_refs']);  // non-string dropped
        $this->assertSame('atlas/materialize/rec-pf', $outcome['branch']);
        $this->assertSame('codex_cli', $outcome['provider']);
        // The raw measure payload never appears anywhere in the projected outcome.
        $this->assertStringNotContainsString('SECRET TEST OUTPUT', json_encode($outcome));
    }

    public function test_fail_open_when_disabled_when_no_ingestion_and_when_store_missing(): void
    {
        $delivery = $this->fakeDeliveryResult([
            'id' => 'rec-skip', 'request' => 'r', 'delivered' => true,
        ]);

        // Disabled flag ⇒ recorded=false, no throw.
        config()->set('atlas.mission.record_outcome_enabled', false);
        $disabled = $this->recorder()->recordFromDelivery($delivery);
        $this->assertFalse($disabled['recorded']);
        $this->assertSame('disabled', $disabled['reason']);

        // No ingestion injected ⇒ recorded=false, no throw.
        config()->set('atlas.mission.record_outcome_enabled', true);
        $noIngestion = (new AtlasMissionOutcomeRecorder(null))->recordFromDelivery($delivery);
        $this->assertFalse($noIngestion['recorded']);
        $this->assertSame('ingestion_unavailable', $noIngestion['reason']);

        // Absent store ⇒ recorded=false, no throw (the delegate's fail-open boundary).
        Schema::drop('atlas_aurg_edges');
        Schema::drop('atlas_aurg_nodes');
        $missing = $this->recorder()->recordFromDelivery($delivery);
        $this->assertFalse($missing['recorded']);
        $this->assertSame('store_missing', $missing['reason']);
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    /**
     * A cost-free stand-in for the MissionDeliveryOrchestrator deliver() result —
     * a plain array; no provider, no real branch, no git, no DB writes here.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function fakeDeliveryResult(array $overrides): array
    {
        return array_merge([
            'delivered' => true,
            'branch' => null,
            'delivery' => ['files' => []],
            'materialization' => [],
        ], $overrides);
    }

    private function recorder(): AtlasMissionOutcomeRecorder
    {
        return new AtlasMissionOutcomeRecorder($this->ingestion());
    }

    private function ingestion(): AtlasRealityGraphIngestionService
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
