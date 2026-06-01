<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Lane auto-reconcile guard (operator mandate, 2026-05-31). After a commit lands
 * on main, the integration lane (atlas/integration/<area>/main) can DIVERGE from
 * main. A diverged lane must never make the loop spend provider budget or fake a
 * merge — it must be reconciled (or blocked) deterministically FIRST.
 *
 * PURE: the caller computes the three git facts (the {@see StewardshipIntegrationLaneService}
 * already runs the ancestry checks; lane_only_commit_count is the RIGHT side of
 * `git rev-list --left-right --count base...lane` — commits on the lane NOT
 * reachable from base, i.e. unpromoted lane work). This class only decides.
 *
 * lane_state x action:
 *   - equal   (base==lane)                          -> proceed
 *   - ahead   (base ancestor of lane, lane_only>0)  -> proceed (normal promotable lane)
 *   - behind  (lane ancestor of base, lane_only==0) -> refresh_lane_from_main (FF, nothing lost)
 *   - diverged + lane_only==0                        -> refresh_lane_from_main (no unique commits to lose)
 *   - diverged + lane_only>0                         -> BLOCK lane_reconcile_required (useful unpromoted work)
 *
 * Refresh is only ever authorized when lane_only_commit_count===0, so it can
 * never discard unpromoted lane commits. Any doubt (unpromoted commits present)
 * blocks rather than recreates.
 */
final class LaneReconcileDecider
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.lane_reconcile_decision.v1';

    public const BLOCKER = 'lane_reconcile_required';

    public const STATE_EQUAL = 'equal';

    public const STATE_AHEAD = 'ahead';

    public const STATE_BEHIND = 'behind';

    public const STATE_DIVERGED = 'diverged';

    public const ACTION_PROCEED = 'proceed';

    public const ACTION_REFRESH = 'refresh_lane_from_main';

    public const ACTION_BLOCK = 'block';

    /**
     * @param  array<string,mixed>  $input  {base_is_ancestor_of_lane:bool, lane_is_ancestor_of_base:bool, lane_only_commit_count:int}
     * @return array{schema_version:string, lane_state:string, action:string, blocker:string|null, lane_only_commit_count:int, spends_provider:bool, fakes_merge:bool, reason:string}
     */
    public function decide(array $input): array
    {
        $baseIsAncestorOfLane = (bool) ($input['base_is_ancestor_of_lane'] ?? false);
        $laneIsAncestorOfBase = (bool) ($input['lane_is_ancestor_of_base'] ?? false);
        $laneOnly = max(0, (int) ($input['lane_only_commit_count'] ?? 0));

        if ($baseIsAncestorOfLane && $laneIsAncestorOfBase) {
            return $this->result(self::STATE_EQUAL, self::ACTION_PROCEED, null, $laneOnly, 'lane_equals_base');
        }
        if ($baseIsAncestorOfLane && ! $laneIsAncestorOfBase) {
            return $this->result(self::STATE_AHEAD, self::ACTION_PROCEED, null, $laneOnly, 'lane_ahead_of_base_promotable');
        }
        if (! $baseIsAncestorOfLane && $laneIsAncestorOfBase) {
            // Lane strictly behind base: no unique lane commits — safe to fast-forward.
            return $this->result(self::STATE_BEHIND, self::ACTION_REFRESH, null, $laneOnly, 'lane_behind_base_safe_refresh');
        }

        // Diverged: neither is an ancestor of the other.
        if ($laneOnly === 0) {
            return $this->result(self::STATE_DIVERGED, self::ACTION_REFRESH, null, $laneOnly, 'lane_diverged_without_unpromoted_commits_safe_refresh');
        }

        return $this->result(self::STATE_DIVERGED, self::ACTION_BLOCK, self::BLOCKER, $laneOnly, 'lane_diverged_with_'.$laneOnly.'_unpromoted_commits_reconcile_required');
    }

    /**
     * @return array{schema_version:string, lane_state:string, action:string, blocker:string|null, lane_only_commit_count:int, spends_provider:bool, fakes_merge:bool, reason:string}
     */
    private function result(string $state, string $action, ?string $blocker, int $laneOnly, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'lane_state' => $state,
            'action' => $action,
            'blocker' => $blocker,
            'lane_only_commit_count' => $laneOnly,
            'spends_provider' => false,
            'fakes_merge' => false,
            'reason' => $reason,
        ];
    }
}
