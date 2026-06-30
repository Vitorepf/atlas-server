<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

final class AtlasLoopConsolidationCertifier
{
    public const SCHEMA = 'atlas.loop.consolidation_certifier.v1';

    public function __construct(
        private readonly AtlasLoopSelfArchitectureScanner $scanner,
        private readonly AtlasLoopSelfDependencyGraphReporter $graphReporter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function certify(string $baselinePath, ?string $scanDirectory = null): array
    {
        if (! is_file($baselinePath)) {
            return $this->envelope('baseline_missing', [], 'Baseline file not found: '.$baselinePath);
        }

        $baselineJson = (string) file_get_contents($baselinePath);
        $baseline = json_decode($baselineJson, true);
        if (! is_array($baseline) || ! isset($baseline['totalLoc'], $baseline['edgeCount'], $baseline['cycleCount'], $baseline['refillerLoc'])) {
            return $this->envelope('baseline_malformed', [], 'Baseline JSON is missing required keys');
        }

        $scan = $this->scanner->scan($scanDirectory);
        $graph = $this->graphReporter->report($scan['perFileEdges'] ?? []);

        $current = [
            'totalLoc' => (int) $scan['totalLoc'],
            'refillerLoc' => (int) $scan['refillerLoc'],
            'edgeCount' => (int) $graph['edgeCount'],
            'cycleCount' => (int) $graph['cycleCount'],
        ];

        $axes = [];
        foreach (['totalLoc', 'edgeCount', 'cycleCount', 'refillerLoc'] as $axis) {
            $baselineVal = (int) $baseline[$axis];
            $currentVal = $current[$axis];
            $axes[$axis] = [
                'baseline' => $baselineVal,
                'current' => $currentVal,
                'decreased' => $currentVal < $baselineVal,
            ];
        }

        $allDecreased = true;
        foreach ($axes as $axis) {
            if (! $axis['decreased']) {
                $allDecreased = false;
                break;
            }
        }

        return $this->envelope($allDecreased ? 'certified' : 'not_certified', $axes);
    }

    /**
     * @param  array<string, array<string, mixed>>  $axes
     * @return array<string, mixed>
     */
    private function envelope(string $verdict, array $axes, string $reason = ''): array
    {
        $result = [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'axes' => $axes,
        ];
        if ($reason !== '') {
            $result['reason'] = $reason;
        }

        return $result;
    }
}
