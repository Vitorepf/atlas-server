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
 *
 * Circuit boundaries are drawn from real producer/consumer crossing_edges,
 * never from filename similarity alone. Callers may pass
 * filename_similarity_candidates (pairs a caller's naive name-matching heuristic
 * proposed as "possibly the same circuit"); any pair with no backing crossing_edge
 * is reported as weak_boundary_evidence instead of being trusted.
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
        $externalConsumers = [];
        $edgePairs = [];
        foreach ($crossingEdges as $edge) {
            $edge = (array) $edge;
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            $contract = (string) ($edge['contract'] ?? '');

            $malformed = false;
            if ($source === '') {
                $consolidationBlockers[] = 'malformed_crossing_edge_missing_source';
                $malformed = true;
            }
            if ($target === '') {
                $consolidationBlockers[] = 'malformed_crossing_edge_missing_target';
                $malformed = true;
            }
            if ($contract === '') {
                $consolidationBlockers[] = 'malformed_crossing_edge_missing_contract';
                $malformed = true;
            }

            if ($malformed) {
                continue;
            }

            $edgePairs[] = [$source, $target];

            if (! in_array($target, $allowedFiles, true)) {
                $consolidationBlockers[] = "crossing_edge_outside_allowed_files:{$target}";
                $externalConsumers[] = $target;
            }
        }
        $externalConsumers = array_values(array_unique($externalConsumers));

        // AC: filename-only similarity without a real producer/consumer edge is weak evidence.
        $weakBoundaryEvidence = [];
        foreach ((array) ($graph['filename_similarity_candidates'] ?? []) as $candidate) {
            $candidate = (array) $candidate;
            $a = (string) ($candidate['a'] ?? '');
            $b = (string) ($candidate['b'] ?? '');
            if ($a === '' || $b === '') {
                continue;
            }

            $backedByRealEdge = false;
            foreach ($edgePairs as [$s, $t]) {
                if (($s === $a && $t === $b) || ($s === $b && $t === $a)) {
                    $backedByRealEdge = true;
                    break;
                }
            }

            if (! $backedByRealEdge) {
                $weakBoundaryEvidence[] = [
                    'a' => $a,
                    'b' => $b,
                    'reason' => 'filename_only_similarity_without_call_edges',
                ];
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

        // AC: proof_paths are the concrete files a worker must keep green/stable —
        // the circuit's own implementation files plus their test anchors.
        $proofPaths = array_values(array_unique(array_merge($allowedFiles, $tests)));

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
            'proof_paths' => $proofPaths,
            'external_consumers' => $externalConsumers,
            'weak_boundary_evidence' => $weakBoundaryEvidence,
        ];
    }
}
