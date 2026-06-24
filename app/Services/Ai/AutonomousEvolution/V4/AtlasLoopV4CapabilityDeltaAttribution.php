<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V4;

/**
 * V4 CAPABILITY-DELTA ATTRIBUTION — closes the feedback the loop was missing: from "proposal X landed" →
 * "which capability dimension moved how much, and which proposals CLAIMED it". Without this, the meta-objective
 * originator gambles — it cites capability deltas no one proved came from the proposals it inspired.
 *
 * HONEST by construction:
 *  - snapshot_schema_drift: refuse unless pre/post measure the EXACT same dimensions (apples-to-apples or nothing).
 *  - zero_movement: refuse when nothing moved (no signal to attribute).
 *  - shared credit: a dimension's delta is attributed to EVERY landed proposal that targeted it, listed in
 *    proposal_id order — never divided arbitrarily, so the operator sees the co-claimants.
 *  - unattributed_dimensions: a dimension that MOVED with NO proposal claiming it is a CONFOUND the operator
 *    must see — capability moving unclaimed means the metric is noisy and any objective citing it is gambling.
 *
 * Pure: no I/O, no DB, no provider.
 */
final class AtlasLoopV4CapabilityDeltaAttribution
{
    /**
     * @param  array<string,float>  $preSnapshot
     * @param  array<string,float>  $postSnapshot
     * @param  list<array{proposal_id:string, target_dimension:string}>  $landedProposals
     * @return array{attributed:bool, deltas:array<string,array{pre:float,post:float,delta:float,attributed_to:list<string>}>, unattributed_dimensions:list<string>, refuse_reason:?string}
     */
    public function attribute(array $preSnapshot, array $postSnapshot, array $landedProposals): array
    {
        $preKeys = array_keys($preSnapshot);
        $postKeys = array_keys($postSnapshot);
        sort($preKeys);
        sort($postKeys);
        if ($preKeys !== $postKeys) {
            return $this->refuse('snapshot_schema_drift');
        }

        // Claimants per dimension, in stable proposal_id order.
        $proposals = array_values(array_filter($landedProposals, 'is_array'));
        usort($proposals, static fn (array $a, array $b): int => strcmp((string) ($a['proposal_id'] ?? ''), (string) ($b['proposal_id'] ?? '')));
        $claimantsByDim = [];
        foreach ($proposals as $proposal) {
            $dim = (string) ($proposal['target_dimension'] ?? '');
            $pid = (string) ($proposal['proposal_id'] ?? '');
            if ($dim === '' || $pid === '') {
                continue;
            }
            $claimantsByDim[$dim][] = $pid;
        }

        $deltas = [];
        $unattributed = [];
        $allZero = true;
        $anyNonZeroAttributed = false;

        $dims = array_keys($preSnapshot);
        sort($dims);
        foreach ($dims as $dim) {
            $pre = (float) $preSnapshot[$dim];
            $post = (float) $postSnapshot[$dim];
            $delta = round($post - $pre, 6);
            $attributedTo = $claimantsByDim[$dim] ?? [];

            $deltas[$dim] = ['pre' => $pre, 'post' => $post, 'delta' => $delta, 'attributed_to' => $attributedTo];

            if ($delta !== 0.0) {
                $allZero = false;
                if ($attributedTo === []) {
                    $unattributed[] = $dim; // a confound: moved with nobody claiming it
                } else {
                    $anyNonZeroAttributed = true;
                }
            }
        }

        if ($allZero) {
            return ['attributed' => false, 'deltas' => $deltas, 'unattributed_dimensions' => [], 'refuse_reason' => 'zero_movement'];
        }

        return [
            'attributed' => $anyNonZeroAttributed,
            'deltas' => $deltas,
            'unattributed_dimensions' => $unattributed,
            'refuse_reason' => null,
        ];
    }

    /**
     * @return array{attributed:bool, deltas:array<string,array{pre:float,post:float,delta:float,attributed_to:list<string>}>, unattributed_dimensions:list<string>, refuse_reason:?string}
     */
    private function refuse(string $reason): array
    {
        return ['attributed' => false, 'deltas' => [], 'unattributed_dimensions' => [], 'refuse_reason' => $reason];
    }
}
