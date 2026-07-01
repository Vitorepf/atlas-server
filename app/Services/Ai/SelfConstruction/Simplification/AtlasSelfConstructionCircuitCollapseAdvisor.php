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

    private const HIGH_OVERLAP_THRESHOLD = 0.70;

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
}
