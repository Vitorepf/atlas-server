<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Obra\AtlasObraPlanService;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraDecomposer;
use App\Services\Ai\Obra\ObraNodeDraft;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AOBG N3.F1 — the DECOMPOSITION spine (intent → plan-DAG → steps).
 *
 * Locks the planning contract over a sqlite, COST-FREE fixture: the decomposer is
 * stubbed (deterministic or a crafted-DAG fake — ZERO provider spend, exactly the
 * MissionDeliveryOrchestratorTest philosophy), the brain is a crafted-pack stub (no
 * DB, no provider), and the REAL tables are built from the real migration (plain
 * portable SQL, sqlite-safe) so the test exercises the SAME schema dev runs on pgsql.
 *
 * Non-negotiable assertions:
 *  - an intent decomposes into a VALID multi-node DAG, PERSISTED (plan + nodes);
 *  - deps resolve to node ids in the same plan + the topological order is valid
 *    (every dep precedes its dependent);
 *  - a CYCLE is REFUSED (throws, nothing persisted);
 *  - a dangling dependency is REFUSED;
 *  - each node carries brain_refs cited from the N1 pack;
 *  - the max_nodes cap REFUSES an over-cap plan;
 *  - the deterministic decomposer is genuinely cost-free (no provider wiring).
 */
