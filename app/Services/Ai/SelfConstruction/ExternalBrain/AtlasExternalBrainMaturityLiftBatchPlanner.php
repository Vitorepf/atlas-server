<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes batches that lift maturity bottlenecks in order rather
 * than scattering unrelated shallow improvements.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainMaturityLiftBatchPlanner
{
    public const SCHEMA = 'atlas.self_construction.external_brain_maturity_lift_batch_planner.v1';

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function plan(array $candidates): array
    {
        $bottlenecks = [];
        $padding = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = (string) ($candidate['id'] ?? '');
            $maturityGap = (float) ($candidate['maturity_gap'] ?? 0.0);
            $dependsOn = (array) ($candidate['depends_on'] ?? []);
            $isBottleneck = (bool) ($candidate['is_bottleneck'] ?? false);
            $impactScore = (float) ($candidate['impact_score'] ?? 0.0);

            if ($isBottleneck || $maturityGap > 0.0) {
                $bottlenecks[] = [
                    'id' => $id,
                    'maturity_gap' => $maturityGap,
                    'depends_on' => $dependsOn,
                    'impact_score' => $impactScore,
                    'is_bottleneck' => true,
                ];
            } else {
                $padding[] = [
                    'id' => $id,
                    'impact_score' => $impactScore,
                    'is_bottleneck' => false,
                ];
            }
        }

        // Order bottlenecks by maturity gap descending
        usort($bottlenecks, static fn (array $a, array $b): int =>
            $b['maturity_gap'] <=> $a['maturity_gap']);

        // Sequence dependent tasks
        $sequenced = $this->topologicalSort($bottlenecks);

        return [
            'schema' => self::SCHEMA,
            'batch' => $sequenced,
            'batch_count' => count($sequenced),
            'rejected_padding' => $padding,
            'rejected_count' => count($padding),
            'bottleneck_count' => count($bottlenecks),
        ];
    }

    private function topologicalSort(array $bottlenecks): array
    {
        $ids = array_column($bottlenecks, 'id');
        $idSet = array_flip($ids);
        $visited = [];
        $result = [];

        $visit = function (string $id) use (&$visited, &$result, $bottlenecks, $idSet, &$visit): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;

            // Find dependencies and visit them first
            foreach ($bottlenecks as $b) {
                if ($b['id'] === $id) {
                    foreach ($b['depends_on'] as $dep) {
                        if (isset($idSet[$dep])) {
                            $visit($dep);
                        }
                    }
                    break;
                }
            }

            // Add self to result
            foreach ($bottlenecks as $b) {
                if ($b['id'] === $id) {
                    $result[] = $b;
                    break;
                }
            }
        };

        foreach ($bottlenecks as $b) {
            $visit($b['id']);
        }

        return $result;
    }
}
