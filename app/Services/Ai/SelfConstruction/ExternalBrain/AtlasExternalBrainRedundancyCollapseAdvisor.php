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
            'refused_collapses' => $refused,
            'candidate_count' => count($candidates),
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
                'organ_pair' => [$aId, $bId],
                'reason' => $reason,
                'shared_decisions' => $sharedDecisions,
            ]];
        }

        $sharedConsumers = array_values(array_intersect($aConsumers, $bConsumers));
        if ($aConsumers !== [] && $bConsumers !== [] && $sharedConsumers === []) {
            return ['collapse_safe' => false, 'refusal' => [
                'organ_pair' => [$aId, $bId],
                'reason' => 'consumers_differ_materially',
                'shared_decisions' => $sharedDecisions,
            ]];
        }

        $delta = abs($aEvidence - $bEvidence);
        if ($delta < self::MIN_EVIDENCE_DELTA) {
            return ['collapse_safe' => false, 'refusal' => [
                'organ_pair' => [$aId, $bId],
                'reason' => 'no_canonical_owner_stronger_evidence',
                'shared_decisions' => $sharedDecisions,
                'evidence_delta' => $delta,
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

        return ['collapse_safe' => true, 'candidate' => [
            'canonical_owner'        => $ownerId,
            'canonical_owner_reason' => 'higher_evidence_strength',
            'absorbed_organs'        => [$absorbedId],
            'shared_decisions'       => $sharedDecisions,
            'preserved_behaviors'    => $preserved,
            'deleted_responsibilities' => $deletedResponsibilities,
            'migration_notes'        => "Route all {$absorbedId} callers to {$ownerId}",
            'risk_level'             => $this->riskLevel(count($deletedResponsibilities), count($absorbedExclusiveConsumers)),
            'required_tests'         => $requiredTests,
            'required_behavior_tests' => $requiredTests,
            'deletion_blockers'      => $deletionBlockers,
            'collapse_confidence'    => $collapseConfidence,
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
}