final class AtlasObraPlanServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createObraTables();
        config()->set('atlas.obra.enabled', true);
        config()->set('atlas.obra.max_nodes', 12);
        config()->set('atlas.obra.decompose_provider', ''); // deterministic = cost-free
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');
        parent::tearDown();
    }

    public function test_intent_decomposes_into_a_valid_multi_node_dag_and_persists(): void
    {
        $service = $this->service($this->deterministic());

        $r = $service->decompose('add a Foo service; then wire Foo into Bar; then test the wiring', [
            'workspace' => 'atlas-server',
            'id' => 'multi',
        ]);

        $this->assertSame(AtlasObraPlanService::SCHEMA, $r['schema']);
        $this->assertSame('obra-multi', $r['plan_id']);
        $this->assertSame(AtlasObraPlanService::STATUS_PLANNED, $r['status']);
        $this->assertSame(3, $r['node_count']);
        $this->assertCount(3, $r['nodes']);

        // Persisted: plan header + all nodes are in the real tables.
        $this->assertSame(1, DB::table('atlas_obra_plans')->where('id', 'obra-multi')->count());
        $this->assertSame(3, DB::table('atlas_obra_nodes')->where('plan_id', 'obra-multi')->count());

        // Node ids follow the stable topo scheme; seq is 0..N-1.
        $seqs = array_column($r['nodes'], 'seq');
        $this->assertSame([0, 1, 2], $seqs);
        $this->assertSame('obra-multi:n0', $r['nodes'][0]['id']);
        $this->assertSame('obra-multi:n2', $r['nodes'][2]['id']);

        // The deterministic decomposer chains each step on its predecessor.
        $this->assertSame([], $r['nodes'][0]['depends_on']);
        $this->assertSame(['obra-multi:n0'], $r['nodes'][1]['depends_on']);
        $this->assertSame(['obra-multi:n1'], $r['nodes'][2]['depends_on']);
    }

    public function test_dependencies_resolve_and_topological_order_is_valid(): void
    {
        // A diamond DAG: a → b, a → c, (b,c) → d. The valid topo order must place a
        // first and d last, with b and c after a and before d.
        $fake = $this->fakeDag([
            new ObraNodeDraft(key: 'd', title: 'merge', request: 'merge b and c', dependsOn: ['b', 'c']),
            new ObraNodeDraft(key: 'a', title: 'root', request: 'create base'),
            new ObraNodeDraft(key: 'b', title: 'left', request: 'left branch', dependsOn: ['a']),
            new ObraNodeDraft(key: 'c', title: 'right', request: 'right branch', dependsOn: ['a']),
        ]);

        $r = $this->service($fake)->decompose('build a diamond', ['id' => 'diamond']);

        $this->assertSame(4, $r['node_count']);

        // Build a position map over the persisted topo order, then assert every dep
        // edge points BACKWARD (dep precedes dependent) — the DAG invariant.
        $pos = [];
        foreach ($r['nodes'] as $node) {
            $pos[$node['id']] = $node['seq'];
        }
        foreach ($r['nodes'] as $node) {
            foreach ($node['depends_on'] as $depId) {
                $this->assertArrayHasKey($depId, $pos, 'a dep must resolve to a node id in this plan');
                $this->assertLessThan(
                    $pos[$node['id']],
                    $pos[$depId],
                    "dep {$depId} must precede {$node['id']} in the topological order",
                );
            }
        }

        // 'a' (root) is first; 'd' (sink) is last.
        $first = $r['nodes'][0];
        $last = $r['nodes'][count($r['nodes']) - 1];
        $this->assertSame('create base', $first['request']);
        $this->assertSame('merge b and c', $last['request']);
    }

    public function test_a_cycle_is_refused_and_nothing_is_persisted(): void
    {
        $fake = $this->fakeDag([
            new ObraNodeDraft(key: 'a', title: 'a', request: 'a', dependsOn: ['b']),
            new ObraNodeDraft(key: 'b', title: 'b', request: 'b', dependsOn: ['a']),
        ]);

        try {
            $this->service($fake)->decompose('a cyclic intent', ['id' => 'cyclic']);
            $this->fail('a cyclic plan must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cycle', $e->getMessage());
        }

        // Refused BEFORE persisting — the tables are untouched.
        $this->assertSame(0, DB::table('atlas_obra_plans')->where('id', 'obra-cyclic')->count());
        $this->assertSame(0, DB::table('atlas_obra_nodes')->where('plan_id', 'obra-cyclic')->count());
    }

    public function test_a_dangling_dependency_is_refused(): void
    {
        $fake = $this->fakeDag([
            new ObraNodeDraft(key: 'a', title: 'a', request: 'a'),
            new ObraNodeDraft(key: 'b', title: 'b', request: 'b', dependsOn: ['ghost']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown step "ghost"');

        $this->service($fake)->decompose('a dangling intent', ['id' => 'dangling']);
    }

    public function test_each_node_carries_brain_refs_from_the_n1_pack(): void
    {
        $service = $this->service($this->deterministic(), $this->stubBrain());

        $r = $service->decompose('add Foo; then test Foo', ['id' => 'anchored']);

        foreach ($r['nodes'] as $node) {
            $refs = $node['brain_refs'];
            $this->assertSame(['code_graph', 'reality_graph', 'memory'], $refs['sources_present']);
            $this->assertSame('App\\Services\\Foo', $refs['code'][0]['id']);
            $this->assertSame('prior obra learning', $refs['memory'][0]['title']);
            $this->assertNotEmpty($refs['reality']);
        }

        // The refs were persisted as provider-safe json (read back from the table).
        $row = DB::table('atlas_obra_nodes')->where('plan_id', 'obra-anchored')->orderBy('seq')->first();
        $stored = json_decode((string) $row->brain_refs, true);
        $this->assertSame('App\\Services\\Foo', $stored['code'][0]['id']);
    }

    public function test_brain_outage_fails_open_with_empty_refs_never_breaks_planning(): void
    {
        $service = $this->service($this->deterministic(), $this->throwingBrain());

        $r = $service->decompose('survive a dead brain', ['id' => 'failopen']);

        $this->assertSame(1, $r['node_count']);
        $this->assertSame([], $r['nodes'][0]['brain_refs']['sources_present']);
        $this->assertSame([], $r['nodes'][0]['brain_refs']['code']);
    }

    public function test_max_nodes_cap_refuses_an_over_cap_plan(): void
    {
        config()->set('atlas.obra.max_nodes', 2);

        // The deterministic decomposer yields 3 steps; the cap of 2 must refuse.
        $service = $this->service($this->deterministic());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds max_nodes');

        $service->decompose('step one; step two; step three', ['id' => 'overcap']);
    }

    public function test_empty_intent_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service($this->deterministic())->decompose('   ');
    }

    public function test_redecompose_same_intent_is_idempotent(): void
    {
        $service = $this->service($this->deterministic());

        $service->decompose('alpha; beta', ['id' => 'idem']);
        $service->decompose('alpha; beta', ['id' => 'idem']);

        // One plan row, exactly the fresh node set (no duplicate-flood).
        $this->assertSame(1, DB::table('atlas_obra_plans')->where('id', 'obra-idem')->count());
        $this->assertSame(2, DB::table('atlas_obra_nodes')->where('plan_id', 'obra-idem')->count());
    }

    // ------------------------------------------------------------------
    // fixtures (all cost-free — no provider call anywhere)
    // ------------------------------------------------------------------

    private function service(ObraDecomposer $decomposer, ?AtlasOpenBrainContextPackService $brain = null): AtlasObraPlanService
    {
        return new AtlasObraPlanService(
            $decomposer,
            app(CodeGraphWorkspaceIdentity::class),
            $brain ?? $this->stubBrain(),
        );
    }

    private function deterministic(): DeterministicObraDecomposer
    {
        return new DeterministicObraDecomposer;
    }

    /**
     * A decomposer that returns a CRAFTED draft list verbatim (for DAG-shape control).
     *
     * @param  list<ObraNodeDraft>  $drafts
     */
    private function fakeDag(array $drafts): ObraDecomposer
    {
        return new class($drafts) implements ObraDecomposer
        {
            /** @param list<ObraNodeDraft> $drafts */
            public function __construct(private array $drafts) {}

            public function label(): string
            {
                return 'fake_dag';
            }

            public function decompose(string $intent, array $opts = []): array
            {
                return $this->drafts;
            }
        };
    }

    /**
     * A brain stub returning a crafted provider-safe pack (no DB, no provider).
     */
    private function stubBrain(): AtlasOpenBrainContextPackService
    {
        return new class extends AtlasOpenBrainContextPackService
        {
            public function __construct() {}

            public function packFor(string $task, array $opts = []): array
            {
                return [
                    'schema' => self::SCHEMA,
                    'task' => $task,
                    'provenance' => ['sources_present' => ['code_graph', 'reality_graph', 'memory']],
                    'code_graph' => [['id' => 'App\\Services\\Foo', 'file_path' => 'app/Services/Foo.php']],
                    'reality_graph_paths' => [['nodes' => ['memory:m1', 'code:mod1']]],
                    'memory' => [['id' => 'mem1', 'title' => 'prior obra learning']],
                ];
            }
        };
    }

    private function throwingBrain(): AtlasOpenBrainContextPackService
    {
        return new class extends AtlasOpenBrainContextPackService
        {
            public function __construct() {}

            public function packFor(string $task, array $opts = []): array
            {
                throw new \RuntimeException('brain is down');
            }
        };
    }

    private function createObraTables(): void
    {
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();
    }
}
