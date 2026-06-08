<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRuntimeEvidenceOverlay;
use PHPUnit\Framework\TestCase;

class CodeGraphRuntimeEvidenceOverlayTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $metadataOverride
     * @return array<string,mixed>
     */
    private function edge(string $sourceRef, float $confidence = 0.85, array $metadataOverride = []): array
    {
        return [
            'from_node_id' => 'node:from',
            'to_node_id' => 'node:to',
            'edge_type' => 'depends_on',
            'confidence' => 'INFERRED',
            'confidence_score' => $confidence,
            'metadata' => array_merge([
                'resolver' => 'atlas.code_graph.resolved_edges.v1',
                'relation' => 'reference',
                'source_ref' => $sourceRef,
                'occurrences' => 1,
            ], $metadataOverride),
        ];
    }

    public function test_proven_edge_gets_runtime_proven_true_and_higher_weight(): void
    {
        $edges = [$this->edge('app/Services/Foo.php:42', confidence: 0.85)];
        $proven = ['app/Services/Foo.php:42'];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        $this->assertSame(CodeGraphRuntimeEvidenceOverlay::SCHEMA, $result['schema_version']);
        $edge = $result['edges'][0];
        $this->assertTrue($edge['metadata']['runtime_proven']);
        // base weight seeded from confidence_score (0.85) then bumped x1.5 => 1.275.
        $this->assertSame(1.275, $edge['metadata']['weight']);
        $this->assertGreaterThan($edge['confidence_score'], $edge['metadata']['weight']);
    }

    public function test_unproven_edge_stays_false_with_base_weight(): void
    {
        $edges = [$this->edge('app/Services/Bar.php:7', confidence: 0.8)];
        $proven = ['app/Services/Foo.php:42'];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        $edge = $result['edges'][0];
        $this->assertFalse($edge['metadata']['runtime_proven']);
        // base weight = confidence_score, no bump.
        $this->assertSame(0.8, $edge['metadata']['weight']);
    }

    public function test_proven_edge_outweighs_identical_unproven_edge(): void
    {
        $edges = [
            $this->edge('app/A.php:1', confidence: 0.85),
            $this->edge('app/B.php:1', confidence: 0.85),
        ];
        $proven = ['app/A.php:1'];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        $provenEdge = $result['edges'][0];
        $unprovenEdge = $result['edges'][1];
        $this->assertTrue($provenEdge['metadata']['runtime_proven']);
        $this->assertFalse($unprovenEdge['metadata']['runtime_proven']);
        $this->assertGreaterThan(
            $unprovenEdge['metadata']['weight'],
            $provenEdge['metadata']['weight'],
        );
    }

    public function test_file_grained_proven_set_matches_path_line_source_ref(): void
    {
        // Runtime evidence is file-grained; the resolved edge pins an exact line.
        $edges = [$this->edge('app/Services/Foo.php:42', confidence: 1.0)];
        $proven = ['app/Services/Foo.php'];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        $this->assertTrue($result['edges'][0]['metadata']['runtime_proven']);
        // 1.0 base x 1.5.
        $this->assertSame(1.5, $result['edges'][0]['metadata']['weight']);
    }

    public function test_stats_count_proven_and_total(): void
    {
        $edges = [
            $this->edge('app/A.php:1'),
            $this->edge('app/B.php:2'),
            $this->edge('app/C.php:3'),
        ];
        $proven = ['app/A.php:1', 'app/C.php:3', 'app/unused.php:9'];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        $this->assertSame(2, $result['stats']['proven']);
        $this->assertSame(3, $result['stats']['total']);
    }

    public function test_edge_without_source_ref_is_unproven(): void
    {
        $edge = $this->edge('placeholder');
        unset($edge['metadata']['source_ref']);
        $edges = [$edge];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, ['app/A.php:1']);

        $this->assertFalse($result['edges'][0]['metadata']['runtime_proven']);
        $this->assertSame(1, $result['stats']['total']);
        $this->assertSame(0, $result['stats']['proven']);
    }

    public function test_accepts_proven_set_in_keyed_form(): void
    {
        // Set form keyed by ref with truthy value, mirroring a lookup map.
        $edges = [
            $this->edge('app/A.php:1'),
            $this->edge('app/B.php:2'),
        ];
        $proven = ['app/A.php:1' => true, 'app/B.php:2' => false];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        $this->assertTrue($result['edges'][0]['metadata']['runtime_proven']);
        // false value => not proven.
        $this->assertFalse($result['edges'][1]['metadata']['runtime_proven']);
        $this->assertSame(1, $result['stats']['proven']);
    }

    public function test_existing_weight_is_respected_as_base(): void
    {
        // A prior stage already set a weight; overlay bumps from that, not from confidence.
        $edges = [$this->edge('app/A.php:1', confidence: 0.85, metadataOverride: ['weight' => 2.0])];
        $proven = ['app/A.php:1'];

        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, $proven);

        // 2.0 base x 1.5 = 3.0, ignoring the 0.85 confidence_score.
        $this->assertSame(3.0, $result['edges'][0]['metadata']['weight']);
    }

    public function test_colon_in_symbol_is_not_treated_as_line_number(): void
    {
        // A symbol-style ref (FQCN::method) must not be split on the method `:`.
        $edges = [$this->edge('App\\Services\\Foo::handle', confidence: 1.0)];
        // File-grained proven set with the FQCN-prefix must NOT match (no digit line).
        $result = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, ['App\\Services\\Foo']);

        $this->assertFalse($result['edges'][0]['metadata']['runtime_proven']);

        // Exact symbol match DOES prove it.
        $exact = (new CodeGraphRuntimeEvidenceOverlay)->overlay($edges, ['App\\Services\\Foo::handle']);
        $this->assertTrue($exact['edges'][0]['metadata']['runtime_proven']);
    }
}
