<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\SandboxCleanupSafetyDecider;
use PHPUnit\Framework\TestCase;

final class SandboxCleanupSafetyDeciderTest extends TestCase
{
    private SandboxCleanupSafetyDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new SandboxCleanupSafetyDecider();
    }

    public function test_contained_clean_unprotected_branch_is_removable(): void
    {
        $r = $this->decider->decide([
            'branch_contained_or_superseded' => true,
            'worktree_clean_or_ignored_only' => true,
            'is_controller' => false, 'is_main' => false, 'is_current_lane' => false, 'is_human_wip' => false,
        ]);

        $this->assertTrue($r['remove_allowed']);
        $this->assertFalse($r['branch_protected']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_controller_branch_is_never_removable_even_if_contained_and_clean(): void
    {
        $r = $this->decider->decide([
            'branch_contained_or_superseded' => true,
            'worktree_clean_or_ignored_only' => true,
            'is_controller' => true,
            'ref' => 'atlas/loop-runner/agentic-engineering-os-dev-forge',
        ]);

        $this->assertFalse($r['remove_allowed']);
        $this->assertTrue($r['branch_protected']);
        $this->assertContains(SandboxCleanupSafetyDecider::BLOCKER_PROTECTED, $r['blockers']);
    }

    public function test_main_lane_and_human_wip_are_protected(): void
    {
        foreach ([['is_main' => true], ['is_current_lane' => true], ['is_human_wip' => true]] as $flag) {
            $r = $this->decider->decide(array_merge([
                'branch_contained_or_superseded' => true,
                'worktree_clean_or_ignored_only' => true,
            ], $flag));
            $this->assertFalse($r['remove_allowed'], json_encode($flag));
            $this->assertTrue($r['branch_protected']);
        }
    }

    public function test_uncontained_branch_blocks_with_cleanup_plan(): void
    {
        $r = $this->decider->decide([
            'branch_contained_or_superseded' => false,
            'worktree_clean_or_ignored_only' => true,
        ]);

        $this->assertFalse($r['remove_allowed']);
        $this->assertContains(SandboxCleanupSafetyDecider::BLOCKER_NOT_CONTAINED, $r['blockers']);
        $this->assertNotEmpty($r['cleanup_plan']);
    }

    public function test_dirty_worktree_blocks(): void
    {
        $r = $this->decider->decide([
            'branch_contained_or_superseded' => true,
            'worktree_clean_or_ignored_only' => false,
        ]);

        $this->assertFalse($r['remove_allowed']);
        $this->assertContains(SandboxCleanupSafetyDecider::BLOCKER_WORKTREE_DIRTY, $r['blockers']);
    }

    public function test_human_wip_protection_dominates_when_in_doubt(): void
    {
        // Even with all "removable" proofs, human WIP on the ref blocks.
        $r = $this->decider->decide([
            'branch_contained_or_superseded' => true,
            'worktree_clean_or_ignored_only' => true,
            'is_human_wip' => true,
        ]);

        $this->assertFalse($r['remove_allowed']);
        $this->assertTrue($r['branch_protected']);
    }
}
