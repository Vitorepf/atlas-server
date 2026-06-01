<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CanonicalWorktreeWriteGuard;
use PHPUnit\Framework\TestCase;

final class CanonicalWorktreeWriteGuardTest extends TestCase
{
    private CanonicalWorktreeWriteGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new CanonicalWorktreeWriteGuard();
    }

    public function test_mutating_run_on_canonical_is_refused(): void
    {
        $r = $this->guard->decide(['on_canonical' => true, 'is_mutating' => true]);

        $this->assertSame(CanonicalWorktreeWriteGuard::DECISION_REFUSED, $r['decision']);
        $this->assertSame(CanonicalWorktreeWriteGuard::BLOCKER, $r['blocker']);
    }

    public function test_read_only_run_on_canonical_is_allowed(): void
    {
        $r = $this->guard->decide(['on_canonical' => true, 'is_mutating' => false]);

        $this->assertSame(CanonicalWorktreeWriteGuard::DECISION_ALLOWED, $r['decision']);
        $this->assertNull($r['blocker']);
    }

    public function test_mutating_run_on_canonical_with_explicit_opt_in_is_allowed(): void
    {
        $r = $this->guard->decide(['on_canonical' => true, 'is_mutating' => true, 'allow_canonical_worktree_write' => true]);

        $this->assertSame(CanonicalWorktreeWriteGuard::DECISION_ALLOWED, $r['decision']);
        $this->assertNull($r['blocker']);
        $this->assertTrue($r['allow_opt_in']);
    }

    public function test_mutating_run_on_dedicated_loop_worktree_is_allowed(): void
    {
        $r = $this->guard->decide(['on_canonical' => false, 'on_dedicated_loop_worktree' => true, 'is_mutating' => true]);

        $this->assertSame(CanonicalWorktreeWriteGuard::DECISION_ALLOWED, $r['decision']);
        $this->assertNull($r['blocker']);
    }

    public function test_unverified_topology_fails_safe_to_allowed(): void
    {
        // Neither canonical nor dedicated could be classified (degraded/non-git):
        // a mutating run is allowed-unenforced, never a fabricated block.
        $r = $this->guard->decide(['on_canonical' => false, 'on_dedicated_loop_worktree' => false, 'is_mutating' => true]);

        $this->assertSame(CanonicalWorktreeWriteGuard::DECISION_ALLOWED, $r['decision']);
        $this->assertNull($r['blocker']);
        $this->assertFalse($r['topology_verified']);
    }

    public function test_path_equality_derivation_when_on_canonical_not_supplied(): void
    {
        $r = $this->guard->decide(['repo_root' => '/x/canonical', 'canonical_root' => '/x/canonical', 'is_mutating' => true]);

        $this->assertSame(CanonicalWorktreeWriteGuard::DECISION_REFUSED, $r['decision']);
        $this->assertTrue($r['on_canonical']);
    }
}
