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

        $consumers = array_values((array) ($graph['consumers'] ?? []));
        $publicMethods = array_values((array) ($graph['public_methods'] ?? []));
        $privateHelpers = array_values((array) ($graph['private_helpers'] ?? []));
        $sideEffects = array_values((array) ($graph['side_effects'] ?? []));
        $tests = array_values((array) ($graph['tests'] ?? []));

        // A merge/delete candidate is only safe to collapse when its public contract,
        // callers, side-effect footprint, and test coverage are all proven — silence
        // on any one of these is a missing boundary proof, never an implied "safe".
        $missingBoundaryProof = [];
        if ($publicMethods === []) {
            $missingBoundaryProof[] = 'missing_public_methods';
        }
        if ($consumers === []) {
            $missingBoundaryProof[] = 'missing_consumers';
        }
        if ($sideEffects === []) {
            $missingBoundaryProof[] = 'missing_side_effect_inventory';
        }
        if ($tests === []) {
            $missingBoundaryProof[] = 'missing_test_anchors';
        }

        $safeToCollapse = $missingBoundaryProof === [];

        return [
            'entrypoints' => array_values((array) ($graph['entrypoints'] ?? [])),
            'outputs' => array_values((array) ($graph['outputs'] ?? [])),
            'ledgers' => array_values((array) ($graph['ledgers'] ?? [])),
            'consumers' => $consumers,
            'crossing_edges' => $crossingEdges,
            'consolidation_blockers' => $consolidationBlockers,
            'boundary_confident' => $unknownFacts === [],
            'unknown_facts' => $unknownFacts,
            'public_methods' => $publicMethods,
            'private_helpers' => $privateHelpers,
            'side_effects' => $sideEffects,
            'tests' => $tests,
            'missing_boundary_proof' => $missingBoundaryProof,
            'safe_to_collapse' => $safeToCollapse,
            'unsafe_to_collapse' => ! $safeToCollapse,
        ];
    }
}
