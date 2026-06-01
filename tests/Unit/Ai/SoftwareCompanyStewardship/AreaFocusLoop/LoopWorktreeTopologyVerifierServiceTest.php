<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopWorktreeTopologyVerifierService;
use PHPUnit\Framework\TestCase;

final class LoopWorktreeTopologyVerifierServiceTest extends TestCase
{
    private LoopWorktreeTopologyVerifierService $verifier;

    protected function setUp(): void
    {
        $this->verifier = new LoopWorktreeTopologyVerifierService();
    }

    private function porcelain(): string
    {
        return implode("\n", [
            'worktree /repo/atlas-server',
            'HEAD aaaa',
            'branch refs/heads/main',
            '',
            'worktree /repo/atlas-server-loop-worktree',
            'HEAD bbbb',
            'branch refs/heads/atlas/loop-runner/agentic-engineering-os-dev-forge',
            '',
            'worktree /repo/atlas-server/storage/atlas/software_company_stewardship/area_focus_branch_sandboxes/worktrees/afsb_1',
            'HEAD cccc',
            'branch refs/heads/atlas/area-focus/agentic_engineering_os/atlas_dev/abc',
            '',
        ]);
    }

    public function test_is_canonical_checkout_true_for_main_working_tree(): void
    {
        $this->assertTrue($this->verifier->isCanonicalCheckout('/repo/atlas-server', ['worktree_list_porcelain' => $this->porcelain()]));
    }

    public function test_is_canonical_checkout_false_for_dedicated_loop_worktree(): void
    {
        $this->assertFalse($this->verifier->isCanonicalCheckout('/repo/atlas-server-loop-worktree', ['worktree_list_porcelain' => $this->porcelain()]));
    }

    public function test_degraded_non_git_fails_safe_to_not_canonical(): void
    {
        // Empty porcelain (non-git / degraded) => cannot classify => not canonical
        // => the single-writer guard ALLOWS rather than fabricating a block.
        $this->assertFalse($this->verifier->isCanonicalCheckout('/repo/atlas-server', ['worktree_list_porcelain' => '']));
    }

    public function test_verify_reports_topology_for_ap805(): void
    {
        $report = $this->verifier->verify([
            'repo_root' => '/repo/atlas-server',
            'worktree_list_porcelain' => $this->porcelain(),
            'status_porcelain' => '',
        ]);

        $this->assertSame(LoopWorktreeTopologyVerifierService::STATUS_VERIFIED, $report['status']);
        $this->assertTrue($report['is_canonical_checkout']);
        $this->assertTrue($report['canonical_clean']);
        $this->assertTrue($report['loop_worktree_present']);
        $this->assertSame('atlas/loop-runner/agentic-engineering-os-dev-forge', $report['loop_branch_ref']);
        $this->assertSame(1, $report['stale_count']); // the one sandbox worktree
        $this->assertNotEmpty($report['cleanup_plan']);
        $this->assertTrue($report['claim_policy']['read_only']);
    }

    public function test_verify_reports_dirty_canonical(): void
    {
        $report = $this->verifier->verify([
            'repo_root' => '/repo/atlas-server',
            'worktree_list_porcelain' => $this->porcelain(),
            'status_porcelain' => " M app/Foo.php",
        ]);

        $this->assertFalse($report['canonical_clean']);
    }

    public function test_verify_blocks_on_non_git(): void
    {
        $report = $this->verifier->verify(['repo_root' => '/repo/atlas-server', 'worktree_list_porcelain' => '']);

        $this->assertSame(LoopWorktreeTopologyVerifierService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['is_canonical_checkout']);
        $this->assertFalse($report['loop_worktree_present']);
    }
}
