<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner that turns queued-target collision summaries into a denylist
 * plus safe alternate target families before an originator emits another batch.
 *
 * Input:
 *   collisions: list of { target_family, severity: 'critical'|'warning', reason, queued_task_ids }
 *   candidate_families: list of target families the originator is considering
 *
 * Output:
 *   denylist: target families with critical collisions (must not be originated)
 *   pivot_recommendations: target families with warnings (pivot recommended)
 *   eligible_families: candidate families not in denylist or pivot list
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainQueueCollisionAvoidancePlanner
{
    public const SCHEMA = 'atlas.external_brain.queue_collision_avoidance_planner.v1';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $collisions = (array) ($input['collisions'] ?? []);
        $candidateFamilies = array_values(array_filter(array_map(
            static fn ($f): string => trim((string) $f),
            (array) ($input['candidate_families'] ?? []),
        ), static fn (string $f): bool => $f !== ''));

        $denylist = [];
        $pivotRecommendations = [];
        $collisionReasons = [];

        foreach ($collisions as $collision) {
            if (! is_array($collision)) {
                continue;
            }

            $targetFamily = trim((string) ($collision['target_family'] ?? ''));
            $severity = strtolower(trim((string) ($collision['severity'] ?? '')));
            $reason = trim((string) ($collision['reason'] ?? ''));

            if ($targetFamily === '') {
                continue;
            }

            if ($severity === self::SEVERITY_CRITICAL) {
                $denylist[$targetFamily] = true;
                $collisionReasons[$targetFamily] = $reason;
            } elseif ($severity === self::SEVERITY_WARNING) {
                $pivotRecommendations[$targetFamily] = true;
                if (! isset($collisionReasons[$targetFamily])) {
                    $collisionReasons[$targetFamily] = $reason;
                }
            }
        }

        $denylist = array_keys($denylist);
        $pivotList = array_keys($pivotRecommendations);

        sort($denylist, SORT_STRING);
        sort($pivotList, SORT_STRING);

        // Eligible families: candidates not in denylist or pivot list.
        $blocked = array_flip(array_merge($denylist, $pivotList));
        $eligible = array_values(array_filter(
            $candidateFamilies,
            static fn (string $f): bool => ! isset($blocked[$f]),
        ));
        sort($eligible, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'denylist' => $denylist,
            'pivot_recommendations' => $pivotList,
            'eligible_families' => $eligible,
            'collision_reasons' => $collisionReasons,
            'total_collisions' => count($collisions),
            'denylist_count' => count($denylist),
            'pivot_count' => count($pivotList),
            'eligible_count' => count($eligible),
        ];
    }
}
