<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfDependencyGraphReporter;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasLoopSelfDependencyGraphReporterTest extends TestCase
{
    public function test_scan_returns_deterministic_fact_array(): void
    {
        $reporter = new AtlasLoopSelfDependencyGraphReporter();
        $a = $reporter->scan();
        $b = $reporter->scan();

        self::assertSame(json_encode($a), json_encode($b));
        foreach (['nodes', 'edges', 'graph_depth', 'graph_width', 'cycles'] as $key) {
            self::assertArrayHasKey($key, $a);
        }
        self::assertIsInt($a['graph_depth']);
        self::assertIsInt($a['graph_width']);
        self::assertIsArray($a['nodes']);
        self::assertIsArray($a['edges']);
        self::assertIsArray($a['cycles']);
    }

    public function test_nodes_are_sorted_and_edges_carry_from_to_pairs(): void
    {
        $verdict = (new AtlasLoopSelfDependencyGraphReporter)->scan();

        $sorted = $verdict['nodes'];
        $copy = $sorted;
        sort($copy, SORT_STRING);
        self::assertSame($copy, $sorted);

        foreach ($verdict['edges'] as $row) {
            self::assertArrayHasKey('from', $row);
            self::assertArrayHasKey('to', $row);
            self::assertContains($row['from'], $verdict['nodes']);
            self::assertContains($row['to'], $verdict['nodes']);
        }
    }

    public function test_path_outside_autonomous_evolution_throws(): void
    {
        $reporter = new AtlasLoopSelfDependencyGraphReporter();
        $this->expectException(InvalidArgumentException::class);
        $reporter->scan(base_path('app/Services/Engineering'));
    }

    public function test_no_score_grade_quality_or_rating_field_in_output(): void
    {
        $verdict = (new AtlasLoopSelfDependencyGraphReporter)->scan();

        foreach (array_keys($verdict) as $key) {
            self::assertDoesNotMatchRegularExpression('/(score|grade|quality|rating)/i', (string) $key);
        }
    }

    public function test_graph_is_non_empty_for_real_tree(): void
    {
        $verdict = (new AtlasLoopSelfDependencyGraphReporter)->scan();

        self::assertGreaterThan(0, count($verdict['nodes']));
        self::assertGreaterThan(0, count($verdict['edges']));
        self::assertGreaterThanOrEqual(0, $verdict['graph_depth']);
        self::assertGreaterThanOrEqual(0, $verdict['graph_width']);
    }
}
