<?php

namespace Tests\Feature\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRuntimeInvoker;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * AP-812 §9.2 — the governed PHP -> python_ai_data invoker, behind the flag.
 *
 * No DB: the invoker operates on an edge set it is handed and shells out to the
 * (pure-stdlib) python runtime, so this needs no schema booting — unlike the
 * sibling CodeGraphEdgeBuilderTest. We assert the two contract guarantees:
 *  - flag ON  + a tiny edge set  -> status 'succeeded' with a ranked result;
 *  - flag OFF                     -> status 'blocked' WITHOUT running anything.
 *
 * If python3 is genuinely absent the success case is skipped (the runtime can't
 * be exercised), but the blocked-by-flag guarantee is still proven.
 */
class CodeGraphRuntimeInvokerTest extends TestCase
{
    public function test_blocked_when_flag_disabled(): void
    {
        config()->set('atlas.code_graph.real_edges', false);

        $result = (new CodeGraphRuntimeInvoker)->invoke('betweenness', [
            'edges' => $this->pathEdges(),
            'limit' => 5,
        ]);

        $this->assertSame(CodeGraphRuntimeInvoker::RESULT_SCHEMA, $result['schema_version']);
        $this->assertSame(CodeGraphRuntimeInvoker::STATUS_BLOCKED, $result['status']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame('runtime_blocked', $result['findings'][0]['kind'] ?? null);
        $this->assertSame('code_graph_real_edges_flag_disabled', $result['findings'][0]['reason'] ?? null);
    }

    public function test_succeeds_and_ranks_betweenness_when_flag_enabled(): void
    {
        if ((new ExecutableFinder)->find('python3') === null) {
            $this->markTestSkipped('python3 is not available in this environment.');
        }

        config()->set('atlas.code_graph.real_edges', true);

        // Path graph a-b-c-d-e: c is the most central (sits on the most shortest
        // paths); the two endpoints have zero betweenness.
        $result = (new CodeGraphRuntimeInvoker)->invoke('betweenness', [
            'edges' => $this->pathEdges(),
            'limit' => 10,
            'normalized' => true,
        ]);

        $this->assertSame(CodeGraphRuntimeInvoker::RESULT_SCHEMA, $result['schema_version']);
        $this->assertSame(
            CodeGraphRuntimeInvoker::STATUS_SUCCEEDED,
            $result['status'],
            'runtime did not succeed; findings: '.json_encode($result['findings'])
        );

        $artifact = $result['artifacts'][0] ?? [];
        $this->assertSame('betweenness', $artifact['op'] ?? null);

        $payload = $artifact['result'] ?? [];
        $this->assertSame('atlas.code_graph.centrality.v1', $payload['schema_version'] ?? null);
        $this->assertSame(5, $payload['node_count'] ?? null);
        $this->assertSame(4, $payload['edge_count'] ?? null);

        $ranked = $payload['ranked'] ?? [];
        $this->assertNotEmpty($ranked, 'expected a ranked result');
        $this->assertSame('c', $ranked[0]['node_id'] ?? null, 'middle node of a path must rank first');

        $scores = [];
        foreach ($ranked as $row) {
            $scores[$row['node_id']] = $row['betweenness'];
        }
        $this->assertGreaterThan($scores['b'], $scores['c']);
        $this->assertSame(0.0, $scores['a']);
        $this->assertSame(0.0, $scores['e']);

        // Metrics surface the shape the brain records as evidence.
        $this->assertSame(5, $result['metrics']['node_count'] ?? null);
        $this->assertSame(5, $result['metrics']['ranked_count'] ?? null);
    }

    /**
     * @return array<int,array{from_node_id:string,to_node_id:string}>
     */
    private function pathEdges(): array
    {
        return [
            ['from_node_id' => 'a', 'to_node_id' => 'b'],
            ['from_node_id' => 'b', 'to_node_id' => 'c'],
            ['from_node_id' => 'c', 'to_node_id' => 'd'],
            ['from_node_id' => 'd', 'to_node_id' => 'e'],
        ];
    }
}
