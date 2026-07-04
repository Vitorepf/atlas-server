<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure advisor: given organ capability maps with overlapping decisions, proposes
 * a safe single-owner collapse plan.
 *
 * Refuses collapse when:
 *   - shared decision count < MIN_SEMANTIC_OVERLAP (lexical/insufficient overlap)
 *   - consumer sets are non-empty and fully disjoint (consumers_differ_materially)
 *   - evidence_strength delta < MIN_EVIDENCE_DELTA (no canonical owner with stronger proof)
 *
 * Collapse candidate output: canonical_owner, absorbed_organs, preserved_behaviors,
 * risk_level, required_tests, migration_notes, deleted_responsibilities.
 */
final class AtlasExternalBrainRedundancyCollapseAdvisor
{
    public const SCHEMA = 'atlas.external_brain.redundancy_collapse_advisor.v1';

    /** Minimum exact-match shared decisions required to consider overlap semantic. */
    private const MIN_SEMANTIC_OVERLAP = 2;

    /** Minimum evidence_strength advantage the canonical owner must have. */
    private const MIN_EVIDENCE_DELTA = 0.5;

    /**
     * @param  list<array<string,mixed>>  $organMaps  each entry: organ_id, decisions, inputs, outputs, consumers, behaviors, evidence_strength
     * @return array<string,mixed>
     */
    public function advise(array $organMaps): array
    {
        $candidates = [];
        $refused = [];
        $n = count($organMaps);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $result = $this->evaluatePair($organMaps[$i], $organMaps[$j]);
                if ($result['collapse_safe']) {
                    $candidates[] = $result['candidate'];
                } else {
                    $refused[] = $result['refusal'];
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'collapse_candidates' => $candidates,
            'merge_candidates' => array_values(array_filter(
                $candidates,
                static fn (array $c): bool => ($c['recommendation'] ?? '') === 'merge',
            )),
            'refused_collapses' => $refused,
            'candidate_count' => count($candidates),
            'redundancy_clusters' => $this->findRedundancyClusters($organMaps),
        ];
    }

