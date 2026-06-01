<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LaneReconcileDecider;
use PHPUnit\Framework\TestCase;

final class LaneReconcileDeciderTest extends TestCase
{
    private LaneReconcileDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new LaneReconcileDecider();
    }

    public function test_equal_lane_proceeds(): void
    {
        $r = $this->decider->decide(['base_is_ancestor_of_lane' => true, 'lane_is_ancestor_of_base' => true, 'lane_only_commit_count' => 0]);

        $this->assertSame(LaneReconcileDecider::STATE_EQUAL, $r['lane_state']);
        $this->assertSame(LaneReconcileDecider::ACTION_PROCEED, $r['action']);
        $this->assertNull($r['blocker']);
    }

    public function test_ahead_lane_proceeds(): void
    {
        $r = $this->decider->decide(['base_is_ancestor_of_lane' => true, 'lane_is_ancestor_of_base' => false, 'lane_only_commit_count' => 3]);

        $this->assertSame(LaneReconcileDecider::STATE_AHEAD, $r['lane_state']);
        $this->assertSame(LaneReconcileDecider::ACTION_PROCEED, $r['action']);
    }

    public function test_behind_lane_refreshes_from_main(): void
    {
        $r = $this->decider->decide(['base_is_ancestor_of_lane' => false, 'lane_is_ancestor_of_base' => true, 'lane_only_commit_count' => 0]);

        $this->assertSame(LaneReconcileDecider::STATE_BEHIND, $r['lane_state']);
        $this->assertSame(LaneReconcileDecider::ACTION_REFRESH, $r['action']);
        $this->assertNull($r['blocker']);
    }

    public function test_diverged_lane_with_unpromoted_commits_blocks(): void
    {
        // The critical case: diverged AND unpromoted lane work => block, never
        // refresh (refresh would destroy the unpromoted commits) and never
        // spend provider or fake a merge.
        $r = $this->decider->decide(['base_is_ancestor_of_lane' => false, 'lane_is_ancestor_of_base' => false, 'lane_only_commit_count' => 2]);

        $this->assertSame(LaneReconcileDecider::STATE_DIVERGED, $r['lane_state']);
        $this->assertSame(LaneReconcileDecider::ACTION_BLOCK, $r['action']);
        $this->assertSame(LaneReconcileDecider::BLOCKER, $r['blocker']);
        $this->assertFalse($r['spends_provider']);
        $this->assertFalse($r['fakes_merge']);
    }

    public function test_diverged_lane_without_unpromoted_commits_safely_refreshes(): void
    {
        $r = $this->decider->decide(['base_is_ancestor_of_lane' => false, 'lane_is_ancestor_of_base' => false, 'lane_only_commit_count' => 0]);

        $this->assertSame(LaneReconcileDecider::STATE_DIVERGED, $r['lane_state']);
        $this->assertSame(LaneReconcileDecider::ACTION_REFRESH, $r['action']);
        $this->assertNull($r['blocker']);
    }
}
