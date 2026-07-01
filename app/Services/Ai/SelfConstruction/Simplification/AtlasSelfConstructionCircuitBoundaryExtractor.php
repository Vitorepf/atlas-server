<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Extracts where an Autonomous OS circuit begins and ends before it is
 * consolidated: entrypoints, outputs, stateful ledgers, downstream
 * consumers, and crossing edges. boundary_confident is false whenever an
 * entrypoint, output contract, or ledger dependency is unknown (key not
 * supplied at all) rather than merely empty, and crossing edges that reach
 * outside the planned allowed_files set are surfaced as consolidation
 * blockers instead of silently permitted.
 */
final class AtlasSelfConstructionCircuitBoundaryExtractor
{
    /**
     * @param  array<string,mixed>  $graph
     * @return array<string,mixed>
     */
    public function extract(array $graph): array
    {
        $unknownFacts = [];
        foreach (['entrypoints', 'outputs', 'ledgers'] as $key) {
            if (! array_key_exists($key, $graph)) {
                $unknownFacts[] = $key;
            }
        }

        $allowedFiles = array_values((array) ($graph['allowed_files'] ?? []));
        $crossingEdges = array_values((array) ($graph['crossing_edges'] ?? []));

        $consolidationBlockers = [];
        foreach ($crossingEdges as $edge) {
            $target = (string) ($edge['target'] ?? '');
            if (! in_array($target, $allowedFiles, true)) {
                $consolidationBlockers[] = "crossing_edge_outside_allowed_files:{$target}";
            }
        }

        return [
            'entrypoints' => array_values((array) ($graph['entrypoints'] ?? [])),
            'outputs' => array_values((array) ($graph['outputs'] ?? [])),
            'ledgers' => array_values((array) ($graph['ledgers'] ?? [])),
            'consumers' => array_values((array) ($graph['consumers'] ?? [])),
            'crossing_edges' => $crossingEdges,
            'consolidation_blockers' => $consolidationBlockers,
            'boundary_confident' => $unknownFacts === [],
            'unknown_facts' => $unknownFacts,
        ];
    }
}