    /**
     * Detects clusters of 3+ organs that ALL pairwise share the same decision surface (real
     * semantic overlap, not lexical-only) and compatible consumers. A cluster requires every
     * pair inside it to independently pass this check (a clique) — one weak/lexical pair
     * anywhere in the group means no cluster forms, even if other pairs in the group are strong.
     *
     * @param  list<array<string,mixed>>  $organMaps
     * @return list<array<string,mixed>>
     */
    private function findRedundancyClusters(array $organMaps): array
    {
        $n = count($organMaps);
        if ($n < 3) {
            return [];
        }

        // Adjacency: true only for pairs with real semantic overlap + compatible consumers.
        $adjacent = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $adjacent[$i][$j] = $adjacent[$j][$i] = $this->hasStrongSharedSurface($organMaps[$i], $organMaps[$j]);
            }
        }

        // Union-Find to group connected organs, then keep only components that are full cliques
        // (every pair inside independently adjacent) — a merely-connected but inconsistent group
        // never becomes a single redundancy cluster.
        $parent = range(0, $n - 1);
        $find = function (int $x) use (&$find, &$parent): int {
            return $parent[$x] === $x ? $x : $parent[$x] = $find($parent[$x]);
        };
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($adjacent[$i][$j]) {
                    $rootI = $find($i);
                    $rootJ = $find($j);
                    if ($rootI !== $rootJ) {
                        $parent[$rootI] = $rootJ;
                    }
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $groups[$find($i)][] = $i;
        }

        $clusters = [];
        foreach ($groups as $memberIndexes) {
            if (count($memberIndexes) < 3) {
                continue;
            }

            $isClique = true;
            foreach ($memberIndexes as $i) {
                foreach ($memberIndexes as $j) {
                    if ($i < $j && ! $adjacent[$i][$j]) {
                        $isClique = false;
                        break 2;
                    }
                }
            }
            if (! $isClique) {
                continue;
            }

            $clusters[] = $this->buildCluster(array_map(fn (int $idx): array => $organMaps[$idx], $memberIndexes));
        }

        return $clusters;
    }

    private function hasStrongSharedSurface(array $a, array $b): bool
    {
        $aDecisions = array_values(array_map('strval', (array) ($a['decisions'] ?? [])));
        $bDecisions = array_values(array_map('strval', (array) ($b['decisions'] ?? [])));
        $shared = array_values(array_intersect($aDecisions, $bDecisions));

        if (count($shared) < self::MIN_SEMANTIC_OVERLAP) {
            return false;
        }
        if (count($shared) === 1 && strlen($shared[0]) < 8) {
            return false;
        }

        $aConsumers = array_values(array_map('strval', (array) ($a['consumers'] ?? [])));
        $bConsumers = array_values(array_map('strval', (array) ($b['consumers'] ?? [])));
        if ($aConsumers !== [] && $bConsumers !== [] && array_intersect($aConsumers, $bConsumers) === []) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $members  3+ organ maps forming a verified clique.
     * @return array<string,mixed>
     */
    private function buildCluster(array $members): array
    {
        usort($members, static fn (array $a, array $b): float =>
            (float) ($b['evidence_strength'] ?? 0.0) <=> (float) ($a['evidence_strength'] ?? 0.0)
        );
        $owner = $members[0];
        $absorbed = array_slice($members, 1);

        $ownerId = (string) ($owner['organ_id'] ?? '');
        $ownerConsumers = array_values(array_map('strval', (array) ($owner['consumers'] ?? [])));

        $preserved = array_values(array_map('strval', (array) ($owner['behaviors'] ?? [])));
        $absorbedOrganIds = [];
        $consumerMigrationNotes = [];
        $deletionBlockers = [];

        foreach ($absorbed as $organ) {
            $absorbedId = (string) ($organ['organ_id'] ?? '');
            $absorbedOrganIds[] = $absorbedId;
            $preserved = array_merge($preserved, array_map('strval', (array) ($organ['behaviors'] ?? [])));

            $consumerMigrationNotes[] = "Route all {$absorbedId} callers to {$ownerId}";

            $absorbedConsumers = array_values(array_map('strval', (array) ($organ['consumers'] ?? [])));
            $exclusiveConsumers = array_values(array_diff($absorbedConsumers, $ownerConsumers));
            if ($exclusiveConsumers !== []) {
                $deletionBlockers[] = "exclusive_consumer_migration_required:{$absorbedId}";
            }
        }

        $preserved = array_values(array_unique($preserved));
        sort($preserved, SORT_STRING);
        $deletionBlockers = array_values(array_unique($deletionBlockers));
        sort($deletionBlockers, SORT_STRING);

        $requiredBehaviorTests = array_values(array_map(
            static fn (string $beh): string => 'test_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($beh)).'_preserved',
            $preserved,
        ));

        return [
            'canonical_owner'          => $ownerId,
            'canonical_owner_reason'   => 'highest_evidence_strength_in_cluster',
            'absorbed_organs'          => $absorbedOrganIds,
            'preserved_behaviors'      => $preserved,
            'required_behavior_tests' => $requiredBehaviorTests,
            'deletion_blockers'        => $deletionBlockers,
            'consumer_migration_notes' => $consumerMigrationNotes,
        ];
    }

    private function evaluatePair(array $a, array $b): array
    {
        $aId = (string) ($a['organ_id'] ?? '');
        $bId = (string) ($b['organ_id'] ?? '');
        $aDecisions = array_values(array_map('strval', (array) ($a['decisions'] ?? [])));
        $bDecisions = array_values(array_map('strval', (array) ($b['decisions'] ?? [])));
        $aConsumers = array_values(array_map('strval', (array) ($a['consumers'] ?? [])));
        $bConsumers = array_values(array_map('strval', (array) ($b['consumers'] ?? [])));
        $aEvidence = (float) ($a['evidence_strength'] ?? 0.0);
        $bEvidence = (float) ($b['evidence_strength'] ?? 0.0);

        $sharedDecisions = array_values(array_intersect($aDecisions, $bDecisions));
        $sharedCount = count($sharedDecisions);

        if ($sharedCount < self::MIN_SEMANTIC_OVERLAP) {
            $reason = ($sharedCount === 1 && strlen($sharedDecisions[0] ?? '') < 8)
                ? 'lexical_overlap_only'
                : 'insufficient_overlap';

            return ['collapse_safe' => false, 'refusal' => [
                'organ_pair'       => [$aId, $bId],
                'reason'           => $reason,
                'recommendation'   => 'keep_separate',
                'shared_decisions' => $sharedDecisions,
            ]];
        }

        $sharedConsumers = array_values(array_intersect($aConsumers, $bConsumers));
        if ($aConsumers !== [] && $bConsumers !== [] && $sharedConsumers === []) {
            return ['collapse_safe' => false, 'refusal' => [
                'organ_pair'       => [$aId, $bId],
                'reason'           => 'consumers_differ_materially',
                'recommendation'   => 'keep_separate',
                'shared_decisions' => $sharedDecisions,
            ]];
        }

        $delta = abs($aEvidence - $bEvidence);
        if ($delta < self::MIN_EVIDENCE_DELTA) {
            return ['collapse_safe' => false, 'refusal' => [
                'organ_pair'       => [$aId, $bId],
                'reason'           => 'no_canonical_owner_stronger_evidence',
                'recommendation'   => 'needs_more_evidence',
                'shared_decisions' => $sharedDecisions,
                'evidence_delta'   => $delta,
            ]];
        }

        [$owner, $absorbed] = $aEvidence >= $bEvidence ? [$a, $b] : [$b, $a];
        $ownerBehaviors = array_values(array_map('strval', (array) ($owner['behaviors'] ?? [])));
        $absorbedBehaviors = array_values(array_map('strval', (array) ($absorbed['behaviors'] ?? [])));
        $preserved = array_values(array_unique(array_merge($ownerBehaviors, $absorbedBehaviors)));
        sort($preserved, SORT_STRING);

        // Refuse when no behaviors to preserve — no behavior-preservation tests can be generated.
        if (empty($preserved)) {
            return ['collapse_safe' => false, 'refusal' => [
                'organ_pair'       => [$aId, $bId],
                'reason'           => 'no_behavior_preservation_tests',
                'recommendation'   => 'needs_more_evidence',
                'shared_decisions' => $sharedDecisions,
            ]];
        }

        $deletedResponsibilities = array_values(array_diff($absorbedBehaviors, $ownerBehaviors));
        $absorbedId = (string) ($absorbed['organ_id'] ?? '');
        $ownerId = (string) ($owner['organ_id'] ?? '');

        $absorbedExclusiveConsumers = array_values(array_diff(
            array_values(array_map('strval', (array) ($absorbed['consumers'] ?? []))),
            array_values(array_map('strval', (array) ($owner['consumers'] ?? []))),
        ));

        $requiredTests = array_values(array_map(
            static fn (string $beh): string => 'test_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($beh)).'_preserved',
            $preserved,
        ));

        $deletionBlockers = [];
        if ($absorbedExclusiveConsumers !== []) {
            $deletionBlockers[] = 'exclusive_consumer_migration_required';
        }
        if ($deletedResponsibilities !== []) {
            $deletionBlockers[] = 'behavior_gap_in_owner';
        }

        $evidenceDelta = abs($aEvidence - $bEvidence);
        $overlapRatio  = $sharedCount / max(1, max(count($aDecisions), count($bDecisions)));
        $collapseConfidence = round(min(1.0, ($evidenceDelta / 10.0) * 0.6 + $overlapRatio * 0.4), 3);

        $recommendation = $deletionBlockers === [] ? 'retire' : 'merge';

        return ['collapse_safe' => true, 'candidate' => [
            'canonical_owner'          => $ownerId,
            'canonical_owner_reason'   => 'higher_evidence_strength',
            'target_primary'           => $ownerId,
            'absorbed_organs'          => [$absorbedId],
            'retire_candidates'        => [$absorbedId],
            'recommendation'           => $recommendation,
            'shared_decisions'         => $sharedDecisions,
            'preserved_behaviors'      => $preserved,
            'deleted_responsibilities' => $deletedResponsibilities,
            'migration_notes'          => "Route all {$absorbedId} callers to {$ownerId}",
            'risk_level'               => $this->riskLevel(count($deletedResponsibilities), count($absorbedExclusiveConsumers)),
            'parity_risk'              => $this->parityRisk($deletedResponsibilities, $deletionBlockers),
            'required_tests'           => $requiredTests,
            'required_behavior_tests'  => $requiredTests,
            'deletion_blockers'        => $deletionBlockers,
            'collapse_confidence'      => $collapseConfidence,
        ]];
    }

    private function riskLevel(int $deletedCount, int $exclusiveConsumerCount): string
    {
        if ($exclusiveConsumerCount > 2 || $deletedCount > 3) {
            return 'high';
        }
        if ($exclusiveConsumerCount > 0 || $deletedCount > 1) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  list<string>  $deletedResponsibilities
     * @param  list<string>  $deletionBlockers
     */
    private function parityRisk(array $deletedResponsibilities, array $deletionBlockers): string
    {
        if ($deletedResponsibilities === []) {
            return 'low';
        }

        return in_array('behavior_gap_in_owner', $deletionBlockers, true) ? 'high' : 'medium';
    }
}
