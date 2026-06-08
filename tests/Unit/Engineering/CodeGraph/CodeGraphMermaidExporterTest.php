<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver;
use App\Services\Engineering\CodeGraph\CodeGraphMermaidExporter;
use PHPUnit\Framework\TestCase;

class CodeGraphMermaidExporterTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function edge(string $from, string $to, string $confidence, string $type = 'depends_on'): array
    {
        return [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'confidence' => $confidence,
            'confidence_score' => $confidence === CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED ? 1.0 : 0.85,
        ];
    }

    public function test_emits_valid_flowchart_header(): void
    {
        $mermaid = (new CodeGraphMermaidExporter)->toMermaid([
            $this->edge('node:a', 'node:b', CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED),
        ]);

        $this->assertStringStartsWith('flowchart LR', $mermaid);
        $this->assertStringEndsWith("\n", $mermaid);
    }

    public function test_renders_an_edge_line_with_sanitized_node_ids(): void
    {
        $mermaid = (new CodeGraphMermaidExporter)->toMermaid([
            $this->edge('node:app/Services/Ai/Router', 'node:app/Models/User', CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED),
        ]);

        // Sanitized ids: only alnum/_ survive (":" and "/" become "_").
        $this->assertStringContainsString('node_app_Services_Ai_Router', $mermaid);
        $this->assertStringContainsString('node_app_Models_User', $mermaid);
        // The original label is preserved in the bracketed node declaration.
        $this->assertStringContainsString('["node:app/Services/Ai/Router"]', $mermaid);
        // An actual edge line connecting the two sanitized ids exists.
        $this->assertMatchesRegularExpression(
            '/node_app_Services_Ai_Router\s+-->\|depends_on\|\s+node_app_Models_User/',
            $mermaid,
        );
        // No raw "/" or ":" leaked into a node identifier position (left of the arrow).
        $this->assertDoesNotMatchRegularExpression('/^\s+\S*[\/:]\S*\s+-/m', $mermaid);
    }

    public function test_extracted_is_solid_and_inferred_is_dashed(): void
    {
        $mermaid = (new CodeGraphMermaidExporter)->toMermaid([
            $this->edge('node:a', 'node:b', CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED),
            $this->edge('node:c', 'node:d', CodeGraphEdgeResolver::CONFIDENCE_INFERRED),
        ]);

        $lines = explode("\n", $mermaid);
        $solid = $this->lineFor($lines, 'a', 'b');
        $dashed = $this->lineFor($lines, 'c', 'd');

        // EXTRACTED -> solid arrow, never dashed.
        $this->assertStringContainsString('-->', $solid);
        $this->assertStringNotContainsString('-.->', $solid);

        // INFERRED -> dashed arrow.
        $this->assertStringContainsString('-.->', $dashed);
    }

    public function test_unknown_confidence_defaults_to_dashed(): void
    {
        // No confidence label at all must under-claim: dashed, not solid.
        $mermaid = (new CodeGraphMermaidExporter)->toMermaid([
            ['from_node_id' => 'node:x', 'to_node_id' => 'node:y', 'edge_type' => 'depends_on'],
        ]);

        $line = $this->lineFor(explode("\n", $mermaid), 'x', 'y');
        $this->assertStringContainsString('-.->', $line);
    }

    public function test_confidence_in_metadata_is_honored(): void
    {
        $mermaid = (new CodeGraphMermaidExporter)->toMermaid([
            [
                'from_node_id' => 'node:m',
                'to_node_id' => 'node:n',
                'edge_type' => 'depends_on',
                'metadata' => ['confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED],
            ],
        ]);

        $line = $this->lineFor(explode("\n", $mermaid), 'm', 'n');
        $this->assertStringContainsString('-->', $line);
        $this->assertStringNotContainsString('-.->', $line);
    }

    public function test_caps_at_max_edges_with_omitted_annotation(): void
    {
        $edges = [];
        for ($i = 0; $i < 10; $i++) {
            $edges[] = $this->edge('node:src'.$i, 'node:dst'.$i, CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED);
        }

        $mermaid = (new CodeGraphMermaidExporter)->toMermaid($edges, 3);

        // Exactly 3 edge lines rendered (lines containing an arrow).
        $arrowLines = array_filter(
            explode("\n", $mermaid),
            static fn (string $l): bool => str_contains($l, '-->') || str_contains($l, '-.->'),
        );
        $this->assertCount(3, $arrowLines);

        // The cap annotation reports the omitted count (10 - 3 = 7).
        $this->assertStringContainsString('%% omitted 7 for readability', $mermaid);
    }

    public function test_no_omitted_annotation_when_under_cap(): void
    {
        $mermaid = (new CodeGraphMermaidExporter)->toMermaid([
            $this->edge('node:a', 'node:b', CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED),
        ], 40);

        $this->assertStringNotContainsString('omitted', $mermaid);
    }

    public function test_output_is_deterministic(): void
    {
        $exporter = new CodeGraphMermaidExporter;
        $a = [
            $this->edge('node:b', 'node:c', CodeGraphEdgeResolver::CONFIDENCE_INFERRED),
            $this->edge('node:a', 'node:b', CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED),
        ];
        // Same set, different input order.
        $b = [
            $this->edge('node:a', 'node:b', CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED),
            $this->edge('node:b', 'node:c', CodeGraphEdgeResolver::CONFIDENCE_INFERRED),
        ];

        $this->assertSame($exporter->toMermaid($a), $exporter->toMermaid($b));
    }

    /**
     * Find the single rendered edge line connecting two short node labels
     * ("a" => "node:a"), independent of declaration lines.
     *
     * @param  array<int,string>  $lines
     */
    private function lineFor(array $lines, string $from, string $to): string
    {
        $fromId = 'node_'.$from;
        $toId = 'node_'.$to;
        foreach ($lines as $line) {
            if (! str_contains($line, '-->') && ! str_contains($line, '-.->')) {
                continue;
            }
            // Edge line shape: "<indent><fromId> <arrow>|type| <toId>"
            if (preg_match('/^\s+'.preg_quote($fromId, '/').'\s/', $line)
                && str_ends_with(rtrim($line), $toId)) {
                return $line;
            }
        }

        $this->fail("No edge line found for {$fromId} -> {$toId} in:\n".implode("\n", $lines));
    }
}
