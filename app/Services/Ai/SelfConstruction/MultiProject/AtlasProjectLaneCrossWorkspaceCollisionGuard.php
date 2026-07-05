<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure guard that detects cross-workspace target collisions before multi-project
 * originator batches are admitted.
 *
 * Rules:
 *   - Same relative target in DIFFERENT lanes → separated (allowed, different workspaces)
 *   - Same target in the SAME lane → blocked (duplicate within one lane)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasProjectLaneCrossWorkspaceCollisionGuard
{
    public const SCHEMA = 'atlas.project_lane.cross_workspace_collision_guard.v1';

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    public function guard(array $proposals): array
    {
        $blocked = [];
        $separated = [];
        $admitted = [];

        // Group by lane + target to detect same-lane duplicates.
        $laneTargets = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $laneId = (string) ($proposal['lane_id'] ?? '');
            $targetFamily = (string) ($proposal['target_family'] ?? '');
            $proposalId = (string) ($proposal['proposal_id'] ?? '');

            if ($laneId === '' || $targetFamily === '') {
                continue;
            }

            $key = $laneId.':'.$targetFamily;
            if (isset($laneTargets[$key])) {
                // Same lane + same target → blocked (duplicate).
                $blocked[] = [
                    'proposal_id' => $proposalId,
                    'lane_id' => $laneId,
                    'target_family' => $targetFamily,
                    'reason' => 'same_lane_duplicate_target',
                ];
            } else {
                $laneTargets[$key] = true;
                $admitted[] = $proposal;
            }
        }

        // Check for cross-workspace (same target, different lanes) → separated.
        $targetLanes = [];
        foreach ($admitted as $proposal) {
            $targetFamily = (string) ($proposal['target_family'] ?? '');
            $laneId = (string) ($proposal['lane_id'] ?? '');
            $targetLanes[$targetFamily][] = $laneId;
        }
        foreach ($targetLanes as $targetFamily => $lanes) {
            if (count(array_unique($lanes)) > 1) {
                $separated[] = [
                    'target_family' => $targetFamily,
                    'lanes' => array_values(array_unique($lanes)),
                    'reason' => 'cross_workspace_same_target_separated',
                ];
            }
        }

        $hasCollisions = $blocked !== [];

        return [
            'schema_version' => self::SCHEMA,
            'passed' => ! $hasCollisions,
            'blocked' => $blocked,
            'separated' => $separated,
            'admitted_count' => count($admitted),
            'blocked_count' => count($blocked),
            'separated_count' => count($separated),
        ];
    }
}
