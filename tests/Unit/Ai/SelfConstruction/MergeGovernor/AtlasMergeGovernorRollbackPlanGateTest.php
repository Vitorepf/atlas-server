<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MergeGovernor;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRollbackPlanGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMergeGovernorRollbackPlanGate: complete plan ⇒ conformant=true; missing restore_strategy
 * yields missing_restore_strategy; missing post-rollback verification yields
 * missing_verification_after_rollback; an affected file outside the lane roots yields
 * affected_file_outside_lane:<path>; blockers are deterministically sorted.
 */
final class AtlasMergeGovernorRollbackPlanGateTest extends TestCase
{
    private function validPlan(): array
    {
        return [
            'affected_files' => ['app/Demo/Helper.php'],
            'restore_strategy' => 'revert_commit',
            'verification_after_rollback' => ['phpunit tests/Unit/Demo/HelperTest.php'],
            'owner_scope' => 'demo',
            'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['app/Demo']],
        ];
    }

    public function test_valid_plan_is_conformant_true_with_zero_blockers(): void
    {
        $r = (new AtlasMergeGovernorRollbackPlanGate)->evaluate($this->validPlan());
        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_missing_restore_strategy_yields_named_blocker(): void
    {
        $p = $this->validPlan();
        $p['restore_strategy'] = '';
        $r = (new AtlasMergeGovernorRollbackPlanGate)->evaluate($p);
        $this->assertFalse($r['conformant']);
        $this->assertContains('missing_restore_strategy', $r['blockers']);
    }

    public function test_missing_verification_after_rollback_yields_named_blocker(): void
    {
        $p = $this->validPlan();
        $p['verification_after_rollback'] = [];
        $r = (new AtlasMergeGovernorRollbackPlanGate)->evaluate($p);
        $this->assertContains('missing_verification_after_rollback', $r['blockers']);
    }

    public function test_affected_file_outside_lane_yields_named_blocker(): void
    {
        $p = $this->validPlan();
        $p['affected_files'] = ['/etc/passwd'];
        $r = (new AtlasMergeGovernorRollbackPlanGate)->evaluate($p);
        $this->assertContains('affected_file_outside_lane:/etc/passwd', $r['blockers']);
    }

    public function test_owner_scope_lane_mismatch_yields_named_blocker(): void
    {
        $p = $this->validPlan();
        $p['owner_scope'] = 'OTHER';
        $r = (new AtlasMergeGovernorRollbackPlanGate)->evaluate($p);
        $this->assertContains('owner_scope_lane_mismatch:OTHER!=demo', $r['blockers']);
    }

    public function test_blockers_are_deterministically_sorted(): void
    {
        $p = [
            'affected_files' => ['/etc/passwd'],
            'restore_strategy' => '',
            'verification_after_rollback' => [],
            'owner_scope' => '',
            'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['app/Demo']],
        ];
        $r = (new AtlasMergeGovernorRollbackPlanGate)->evaluate($p);
        $sorted = $r['blockers'];
        $copy = $sorted;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $sorted, 'blockers are sorted');
    }
}
