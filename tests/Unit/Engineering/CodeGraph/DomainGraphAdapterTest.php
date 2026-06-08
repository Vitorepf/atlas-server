<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphAnalytics;
use App\Services\Engineering\CodeGraph\DomainGraphAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Cross-domain proof (AP-812 M-8): the SAME code-graph analytics
 * ({@see CodeGraphAnalytics}) run unchanged on FINANCE-domain edges produced by
 * {@see DomainGraphAdapter}. Nothing in the analytics is code-specific — they
 * operate on opaque {from_node_id, to_node_id, edge_type} tuples, so a financial
 * dependency graph ranks god-nodes and computes blast-radius identically to a
 * code graph. This is the domain-agnostic claim, proven not asserted.
 */
class DomainGraphAdapterTest extends TestCase
{
    /**
     * A small FINANCE relation set: SPY is the systemic hub many instruments
     * track; QQQ is a sub-hub; rates flow USDt -> TLT -> ... etc.
     *
     * @return array<int,array<string,mixed>>
     */
    private function financeRelations(): array
    {
        return [
            // Three instruments track SPY (the systemic factor) => SPY is a god-node.
            ['from' => 'AAPL', 'to' => 'SPY', 'type' => 'tracks'],
            ['from' => 'MSFT', 'to' => 'SPY', 'type' => 'tracks'],
            ['from' => 'QQQ', 'to' => 'SPY', 'type' => 'tracks'],
            // SPY itself has exposure to the rate factor (an outgoing edge).
            ['from' => 'SPY', 'to' => 'TLT', 'type' => 'hedges'],
            // A second, weaker hub.
            ['from' => 'NVDA', 'to' => 'QQQ', 'type' => 'tracks'],
        ];
    }

    public function test_adapter_emits_canonical_namespaced_edges(): void
    {
        $edges = (new DomainGraphAdapter)->toEdges('finance', [
            ['from' => 'AAPL', 'to' => 'SPY', 'type' => 'tracks'],
        ]);

        $this->assertCount(1, $edges);
        $edge = $edges[0];
        // Exactly the canonical shape the analytics + resolver use.
        $this->assertSame('finance:AAPL', $edge['from_node_id']);
        $this->assertSame('finance:SPY', $edge['to_node_id']);
        $this->assertSame('tracks', $edge['edge_type']);
        $this->assertSame(DomainGraphAdapter::CONFIDENCE_INFERRED, $edge['confidence']);
    }

    public function test_adapter_namespaces_by_domain_and_drops_self_loops(): void
    {
        $edges = (new DomainGraphAdapter)->toEdges('marketing', [
            ['from' => 'ad', 'to' => 'lead', 'type' => 'drives'],
            ['from' => 'lead', 'to' => 'lead', 'type' => 'loops'], // self-loop dropped
            ['from' => '', 'to' => 'x'],                            // missing from dropped
        ]);

        $this->assertCount(1, $edges);
        $this->assertSame('marketing:ad', $edges[0]['from_node_id']);
        $this->assertSame('marketing:lead', $edges[0]['to_node_id']);
    }

    public function test_adapter_defaults_edge_type_and_dedupes(): void
    {
        $edges = (new DomainGraphAdapter)->toEdges('finance', [
            ['from' => 'A', 'to' => 'B'],            // no type => relates_to
            ['from' => 'A', 'to' => 'B'],            // duplicate => deduped, occ++
        ]);

        $this->assertCount(1, $edges);
        $this->assertSame('relates_to', $edges[0]['edge_type']);
        $this->assertSame(2, $edges[0]['metadata']['occurrences']);
    }

    public function test_existing_god_nodes_analytics_runs_unchanged_on_finance_edges(): void
    {
        // Build FINANCE edges through the adapter, then feed the UNMODIFIED
        // CodeGraphAnalytics::godNodes(). The hub must surface by degree alone.
        $edges = (new DomainGraphAdapter)->toEdges('finance', $this->financeRelations());

        $result = (new CodeGraphAnalytics)->godNodes($edges, 10);

        // Same schema, same machinery — no code-specific assumption fired.
        $this->assertSame(CodeGraphAnalytics::SCHEMA, $result['schema_version']);

        $top = $result['god_nodes'][0];
        // finance:SPY = 3 incoming (AAPL/MSFT/QQQ track it) + 1 outgoing (hedges TLT) = degree 4.
        $this->assertSame('finance:SPY', $top['node_id']);
        $this->assertSame(4, $top['degree']);
        $this->assertSame(3, $top['in_degree']);
        $this->assertSame(1, $top['out_degree']);
    }

    public function test_existing_blast_radius_analytics_runs_unchanged_on_finance_edges(): void
    {
        // "If SPY (the systemic factor) moves, what is exposed?" Reverse-BFS over
        // the SAME analytics over adapter-built finance edges.
        $edges = (new DomainGraphAdapter)->toEdges('finance', $this->financeRelations());

        $result = (new CodeGraphAnalytics)->blastRadius($edges, 'finance:SPY', maxDepth: 4, maxNodes: 60);

        $this->assertSame('finance:SPY', $result['seed']);
        $this->assertFalse($result['truncated']);

        $byNode = [];
        foreach ($result['affected'] as $hit) {
            $byNode[$hit['node_id']] = $hit;
        }

        // Direct trackers of SPY are depth 1, reached via the 'tracks' edge_type.
        $this->assertArrayHasKey('finance:AAPL', $byNode);
        $this->assertSame(1, $byNode['finance:AAPL']['depth']);
        $this->assertSame('tracks', $byNode['finance:AAPL']['via']);
        $this->assertSame(1, $byNode['finance:MSFT']['depth']);
        $this->assertSame(1, $byNode['finance:QQQ']['depth']);

        // NVDA tracks QQQ which tracks SPY => NVDA is exposed at depth 2 (transitive).
        $this->assertArrayHasKey('finance:NVDA', $byNode);
        $this->assertSame(2, $byNode['finance:NVDA']['depth']);

        // TLT is an OUTGOING dependency of SPY, not a dependent => never in blast radius.
        $this->assertArrayNotHasKey('finance:TLT', $byNode);
    }
}
