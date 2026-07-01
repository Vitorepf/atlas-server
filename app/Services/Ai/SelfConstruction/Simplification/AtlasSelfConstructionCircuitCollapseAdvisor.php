<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure advisor: recommends collapsing a group of small organs (Task Fabric, Control
 * Plane, Learning, Proof, ...) into one simpler circuit ONLY when their overlap is
 * high AND behavior equivalence has already been proven ready for the group.
 *
 * Either signal alone is not enough — high overlap with unproven behavior risks a
 * silent regression, and a proven-ready group with low overlap is not actually
 * redundant, so collapsing it would be simplification for its own sake.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionCircuitCollapseAdvisor
{
    public const SCHEMA = 'atlas.self_construction.circuit_collapse_advisor.v1';

    public const STATUS_READY = 'ready_for_simplification';

    public const ACTION_DELETE = 'delete';

    public const ACTION_MERGE = 'merge';

    public const ACTION_EXTRACT = 'extract';

    public const ACTION_KEEP = 'keep';

    public const ACTION_BLOCK = 'block';

    private const HIGH_OVERLAP_THRESHOLD = 0.70;

    private const COHESION_KEEP = 'keep';

    private const COHESION_SPLIT_OR_COLLAPSE = 'split_or_collapse';

    private const RISK_HIGH = 'high';

    /**
     * @param  list<array{
     *   group_id?: string,
     *   organs?: list<string>,
     *   overlap_score?: float,
     *   behavior_equivalence_status?: string,
     * }>  $groups
     * @return array{schema:string, recommendations:list<array<string,mixed>>}
     */
    public function advise(array $groups): array
    {
        $recommendations = [];

        foreach ($groups as $group) {
            $groupId = (string) ($group['group_id'] ?? '');
            $organs = array_values((array) ($group['organs'] ?? []));
            $overlapScore = max(0.0, min(1.0, (float) ($group['overlap_score'] ?? 0.0)));
            $behaviorStatus = (string) ($group['behavior_equivalence_status'] ?? '');

            $blockers = [];

            if (count($organs) < 2) {
                $blockers[] = 'fewer_than_two_organs';
            }

            if ($overlapScore < self::HIGH_OVERLAP_THRESHOLD) {
                $blockers[] = 'overlap_below_threshold:'.number_format($overlapScore, 2);
            }

            if ($behaviorStatus !== self::STATUS_READY) {
                $blockers[] = 'behavior_equivalence_not_ready';
            }

            $recommendations[] = [
                'group_id' => $groupId,
                'organs' => $organs,
                'overlap_score' => $overlapScore,
                'recommended' => $blockers === [],
                'blockers' => $blockers,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Combines boundary/cluster/cohesion/consumer-impact/parity/replay/rollback evidence
     * into a single delete/merge/extract/keep/block decision. A candidate is only ever
     * merged or deleted when capability parity, shadow replay promotion, and rollback
     * reversibility are ALL proven and consumer risk is not high — any missing proof
     * blocks the candidate instead of silently keeping or collapsing it.
     *
     * @param  array{
     *   boundary?: array{safe_to_collapse?: bool},
     *   cluster?: array{merge_ready?: bool, duplicate_confidence?: string},
     *   cohesion?: array{recommendation?: string},
     *   consumer_impact?: array{risk_level?: string},
     *   parity?: array{replacement_allowed?: bool},
     *   replay?: array{promotion_allowed?: bool},
     *   rollback?: array{reversible?: bool},
     * }  $evidence
     * @return array{schema:string, action:string, blockers:list<string>}
     */
    public function decide(array $evidence): array
    {
        $mergeReady = (bool) (($evidence['cluster'] ?? [])['merge_ready'] ?? false);
        $duplicateConfidence = (string) (($evidence['cluster'] ?? [])['duplicate_confidence'] ?? 'low');
        $cohesionRecommendation = (string) (($evidence['cohesion'] ?? [])['recommendation'] ?? '');
        $riskLevel = (string) (($evidence['consumer_impact'] ?? [])['risk_level'] ?? 'low');
        $replacementAllowed = (bool) (($evidence['parity'] ?? [])['replacement_allowed'] ?? false);
        $promotionAllowed = (bool) (($evidence['replay'] ?? [])['promotion_allowed'] ?? false);
        $reversible = (bool) (($evidence['rollback'] ?? [])['reversible'] ?? false);

        $blockers = [];
        if (! $replacementAllowed) {
            $blockers[] = 'capability_parity_not_proven';
        }
        if (! $promotionAllowed) {
            $blockers[] = 'shadow_replay_not_promoted';
        }
        if (! $reversible) {
            $blockers[] = 'rollback_not_reversible';
        }
        if ($riskLevel === self::RISK_HIGH) {
            $blockers[] = 'consumer_risk_high';
        }

        if ($mergeReady && $blockers === []) {
            $action = $duplicateConfidence === 'high' ? self::ACTION_DELETE : self::ACTION_MERGE;

            return ['schema' => self::SCHEMA, 'action' => $action, 'blockers' => []];
        }

        if ($blockers !== [] && ($mergeReady || $riskLevel === self::RISK_HIGH)) {
            return ['schema' => self::SCHEMA, 'action' => self::ACTION_BLOCK, 'blockers' => $blockers];
        }

        if (! $mergeReady && $cohesionRecommendation === self::COHESION_KEEP) {
            return ['schema' => self::SCHEMA, 'action' => self::ACTION_KEEP, 'blockers' => []];
        }

        if (! $mergeReady && $cohesionRecommendation === self::COHESION_SPLIT_OR_COLLAPSE) {
            return ['schema' => self::SCHEMA, 'action' => self::ACTION_EXTRACT, 'blockers' => []];
        }

        return [
            'schema' => self::SCHEMA,
            'action' => self::ACTION_BLOCK,
            'blockers' => $blockers !== [] ? $blockers : ['insufficient_evidence'],
        ];
    }
}
