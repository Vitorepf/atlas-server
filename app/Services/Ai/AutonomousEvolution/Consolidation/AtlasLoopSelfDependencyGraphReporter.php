<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

final class AtlasLoopSelfDependencyGraphReporter
{
    public const SCHEMA = 'atlas.loop.self_dependency_graph_report.v1';

    /**
     * @param  array<string, list<string>>  $perFileEdges  from AtlasLoopSelfArchitectureScanner
     * @return array<string, mixed>
     */
    public function report(array $perFileEdges): array
    {
        $edgeCount = 0;
        foreach ($perFileEdges as $edges) {
            $edgeCount += count($edges);
        }

        $cycles = $this->tarjanCycles($perFileEdges);

        return [
            'schema_version' => self::SCHEMA,
            'edgeCount' => $edgeCount,
            'cycleCount' => count($cycles),
            'cycleMembers' => $cycles,
        ];
    }

    /**
     * @param  array<string, list<string>>  $graph
     * @return list<list<string>>
     */
    private function tarjanCycles(array $graph): array
    {
        $index = 0;
        $stack = [];
        $onStack = [];
        $indices = [];
        $lowlinks = [];
        $cycles = [];

        $nodes = array_keys($graph);

        $strongConnect = null;
        $strongConnect = function (string $v) use (&$index, &$stack, &$onStack, &$indices, &$lowlinks, &$cycles, &$graph, &$strongConnect): void {
            $indices[$v] = $index;
            $lowlinks[$v] = $index;
            $index++;
            $stack[] = $v;
            $onStack[$v] = true;

            foreach (($graph[$v] ?? []) as $w) {
                if (! isset($indices[$w])) {
                    if (isset($graph[$w])) {
                        $strongConnect($w);
                        $lowlinks[$v] = min($lowlinks[$v], $lowlinks[$w]);
                    }
                } elseif (! empty($onStack[$w])) {
                    $lowlinks[$v] = min($lowlinks[$v], $indices[$w]);
                }
            }

            if ($lowlinks[$v] === $indices[$v]) {
                $component = [];
                do {
                    $w = array_pop($stack);
                    unset($onStack[$w]);
                    $component[] = $w;
                } while ($w !== $v);

                if (count($component) > 1) {
                    sort($component, SORT_STRING);
                    $cycles[] = $component;
                }
            }
        };

        foreach ($nodes as $node) {
            if (! isset($indices[$node])) {
                $strongConnect($node);
            }
        }

        usort($cycles, static fn (array $a, array $b): int => ($a[0] ?? '') <=> ($b[0] ?? ''));

        return $cycles;
    }
}
