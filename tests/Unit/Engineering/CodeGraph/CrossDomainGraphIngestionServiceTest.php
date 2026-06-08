<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphAnalytics;
use App\Services\Engineering\CodeGraph\CrossDomainGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CrossDomainGraphIngestionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai_domain_profiles', 'ai_domain_handoffs'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('ai_domain_profiles', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('label', 160)->nullable();
            $table->string('status', 40)->default('active');
        });
        Schema::create('ai_domain_handoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_domain_id', 80);
            $table->string('target_domain_id', 80);
        });

        foreach (['finance', 'general', 'programming', 'research'] as $d) {
            DB::table('ai_domain_profiles')->insert(['id' => $d, 'label' => ucfirst($d), 'status' => 'active']);
        }
        // Note: 'programming' is a REGISTRY id → resolves to canonical 'engineering'.
        $handoffs = [
            ['programming', 'finance'],
            ['programming', 'finance'], // exact dup -> occurrences=2 (after canonicalization)
            ['general', 'finance'],
            ['research', 'finance'],
            ['finance', 'general'],
            ['general', 'general'],     // self-loop -> dropped
        ];
        foreach ($handoffs as $i => [$src, $dst]) {
            DB::table('ai_domain_handoffs')->insert([
                'id' => sprintf('00000000-0000-0000-0000-%012d', $i),
                'source_domain_id' => $src,
                'target_domain_id' => $dst,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['ai_domain_profiles', 'ai_domain_handoffs'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function service(): CrossDomainGraphIngestionService
    {
        // null mesh → only handoff edges in this unit (mesh edges proven live).
        return new CrossDomainGraphIngestionService(new CrossDomainTaxonomyMap);
    }

    public function test_nodes_are_the_canonical_superset_with_privacy_tags(): void
    {
        $graph = $this->service()->gather();

        $byId = [];
        foreach ($graph['nodes'] as $n) {
            $byId[$n['node_id']] = $n;
        }

        // Nodes ARE the 21 canonical domains (the single source of truth), regardless
        // of how many rows ai_domain_profiles happens to hold.
        $this->assertSame(21, $graph['stats']['domain_count']);
        $this->assertArrayHasKey('domain:engineering', $byId);
        $this->assertArrayHasKey('domain:finance', $byId);
        $this->assertSame('sensitive', $byId['domain:finance']['metadata']['privacy_class']);
        $this->assertSame('normal', $byId['domain:engineering']['metadata']['privacy_class']);
        // The canonical node carries both aliases for downstream reconciliation.
        $this->assertSame('programming', $byId['domain:engineering']['metadata']['registry_id']);
        $this->assertSame(6, $graph['stats']['sensitive_domain_count']);
    }

    public function test_handoff_edges_resolve_through_the_canonical_map(): void
    {
        $graph = $this->service()->gather();

        $byKey = [];
        foreach ($graph['edges'] as $e) {
            $byKey[$e['from_node_id'].'->'.$e['to_node_id']] = $e;
        }

        // 'programming'->'finance' canonicalizes to engineering->finance.
        $this->assertArrayHasKey('domain:engineering->domain:finance', $byKey);
        $this->assertSame(2, $byKey['domain:engineering->domain:finance']['metadata']['occurrences']);
        $this->assertSame('EXTRACTED', $byKey['domain:engineering->domain:finance']['confidence']);

        // 4 distinct cross-domain handoff edges; self-loop dropped.
        $this->assertSame(4, $graph['stats']['handoff_edge_count']);
        $this->assertSame(4, $graph['stats']['cross_domain_edge_count']);
        $this->assertArrayNotHasKey('domain:general->domain:general', $byKey);
    }

    public function test_existing_analytics_runs_unchanged_on_cross_domain_edges(): void
    {
        $graph = $this->service()->gather();
        $god = (new CodeGraphAnalytics)->godNodes($graph['edges'], 10);

        // finance: 3 incoming (engineering/general/research) + 1 outgoing (general) = degree 4.
        $this->assertSame('domain:finance', $god['god_nodes'][0]['node_id']);
    }

    public function test_canonical_nodes_present_but_edge_empty_when_event_tables_absent(): void
    {
        Schema::dropIfExists('ai_domain_handoffs');
        Schema::dropIfExists('ai_domain_profiles');

        $graph = $this->service()->gather();

        // The canonical domain set always exists (it's the map, not the DB); only
        // edges depend on the event tables.
        $this->assertSame(21, $graph['stats']['domain_count']);
        $this->assertSame([], $graph['edges']);
        $this->assertSame(0, $graph['stats']['handoff_edge_count']);
    }
}
