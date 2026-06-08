<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CrossDomainEntityIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CrossDomainEntityIngestionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTables();

        // 'programming' is a REGISTRY id → must resolve to canonical 'engineering'.
        // 'finance' is canonical + ARPTL-sensitive.
        foreach ([
            ['id' => 'rr-finance-1', 'domain_id' => 'finance'],
            ['id' => 'rr-programming-1', 'domain_id' => 'programming'],
            ['id' => 'rr-unknown-1', 'domain_id' => 'not-a-real-domain'], // skipped
        ] as $row) {
            DB::table('ai_domain_runtime_records')->insert($row);
        }

        foreach ([
            ['id' => 'ev-finance-1', 'domain_id' => 'finance'],
            ['id' => 'ev-programming-1', 'domain_id' => 'programming'],
            ['id' => 'ev-null', 'domain_id' => null], // null domain → skipped
        ] as $row) {
            DB::table('ai_evidence_packs')->insert($row);
        }
    }

    protected function tearDown(): void
    {
        foreach (['ai_domain_runtime_records', 'ai_evidence_packs', 'ai_claims'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function bootTables(): void
    {
        foreach (['ai_domain_runtime_records', 'ai_evidence_packs', 'ai_claims'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('ai_domain_runtime_records', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain_id', 80)->nullable();
        });
        Schema::create('ai_evidence_packs', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain_id', 80)->nullable();
        });
        Schema::create('ai_claims', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain_id', 80)->nullable();
        });
    }

    private function service(): CrossDomainEntityIngestionService
    {
        return new CrossDomainEntityIngestionService(new CrossDomainTaxonomyMap);
    }

    public function test_entity_nodes_are_namespaced_and_privacy_tagged(): void
    {
        $graph = $this->service()->gather();

        $this->assertSame(CrossDomainEntityIngestionService::SCHEMA, $graph['schema_version']);

        $byId = [];
        foreach ($graph['nodes'] as $n) {
            $byId[$n['node_id']] = $n;
        }

        // Namespaced "domain:<canonical>:<type>:<rowid>"; programming → engineering.
        $this->assertArrayHasKey('domain:finance:runtime_record:rr-finance-1', $byId);
        $this->assertArrayHasKey('domain:engineering:runtime_record:rr-programming-1', $byId);
        $this->assertArrayHasKey('domain:finance:evidence:ev-finance-1', $byId);
        $this->assertArrayHasKey('domain:engineering:evidence:ev-programming-1', $byId);

        // Unknown / null domain rows are skipped (never invent a domain).
        $this->assertArrayNotHasKey('domain:not-a-real-domain:runtime_record:rr-unknown-1', $byId);
        foreach (array_keys($byId) as $id) {
            $this->assertStringNotContainsString('not-a-real-domain', $id);
            $this->assertStringNotContainsString(':ev-null', $id);
        }

        // privacy_class from taxonomy: finance sensitive, engineering normal.
        $finance = $byId['domain:finance:runtime_record:rr-finance-1'];
        $this->assertSame('sensitive', $finance['metadata']['privacy_class']);
        $this->assertSame('finance', $finance['metadata']['domain']);
        $this->assertSame('runtime_record', $finance['node_type']);

        $eng = $byId['domain:engineering:runtime_record:rr-programming-1'];
        $this->assertSame('normal', $eng['metadata']['privacy_class']);
        $this->assertSame('engineering', $eng['metadata']['domain']); // resolved through the map

        // 2 runtime_record + 2 evidence valid rows = 4 entities; by_type tallied.
        $this->assertSame(4, $graph['stats']['entity_count']);
        $this->assertSame(['evidence' => 2, 'runtime_record' => 2], $graph['stats']['by_type']);
    }

    public function test_belongs_to_edges_point_at_the_domain_node_intra_domain(): void
    {
        $graph = $this->service()->gather();

        $byFrom = [];
        foreach ($graph['edges'] as $e) {
            $byFrom[$e['from_node_id']] = $e;
        }

        $edge = $byFrom['domain:engineering:runtime_record:rr-programming-1'] ?? null;
        $this->assertNotNull($edge);
        $this->assertSame('domain:engineering', $edge['to_node_id']); // points at the domain node
        $this->assertSame(CrossDomainEntityIngestionService::EDGE_BELONGS_TO, $edge['edge_type']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertSame(1.0, $edge['confidence_score']);
        $this->assertFalse($edge['metadata']['cross_domain']); // intra-domain, not a crossing

        // One belongs_to edge per entity node.
        $this->assertCount(count($graph['nodes']), $graph['edges']);
        foreach ($graph['edges'] as $e) {
            $this->assertStringStartsWith('domain:', $e['to_node_id']);
            $this->assertStringNotContainsString(':', substr($e['to_node_id'], strlen('domain:'))); // domain node, no entity suffix
        }
    }

    public function test_cap_is_respected(): void
    {
        $graph = $this->service()->gather(2);

        $this->assertSame(2, $graph['stats']['entity_count']);
        $this->assertCount(2, $graph['nodes']);
        $this->assertCount(2, $graph['edges']);
    }

    public function test_empty_safe_when_tables_absent(): void
    {
        foreach (['ai_domain_runtime_records', 'ai_evidence_packs', 'ai_claims'] as $t) {
            Schema::dropIfExists($t);
        }

        $graph = $this->service()->gather();

        $this->assertSame([], $graph['nodes']);
        $this->assertSame([], $graph['edges']);
        $this->assertSame(0, $graph['stats']['entity_count']);
        $this->assertSame([], $graph['stats']['by_type']);
    }

    public function test_deterministic_ordering_and_dedup_by_node_id(): void
    {
        $first = $this->service()->gather();
        $second = $this->service()->gather();

        $this->assertSame($first['nodes'], $second['nodes']); // stable order
        $this->assertSame($first['edges'], $second['edges']);

        $ids = array_map(static fn (array $n): string => (string) $n['node_id'], $first['nodes']);
        $this->assertSame($ids, array_values(array_unique($ids))); // no dup node_ids
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids); // sorted by node_id
    }

    public function test_claims_table_contributes_claim_entities_when_present(): void
    {
        DB::table('ai_claims')->insert(['id' => 'cl-finance-1', 'domain_id' => 'finance']);

        $graph = $this->service()->gather();

        $byId = [];
        foreach ($graph['nodes'] as $n) {
            $byId[$n['node_id']] = $n;
        }

        $this->assertArrayHasKey('domain:finance:claim:cl-finance-1', $byId);
        $this->assertSame('claim', $byId['domain:finance:claim:cl-finance-1']['node_type']);
        $this->assertSame(1, $graph['stats']['by_type']['claim']);
    }
}
