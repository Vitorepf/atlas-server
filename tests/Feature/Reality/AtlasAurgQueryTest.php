<?php

declare(strict_types=1);

namespace Tests\Feature\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AURG Phase-2 / F2 — brain query with provenance (Salto 1 "AURG vivo").
 *
 * Locks the F2 contract over a CRAFTED brain fixture (nodes/edges inserted
 * directly — the query layer is read-only over the F1 store):
 *  - cross-layer chains come back as paths with full provenance
 *    (memory→code via references; evidence→memory via proves);
 *  - depth (hard cap 3) and node/edge caps are respected and reported;
 *  - provider_bound structurally excludes non-safe nodes, sensitive domains
 *    AND everything reachable only through them (BFS never crosses them);
 *  - lexical seeding is per-term (K2) and deterministic; semantic seeding is
 *    HONESTLY empty on sqlite (no fake semantic seeds);
 *  - ranking falls back honestly (unranked_* + insertion order + NO score
 *    fields) when the Python runtime is absent/disabled — never fabricated;
 *  - the MCP tool atlas_aurg_query FORCES provider_bound=true.
 *
 * Fixture topology (M1 is the lexical seed for "embedding decision"):
 *
 *   E1(evidence) -proves-> M1(memory) -references-> C1(code module) -belongs_to-> W(workspace) -belongs_to-> Deng(domain)
 *   M1 -belongs_to-> Dfin(domain, SENSITIVE) -references-> S1(strategic, safe but only reachable through Dfin)
 *   M2(memory, NOT provider-safe) -references-> C1
 */
final class AtlasAurgQueryTest extends TestCase
{
    private const M1 = 'memory:memory_entry:mem-1';

    private const M2 = 'memory:memory_entry:mem-2';

    private const C1 = 'code:module:atlas-server/services-ai-memory';

    private const W = 'code:workspace:atlas-server';

    private const E1 = 'evidence:evidence:ev-1';

    private const DENG = 'domain:domain:engineering';

    private const DFIN = 'domain:domain:finance';

    private const S1 = 'strategic:mission:strat-1';

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->down();
        $migration->up();

        config()->set('atlas.aurg.enabled', true);

