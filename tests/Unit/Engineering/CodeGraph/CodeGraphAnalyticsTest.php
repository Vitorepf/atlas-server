<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphAnalytics;
use PHPUnit\Framework\TestCase;

class CodeGraphAnalyticsTest extends TestCase
{
    private function edge(string $from, string $to, string $type = 'depends_on'): array
    {
        return ['from_node_id' => $from, 'to_node_id' => $to, 'edge_type' => $type];
    }

    public function test_god_nodes_rank_by_degree(): void
    {
        // hub is referenced by three nodes and depends on one => degree 4.
        $edges = [
            $this->edge('node:a', 'node:hub'),
            $this->edge('node:b', 'node:hub'),
            $this->edge('node:c', 'node:hub'),
            $this->edge('node:hub', 'node:leaf'),
            $this->edge('node:a', 'node:b'),
        ];

        $result = (new CodeGraphAnalytics)->godNodes($edges, 10);

        $this->assertSame(CodeGraphAnalytics::SCHEMA, $result['schema_version']);
        $top = $result['god_nodes'][0];
        $this->assertSame('node:hub', $top['node_id']);
        $this->assertSame(4, $top['degree']);
        $this->assertSame(3, $top['in_degree']);
        $this->assertSame(1, $top['out_degree']);
    }

    public function test_god_nodes_respects_limit(): void
    {
        $edges = [
            $this->edge('node:a', 'node:b'),
            $this->edge('node:b', 'node:c'),
            $this->edge('node:c', 'node:d'),
        ];

        $result = (new CodeGraphAnalytics)->godNodes($edges, 2);

        $this->assertCount(2, $result['god_nodes']);
    }

    public function test_blast_radius_reverse_bfs_finds_dependents(): void
    {
        // A depends_on B depends_on C depends_on D. Changing D breaks C, B, A.
        $edges = [
            $this->edge('node:a', 'node:b'),
            $this->edge('node:b', 'node:c'),
            $this->edge('node:c', 'node:d', 'tests'),
        ];

        $result = (new CodeGraphAnalytics)->blastRadius($edges, 'node:d', maxDepth: 4, maxNodes: 60);

        $this->assertSame('node:d', $result['seed']);
        $this->assertFalse($result['truncated']);
        $byNode = collect($result['affected'])->keyBy('node_id');
        $this->assertSame(1, $byNode['node:c']['depth']);
        $this->assertSame('tests', $byNode['node:c']['via']);
        $this->assertSame(2, $byNode['node:b']['depth']);
        $this->assertSame(3, $byNode['node:a']['depth']);
    }

    public function test_blast_radius_respects_max_depth(): void
    {
        $edges = [
            $this->edge('node:a', 'node:b'),
            $this->edge('node:b', 'node:c'),
            $this->edge('node:c', 'node:d'),
        ];

        $result = (new CodeGraphAnalytics)->blastRadius($edges, 'node:d', maxDepth: 1, maxNodes: 60);

        $this->assertCount(1, $result['affected']);
        $this->assertSame('node:c', $result['affected'][0]['node_id']);
    }

    public function test_blast_radius_truncates_at_max_nodes(): void
    {
        $edges = [
            $this->edge('node:a', 'node:hub'),
            $this->edge('node:b', 'node:hub'),
            $this->edge('node:c', 'node:hub'),
        ];

        $result = (new CodeGraphAnalytics)->blastRadius($edges, 'node:hub', maxDepth: 4, maxNodes: 2);

        $this->assertTrue($result['truncated']);
        $this->assertCount(2, $result['affected']);
    }
}
