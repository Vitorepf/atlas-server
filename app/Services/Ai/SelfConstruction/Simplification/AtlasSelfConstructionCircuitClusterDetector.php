<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure detector: groups organs into consolidation clusters using evidence — shared
 * capability label, shared inputs, shared outputs, proof overlap, and consumer
 * overlap — rather than class-name similarity.
 *
 * A cluster is only marked merge_ready when the members share BOTH proof overlap
 * and consumer overlap; capability/input/output overlap alone is not enough,
 * since two organs can look alike by name or signature yet serve different callers
 * with no shared evidence trail.
 *
 * CLUSTER TYPE (cluster_type, evidence-driven, never name-based):
 *   wrapper_bloat_cluster — every member reports method_count === 1 (a one-method
 *                            pass-through organ); flags the family as bloat even
 *                            when proof/consumer overlap alone would not qualify.
 *   gate_shard_duplicate  — every member reports is_gate_shard === true AND shares
 *                            decision_inputs; emits keeper_candidate (the member with
 *                            the most proof_refs, ties broken alphabetically) and
 *                            retirement_candidates (every other member).
 *   capability_overlap    — default, no wrapper/gate-shard evidence present.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionCircuitClusterDetector
{
    public const SCHEMA = 'atlas.self_construction.circuit_cluster_detector.v1';

    /**
     * @param  list<array{
     *   name?: string,
     *   capability_label?: string,
     *   inputs?: list<string>,
     *   outputs?: list<string>,
     *   proof_refs?: list<string>,
     *   consumers?: list<string>,
     *   responsibility_tags?: list<string>,
     *   tests?: list<string>,
     * }>  $organs
     * @return array{schema:string, clusters:list<array<string,mixed>>}
     */
    public function detect(array $organs): array
    {
        $byCapability = [];
        foreach ($organs as $organ) {
            $label = (string) ($organ['capability_label'] ?? '');
            if ($label === '') {
                continue;
            }
            $byCapability[$label][] = $organ;
        }

        $clusters = [];
        foreach ($byCapability as $label => $members) {
            if (count($members) < 2) {
                continue;
            }

            $names = array_values(array_map(static fn (array $o): string => (string) ($o['name'] ?? ''), $members));
            sort($names, SORT_STRING);

            $sharedInputs = $this->intersectAcross($members, 'inputs');
            $sharedOutputs = $this->intersectAcross($members, 'outputs');
            $proofOverlap = $this->intersectAcross($members, 'proof_refs');
            $consumerOverlap = $this->intersectAcross($members, 'consumers');
            $responsibilityOverlap = $this->intersectAcross($members, 'responsibility_tags');
            $testOverlap = $this->intersectAcross($members, 'tests');

            $overlapScore = $this->overlapScore($sharedInputs, $sharedOutputs, $proofOverlap, $consumerOverlap, $responsibilityOverlap, $testOverlap);

            $falsePositiveRisks = [];
            if ($sharedInputs === [] && $sharedOutputs === []) {
                $falsePositiveRisks[] = 'capability_label_matches_but_no_shared_contracts';
            }
            if ($proofOverlap === []) {
                $falsePositiveRisks[] = 'no_proof_overlap';
            }
            if ($consumerOverlap === []) {
                $falsePositiveRisks[] = 'no_consumer_overlap';
            }
            if ($responsibilityOverlap === []) {
                $falsePositiveRisks[] = 'no_responsibility_overlap';
            }
            if ($testOverlap === []) {
                $falsePositiveRisks[] = 'no_test_overlap';
            }

            $mergeReady = $proofOverlap !== [] && $consumerOverlap !== [];

            // duplicate_confidence: behavior-signature based, never derived from name similarity —
            // it requires proof+consumer evidence (merge_ready) AND a high overlap_score.
            $duplicateConfidence = match (true) {
                $mergeReady && $overlapScore >= 0.80 => 'high',
                $mergeReady && $overlapScore >= 0.50 => 'medium',
                default => 'low',
            };

            $clusterId = 'cluster_'.substr(hash('sha256', $label.'|'.implode(',', $names)), 0, 16);

            $isWrapperBloat = $this->allMembersAreOneMethodWrappers($members);
            $decisionInputOverlap = $this->intersectAcross($members, 'decision_inputs');
            $isGateShardDuplicate = ! $isWrapperBloat
                && $this->allMembersAreGateShards($members)
                && $decisionInputOverlap !== [];

            $clusterType = match (true) {
                $isWrapperBloat => 'wrapper_bloat_cluster',
                $isGateShardDuplicate => 'gate_shard_duplicate',
                default => 'capability_overlap',
            };

            $keeperCandidate = null;
            $retirementCandidates = [];
            if ($isGateShardDuplicate) {
                [$keeperCandidate, $retirementCandidates] = $this->pickKeeperAndRetirements($members);
            }

            $clusters[] = [
                'cluster_id' => $clusterId,
                'capability_label' => $label,
                'members' => $names,
                'cluster_type' => $clusterType,
                'shared_contracts' => [
                    'inputs' => $sharedInputs,
                    'outputs' => $sharedOutputs,
                ],
                'shared_responsibility_tags' => $responsibilityOverlap,
                'shared_tests' => $testOverlap,
                'shared_decision_inputs' => $decisionInputOverlap,
                'overlap_score' => $overlapScore,
                'duplicate_confidence' => $duplicateConfidence,
                'merge_ready' => $mergeReady,
                'consolidation_reason' => sprintf(
                    '%d organs share capability "%s"%s',
                    count($members),
                    $label,
                    $mergeReady ? ' with proven proof and consumer overlap' : ' but lack proof and/or consumer overlap',
                ),
                'false_positive_risks' => $falsePositiveRisks,
                'keeper_candidate' => $keeperCandidate,
                'retirement_candidates' => $retirementCandidates,
            ];
        }

        usort($clusters, static fn (array $a, array $b): int => strcmp((string) $a['cluster_id'], (string) $b['cluster_id']));

        return [
            'schema' => self::SCHEMA,
            'clusters' => $clusters,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $members
     * @return list<string>
     */
    private function intersectAcross(array $members, string $field): array
    {
        $sets = array_map(
            static fn (array $o): array => array_values((array) ($o[$field] ?? [])),
            $members,
        );

        if ($sets === []) {
            return [];
        }

        $result = array_shift($sets);
        foreach ($sets as $set) {
            $result = array_intersect($result, $set);
        }

        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }

    /** @param  list<array<string,mixed>>  $members */
    private function allMembersAreOneMethodWrappers(array $members): bool
    {
        foreach ($members as $organ) {
            if (! array_key_exists('method_count', $organ) || (int) $organ['method_count'] !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<array<string,mixed>>  $members */
    private function allMembersAreGateShards(array $members): bool
    {
        foreach ($members as $organ) {
            if (($organ['is_gate_shard'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $members
     * @return array{0:string, 1:list<string>}
     */
    private function pickKeeperAndRetirements(array $members): array
    {
        $ranked = $members;
        usort($ranked, static function (array $a, array $b): int {
            $proofCountA = count((array) ($a['proof_refs'] ?? []));
            $proofCountB = count((array) ($b['proof_refs'] ?? []));
            if ($proofCountA !== $proofCountB) {
                return $proofCountB <=> $proofCountA;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $keeper = (string) ($ranked[0]['name'] ?? '');
        $retirements = array_values(array_map(
            static fn (array $o): string => (string) ($o['name'] ?? ''),
            array_slice($ranked, 1),
        ));
        sort($retirements, SORT_STRING);

        return [$keeper, $retirements];
    }

    /**
     * @param  list<string>  $sharedInputs
     * @param  list<string>  $sharedOutputs
     * @param  list<string>  $proofOverlap
     * @param  list<string>  $consumerOverlap
     * @param  list<string>  $responsibilityOverlap
     * @param  list<string>  $testOverlap
     */
    private function overlapScore(array $sharedInputs, array $sharedOutputs, array $proofOverlap, array $consumerOverlap, array $responsibilityOverlap, array $testOverlap): float
    {
        $dimensions = [$sharedInputs, $sharedOutputs, $proofOverlap, $consumerOverlap, $responsibilityOverlap, $testOverlap];
        $nonEmpty = count(array_filter($dimensions, static fn (array $d): bool => $d !== []));

        return round($nonEmpty / count($dimensions), 4);
    }
}
