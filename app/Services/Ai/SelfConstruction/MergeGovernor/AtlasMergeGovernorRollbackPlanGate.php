<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MergeGovernor;

/**
 * Pure gate that requires every Self-Construction release candidate to carry an EXECUTABLE rollback
 * plan before integration is considered.
 *
 * INPUT: rollback_plan FACTS:
 *   { affected_files:list<string>, restore_strategy:string, verification_after_rollback:list<string>,
 *     owner_scope:string, project_lane:{project_id:string, allowed_scope_roots:list<string>} }
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope: identical input ⇒ byte-identical output (blockers sorted).
 *   - NO scalar score.
 *   - conformant=true ONLY when: restore_strategy non-empty, affected_files non-empty,
 *     verification_after_rollback non-empty, owner_scope matches project_lane.project_id,
 *     every affected_file lives inside project_lane.allowed_scope_roots.
 */
final class AtlasMergeGovernorRollbackPlanGate
{
    public const SCHEMA = 'atlas.mergegovernor.rollback_plan_gate.v1';

    /**
     * @param  array{
     *     affected_files?:list<string>,
     *     restore_strategy?:string,
     *     verification_after_rollback?:list<string>,
     *     owner_scope?:string,
     *     project_lane?:array{project_id?:string, allowed_scope_roots?:list<string>}
     * }  $plan
     * @return array{schema:string, conformant:bool, blockers:list<string>, facts:array<string,mixed>}
     */
    public function evaluate(array $plan): array
    {
        $blockers = [];

        $strategy = trim((string) ($plan['restore_strategy'] ?? ''));
        if ($strategy === '') {
            $blockers[] = 'missing_restore_strategy';
        }

        $affected = is_array($plan['affected_files'] ?? null) ? array_values(array_map('strval', $plan['affected_files'])) : [];
        if ($affected === []) {
            $blockers[] = 'empty_affected_files';
        }

        $postCheck = is_array($plan['verification_after_rollback'] ?? null) ? array_values(array_map('strval', $plan['verification_after_rollback'])) : [];
        if ($postCheck === []) {
            $blockers[] = 'missing_verification_after_rollback';
        }

        $ownerScope = trim((string) ($plan['owner_scope'] ?? ''));
        $lane = is_array($plan['project_lane'] ?? null) ? $plan['project_lane'] : [];
        $laneProjectId = (string) ($lane['project_id'] ?? '');
        if ($ownerScope === '') {
            $blockers[] = 'missing_owner_scope';
        } elseif ($laneProjectId !== '' && $ownerScope !== $laneProjectId) {
            $blockers[] = 'owner_scope_lane_mismatch:'.$ownerScope.'!='.$laneProjectId;
        }

        $laneRoots = is_array($lane['allowed_scope_roots'] ?? null) ? array_map('strval', $lane['allowed_scope_roots']) : [];
        if ($laneRoots !== []) {
            foreach ($affected as $f) {
                if (! $this->insideAnyRoot($f, $laneRoots)) {
                    $blockers[] = 'affected_file_outside_lane:'.$f;
                }
            }
        } elseif ($affected !== []) {
            $blockers[] = 'missing_project_lane_scope_roots';
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'conformant' => $blockers === [],
            'blockers' => $blockers,
            'facts' => [
                'restore_strategy' => $strategy,
                'affected_file_count' => count($affected),
                'post_check_count' => count($postCheck),
                'owner_scope' => $ownerScope,
                'lane_project_id' => $laneProjectId,
            ],
        ];
    }

    /**
     * @param  list<string>  $laneRoots
     */
    private function insideAnyRoot(string $path, array $laneRoots): bool
    {
        foreach ($laneRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                return true;
            }
        }

        return false;
    }
}