        $this->seedBrain();
    }

    public function test_query_returns_cross_layer_paths_with_full_provenance(): void
    {
        $result = $this->service()->query('embedding decision');

        // Lexical seed: M1 only (both terms hit its label), via the K2 per-term path.
        $this->assertCount(1, $result['seeds']);
        $this->assertSame(self::M1, $result['seeds'][0]['node_id']);
        $this->assertSame(AtlasRealityGraphQueryService::SEED_VIA_LEXICAL, $result['seeds'][0]['via']);
        $this->assertEqualsCanonicalizing(['embedding', 'decision'], $result['seeds'][0]['matched_terms']);

        // Default depth 2 reaches all 7 fixture nodes (S1/W/M2 at depth 2).
        $ids = array_column($result['nodes'], 'id');
        $this->assertEqualsCanonicalizing(
            [self::M1, self::M2, self::C1, self::W, self::E1, self::DFIN, self::S1],
            $ids,
        );

        // Provenance on EVERY node and edge.
        foreach ($result['nodes'] as $node) {
            $this->assertNotSame('', (string) $node['source_kind']);
            $this->assertNotSame('', (string) $node['source_id']);
            $this->assertNotSame('', (string) $node['content_hash']);
        }
        foreach ($result['edges'] as $edge) {
            $this->assertNotSame('', (string) $edge['kind']);
            $this->assertNotSame('', (string) $edge['source']);
            $this->assertContains(round((float) $edge['confidence'], 3), [0.7, 1.0]);
        }

        $paths = collect($result['paths'])->keyBy('target');

        // memory→code chain via 'references' (forward hop, exact confidence).
        $codePath = $paths->get(self::C1);
        $this->assertNotNull($codePath);
        $this->assertSame([self::M1, self::C1], $codePath['nodes']);
        $this->assertSame('references', $codePath['hops'][0]['edge_kind']);
        $this->assertSame('linker_memory_code', $codePath['hops'][0]['edge_source']);
        $this->assertSame(1.0, $codePath['hops'][0]['confidence']);
        $this->assertSame('forward', $codePath['hops'][0]['direction']);
        $this->assertTrue($codePath['cross_layer']);

        // evidence→memory chain via 'proves' (stored E1→M1, traversed in reverse).
        $evidencePath = $paths->get(self::E1);
        $this->assertNotNull($evidencePath);
        $this->assertSame([self::M1, self::E1], $evidencePath['nodes']);
        $this->assertSame('proves', $evidencePath['hops'][0]['edge_kind']);
        $this->assertSame('reverse', $evidencePath['hops'][0]['direction']);
        $this->assertTrue($evidencePath['cross_layer']);

        // Two-hop cross-layer chain memory→code→workspace.
        $workspacePath = $paths->get(self::W);
        $this->assertNotNull($workspacePath);
        $this->assertSame([self::M1, self::C1, self::W], $workspacePath['nodes']);
        $this->assertSame(2, $workspacePath['depth']);

        // Small result is HONESTLY unranked (insertion order, seed first, no scores).
        $this->assertSame(AtlasRealityGraphQueryService::RANKING_BELOW_THRESHOLD, $result['ranking']);
        $this->assertSame(self::M1, $result['nodes'][0]['id']);
        foreach ($result['nodes'] as $node) {
            $this->assertArrayNotHasKey('rank', $node);
        }

        $this->assertSame($result['counts']['paths'], $result['counts']['cross_layer_paths']);
        $this->assertGreaterThanOrEqual(6, $result['counts']['cross_layer_paths']);
    }

    public function test_depth_and_node_edge_caps_are_respected_and_reported(): void
    {
        $service = $this->service();

        // depth 1: only direct neighbors of the seed.
        $depth1 = $service->query('embedding decision', ['depth' => 1]);
        $this->assertEqualsCanonicalizing(
            [self::M1, self::E1, self::C1, self::DFIN],
            array_column($depth1['nodes'], 'id'),
        );

        // Requested depth above the HARD cap is clamped and flagged.
        $clamped = $service->query('embedding decision', ['depth' => 9]);
        $this->assertSame(3, $clamped['depth']);
        $this->assertTrue($clamped['caps_hit']['depth_clamped']);

        // Node cap: seed + 1 neighbor only, flagged.
        $nodeCapped = $service->query('embedding decision', ['max_nodes' => 2]);
        $this->assertCount(2, $nodeCapped['nodes']);
        $this->assertTrue($nodeCapped['caps_hit']['nodes']);

        // Edge cap: bounded edge list, flagged (paths keep their hop provenance).
        $edgeCapped = $service->query('embedding decision', ['max_edges' => 1]);
        $this->assertCount(1, $edgeCapped['edges']);
        $this->assertTrue($edgeCapped['caps_hit']['edges']);
    }

    public function test_provider_bound_excludes_unsafe_and_sensitive_reachable_only_through_them(): void
    {
        $result = $this->service()->query('embedding decision', ['provider_bound' => true, 'depth' => 3]);

        $ids = array_column($result['nodes'], 'id');

        // Excluded: M2 (not provider-safe), Dfin (sensitive domain) and S1 —
        // safe itself but reachable ONLY through the sensitive domain.
        $this->assertNotContains(self::M2, $ids);
        $this->assertNotContains(self::DFIN, $ids);
        $this->assertNotContains(self::S1, $ids);

        // The safe cross-layer spine survives (Deng needs depth 3).
        $this->assertEqualsCanonicalizing(
            [self::M1, self::E1, self::C1, self::W, self::DENG],
            $ids,
        );
        foreach ($result['nodes'] as $node) {
            $this->assertTrue($node['provider_safe']);
            $this->assertFalse($node['sensitive']);
        }

        // A query that would seed ONLY on a non-safe node yields nothing.
        $unsafeSeedQuery = $this->service()->query('vault rotation', ['provider_bound' => true]);
        $this->assertSame([], $unsafeSeedQuery['seeds']);
        $this->assertSame([], $unsafeSeedQuery['nodes']);
    }

    public function test_provider_bound_seeds_skip_generic_mission_outcome_noise(): void
    {
        $noisyEvidence = 'mission:evidence:generic-provider-noise';
        $noisyMission = 'mission:mission:generic-provider-noise';
        $noisyModule = 'code:module:atlas-server/provider_noise';
        $meaningfulMission = 'mission:mission:aobg-provider-context';

        foreach ([
            [
                'id' => $noisyEvidence,
                'kind' => 'evidence',
                'source_kind' => 'mission',
                'source_id' => 'generic-provider-noise',
                'label' => 'mission_outcome',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['status' => 'blocked', 'summary' => 'aobg mission provider-bound reality_graph'],
            ],
            [
                'id' => $noisyMission,
                'kind' => 'mission',
                'source_kind' => 'mission',
                'source_id' => 'generic-provider-noise',
                'label' => '[Request interrupted by user for tool use]',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['touched_paths' => ['app/Services/Ai/Reality/Noise.php']],
            ],
            [
                'id' => $noisyModule,
                'kind' => 'module',
                'source_kind' => 'code',
                'source_id' => 'atlas-server/provider_noise',
                'label' => 'Provider Noise',
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['slug' => 'provider_noise', 'root_path' => 'app/Services/Ai/Noise'],
            ],
            [
                'id' => $meaningfulMission,
                'kind' => 'mission',
                'source_kind' => 'mission',
                'source_id' => 'aobg-provider-context',
                'label' => 'AOBG provider-bound reality_graph context mission',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['touched_paths' => ['app/Services/Ai/Reality/AtlasRealityGraphQueryService.php']],
            ],
        ] as $node) {
            AtlasAurgNode::query()->create($node + ['content_hash' => hash('sha256', $node['id'])]);
        }

        foreach ([
            [$noisyEvidence, $noisyMission, 'generated', 'mission_outcome'],
            [$noisyMission, $noisyModule, 'references', 'mission_outcome'],
            [$meaningfulMission, self::C1, 'references', 'mission_outcome'],
        ] as [$from, $to, $kind, $source]) {
            AtlasAurgEdge::query()->create([
                'from_node_id' => $from,
                'to_node_id' => $to,
                'kind' => $kind,
                'source' => $source,
                'confidence' => 0.7,
                'meta' => [],
            ]);
        }

        $result = $this->service()->query('aobg provider-bound reality_graph mission context', [
            'provider_bound' => true,
            'depth' => 2,
        ]);

        $this->assertContains('reality', $result['terms']);
        $this->assertContains('graph', $result['terms']);
        $this->assertContains('provider', $result['terms']);
        $this->assertContains('bound', $result['terms']);

        $seedIds = array_column($result['seeds'], 'node_id');
        $this->assertNotContains($noisyEvidence, $seedIds);
        $this->assertNotContains($noisyMission, $seedIds);
        $this->assertContains($meaningfulMission, $seedIds);

        $pathSeeds = array_column($result['paths'], 'seed');
        $pathTargets = array_column($result['paths'], 'target');
        $this->assertNotContains($noisyEvidence, $pathSeeds);
        $this->assertNotContains($noisyModule, $pathTargets);
        $this->assertContains(self::C1, $pathTargets);
    }

    public function test_lexical_seeding_is_per_term_deterministic_and_capped(): void
    {
        // Multi-term: each term recalls a DIFFERENT layer's node (K2 OR-across-terms).
        $result = $this->service()->query('memoria test_run');

        $seedIds = array_column($result['seeds'], 'node_id');
        $this->assertEqualsCanonicalizing([self::M1, self::E1], $seedIds);
        foreach ($result['seeds'] as $seed) {
            $this->assertSame(AtlasRealityGraphQueryService::SEED_VIA_LEXICAL, $seed['via']);
            $this->assertNotEmpty($seed['matched_terms']);
        }

        // Seed cap: equal matched-count ties break by node id ASC (E1 < M1),
        // and the overflow is flagged.
        $capped = $this->service()->query('memoria test_run', ['seed_limit' => 1]);
        $this->assertCount(1, $capped['seeds']);
        $this->assertSame(self::E1, $capped['seeds'][0]['node_id']);
        $this->assertTrue($capped['caps_hit']['seeds']);
    }

    public function test_semantic_seeding_is_honestly_empty_on_sqlite_and_nonsense_finds_nothing(): void
    {
        // sqlite: AtlasMemoryVectorSearchService::available() is false → no
        // semantic seeds, no fake substitutes, no exception.
        $result = $this->service()->query('zzqqx wwyyk');

        $this->assertSame([], $result['seeds']);
        $this->assertSame([], $result['nodes']);
        $this->assertSame([], $result['paths']);
        $this->assertSame(AtlasRealityGraphQueryService::RANKING_BELOW_THRESHOLD, $result['ranking']);
    }

    public function test_ranking_falls_back_honestly_without_python_runtime(): void
    {
        $this->growBrainPastRankThreshold();

        // (a) ranking kill-switch: honest 'unranked_disabled', insertion order
        // (seed first), and NO score fields anywhere.
        config()->set('atlas.aurg.query_rank_enabled', false);
        $disabled = $this->service()->query('embedding decision');
        $this->assertGreaterThan(12, count($disabled['nodes']));
        $this->assertSame(AtlasRealityGraphQueryService::RANKING_DISABLED, $disabled['ranking']);
        $this->assertSame(self::M1, $disabled['nodes'][0]['id']);
        foreach ($disabled['nodes'] as $node) {
            $this->assertArrayNotHasKey('rank', $node);
        }

        // (b) runtime venv absent: honest 'unranked_runtime_absent', same rules.
        config()->set('atlas.aurg.query_rank_enabled', true);
        File::partialMock()->shouldReceive('exists')->andReturnFalse();
        $absent = $this->service()->query('embedding decision');
        $this->assertSame(AtlasRealityGraphQueryService::RANKING_RUNTIME_ABSENT, $absent['ranking']);
        $this->assertSame(self::M1, $absent['nodes'][0]['id']);
        foreach ($absent['nodes'] as $node) {
            $this->assertArrayNotHasKey('rank', $node);
        }
    }

    public function test_command_runs_json_and_validates_input(): void
    {
        $this->artisan('atlas:aurg:query', ['query' => 'embedding decision', '--json' => true])
            ->assertSuccessful();

        $this->artisan('atlas:aurg:query', ['query' => '   '])->assertFailed();

        config()->set('atlas.aurg.enabled', false);
        $this->artisan('atlas:aurg:query', ['query' => 'embedding decision'])->assertSuccessful();
    }

    public function test_mcp_tool_forces_provider_bound_true(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // Listed in the inventory.
        $list = $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $this->assertContains('atlas_aurg_query', array_column($list['result']['tools'], 'name'));

        // provider_bound is forced TRUE on this surface — even though the call
        // does not (and cannot) opt in, sensitive/sensitive-reachable nodes are out.
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_aurg_query', 'arguments' => ['query' => 'embedding decision', 'depth' => 3]],
        ]);
        $structured = $response['result']['structuredContent'];
        $this->assertTrue($structured['ok']);
        $this->assertTrue($structured['provider_bound']);
        $this->assertTrue($structured['result']['provider_bound']);

        $ids = array_column($structured['result']['nodes'], 'id');
        $this->assertNotContains(self::M2, $ids);
        $this->assertNotContains(self::DFIN, $ids);
        $this->assertNotContains(self::S1, $ids);
        $this->assertContains(self::C1, $ids);

        // Input validation + disabled gate.
        $missing = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_aurg_query', 'arguments' => []],
        ]);
        $this->assertSame('query_required', $missing['result']['structuredContent']['error']);

        config()->set('atlas.aurg.enabled', false);
        $disabled = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_aurg_query', 'arguments' => ['query' => 'embedding decision']],
        ]);
        $this->assertSame('aurg_disabled', $disabled['result']['structuredContent']['error']);
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function service(): AtlasRealityGraphQueryService
    {
        return $this->app->make(AtlasRealityGraphQueryService::class);
    }

    private function seedBrain(): void
    {
        $nodes = [
            [
                'id' => self::M1,
                'kind' => 'memory_entry',
                'source_kind' => 'memory',
                'source_id' => 'mem-1',
                'label' => 'Semantic embedding decision for memoria vector search',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['type' => 'decision', 'scope' => 'global', 'privacy_class' => 'normal'],
            ],
            [
                'id' => self::M2,
                'kind' => 'memory_entry',
                'source_kind' => 'memory',
                'source_id' => 'mem-2',
                'label' => '[redacted] vault rotation',
                'provider_safe' => false,
                'sensitive' => true,
                'meta' => ['type' => 'technical_context', 'privacy_class' => 'secret'],
            ],
            [
                'id' => self::C1,
                'kind' => 'module',
                'source_kind' => 'code',
                'source_id' => 'atlas-server/services-ai-memory',
                'label' => 'Ai Memory',
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['slug' => 'services-ai-memory', 'layer' => 'services', 'root_path' => 'app/Services/Ai/Memory'],
            ],
            [
                'id' => self::W,
                'kind' => 'workspace',
                'source_kind' => 'code',
                'source_id' => 'atlas-server',
                'label' => 'atlas-server',
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['module_count' => 1],
            ],
            [
                'id' => self::E1,
                'kind' => 'evidence',
                'source_kind' => 'evidence',
                'source_id' => 'ev-1',
                'label' => 'test_run',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['status' => 'passed', 'target_id' => 'mem-1'],
            ],
            [
                'id' => self::DENG,
                'kind' => 'domain',
                'source_kind' => 'domain',
                'source_id' => 'engineering',
                'label' => 'Engineering',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => [],
            ],
            [
                'id' => self::DFIN,
                'kind' => 'domain',
                'source_kind' => 'domain',
                'source_id' => 'finance',
                'label' => 'Finance',
                'provider_safe' => false,
                'sensitive' => true,
                'meta' => [],
            ],
            [
                'id' => self::S1,
                'kind' => 'mission',
                'source_kind' => 'strategic',
                'source_id' => 'strat-1',
                'label' => 'Mission AURG vivo',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['entity_type' => 'mission'],
            ],
        ];
        foreach ($nodes as $node) {
            AtlasAurgNode::query()->create($node + ['content_hash' => hash('sha256', $node['id'])]);
        }

        $edges = [
            [self::E1, self::M1, 'proves', 'linker_evidence', 1.0, ['matched' => 'target_id', 'value' => 'mem-1']],
            [self::M1, self::C1, 'references', 'linker_memory_code', 1.0, ['matched_path' => 'app/Services/Ai/Memory']],
            [self::C1, self::W, 'belongs_to', 'code_ingest', 1.0, ['matched' => 'workspace_id']],
            [self::W, self::DENG, 'belongs_to', 'linker_code_domain', 1.0, ['matched' => 'workspace_is_code']],
            [self::M1, self::DFIN, 'belongs_to', 'linker_memory_domain', 1.0, ['matched_domain' => 'finance']],
            [self::DFIN, self::S1, 'references', 'strategic_ingest', 1.0, ['matched' => 'relationship_row']],
            [self::M2, self::C1, 'references', 'linker_memory_code', 0.7, ['matched_token' => 'memory']],
        ];
        foreach ($edges as [$from, $to, $kind, $source, $confidence, $meta]) {
            AtlasAurgEdge::query()->create([
                'from_node_id' => $from,
                'to_node_id' => $to,
                'kind' => $kind,
                'source' => $source,
                'confidence' => $confidence,
                'meta' => $meta,
            ]);
        }
    }

    /** Star of extra modules around M1 so the result exceeds the rank threshold (12). */
    private function growBrainPastRankThreshold(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $id = sprintf('code:module:atlas-server/x%02d', $i);
            AtlasAurgNode::query()->create([
                'id' => $id,
                'kind' => 'module',
                'source_kind' => 'code',
                'source_id' => sprintf('atlas-server/x%02d', $i),
                'label' => sprintf('Star module x%02d', $i),
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['slug' => sprintf('x%02d', $i)],
                'content_hash' => hash('sha256', $id),
            ]);
            AtlasAurgEdge::query()->create([
                'from_node_id' => self::M1,
                'to_node_id' => $id,
                'kind' => 'references',
                'source' => 'linker_memory_code',
                'confidence' => 0.7,
                'meta' => ['matched_token' => sprintf('x%02d', $i)],
            ]);
        }
    }
}
