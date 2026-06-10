<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Cleanup safety (operator mandate, 2026-05-31): a worktree/branch may be removed
 * ONLY with proof it is safe. In doubt: emit a cleanup_plan and BLOCK, never
 * delete. Protected refs (the loop controller branch, main, the current
 * integration lane, or anything carrying human WIP) are NEVER deletable — no
 * allow_* flag clears that protection (distinct from allow_dirty_removal /
 * allow_unmerged_branch_delete, which only relax the contained/clean proofs and
 * must NOT apply to protected refs).
 *
 * PURE: the caller establishes the proofs (containment in main/lane — NOT in a
 * possibly-stale HEAD; worktree cleanliness; ref identity). This class decides.
 *
 * remove_allowed === true  IFF  NOT protected
 *                               AND branch_contained_or_superseded === true
 *                               AND worktree_clean_or_ignored_only === true.
 */
final class SandboxCleanupSafetyDecider
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.sandbox_cleanup_safety.v1';

    public const BLOCKER_PROTECTED = 'cleanup_refused_protected_ref';

    public const BLOCKER_NOT_CONTAINED = 'cleanup_refused_branch_not_contained_or_superseded';

    public const BLOCKER_WORKTREE_DIRTY = 'cleanup_refused_worktree_not_clean';

    /**
     * @param  array<string,mixed>  $input  {branch_contained_or_superseded:bool, worktree_clean_or_ignored_only:bool, is_controller:bool, is_main:bool, is_current_lane:bool, is_human_wip:bool, ref?:string}
     * @return array{schema_version:string, remove_allowed:bool, branch_protected:bool, ref:string, blockers:list<string>, cleanup_plan:list<string>, reason:string}
     */
    public function decide(array $input): array
    {
        $contained = (bool) ($input['branch_contained_or_superseded'] ?? false);
        $worktreeClean = (bool) ($input['worktree_clean_or_ignored_only'] ?? false);
        $isController = (bool) ($input['is_controller'] ?? false);
        $isMain = (bool) ($input['is_main'] ?? false);
        $isCurrentLane = (bool) ($input['is_current_lane'] ?? false);
        $isHumanWip = (bool) ($input['is_human_wip'] ?? false);
        $ref = trim((string) ($input['ref'] ?? ''));

        $protected = $isController || $isMain || $isCurrentLane || $isHumanWip;

        $blockers = [];
        $cleanupPlan = [];

        // Protected refs are UN-overridable — checked first and independently.
        if ($protected) {
            $blockers[] = self::BLOCKER_PROTECTED;
            $which = array_keys(array_filter([
                'controller_branch' => $isController,
                'main' => $isMain,
                'current_integration_lane' => $isCurrentLane,
                'human_wip' => $isHumanWip,
            ]));
            $cleanupPlan[] = 'never_remove_protected_ref:'.implode('+', $which);
        }

        if (! $contained) {
            $blockers[] = self::BLOCKER_NOT_CONTAINED;
            $cleanupPlan[] = 'prove_branch_merged_or_contained_in_main_or_lane_before_removal';
        }
        if (! $worktreeClean) {
            $blockers[] = self::BLOCKER_WORKTREE_DIRTY;
            $cleanupPlan[] = 'commit_or_preserve_worktree_changes_before_removal';
        }

        $removeAllowed = ! $protected && $contained && $worktreeClean;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'remove_allowed' => $removeAllowed,
            'branch_protected' => $protected,
            'ref' => $ref,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'cleanup_plan' => AreaFocusStringListNormalizer::uniqueStringValues($cleanupPlan),
            'reason' => $removeAllowed
                ? 'safe_to_remove_contained_clean_unprotected'
                : ($protected ? 'protected_ref_never_removed' : 'cleanup_blocked_emit_plan_not_delete'),
        ];
    }
}
