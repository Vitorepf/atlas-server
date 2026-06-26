<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsSetupService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GUARANTEE regression (operator, 2026-06-25): the PHPUnit suite must NEVER be
 * able to create real `forge-rivals/*` branches or worktrees in the LIVE
 * atlas-server repo. A stray full-suite run once polluted the repo with dozens
 * of them. This test locks the fail-closed guard in AtlasForgeRivalsSetupService.
 */
final class AtlasForgeRivalsLiveRepoGuardTest extends TestCase
{
    public function test_provision_against_live_repo_is_blocked_and_creates_no_branches(): void
    {
        $before = $this->liveForgeRivalsBranchCount();

        $result = app(AtlasForgeRivalsSetupService::class)->provision([
            'repo_root' => base_path(),
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains(
            'forge_rivals_worktree_in_live_repo_forbidden_under_tests',
            $result['blockers'] ?? [],
        );

        $this->assertSame(
            $before,
            $this->liveForgeRivalsBranchCount(),
            'forge-rivals branches must never be created in the live repo under PHPUnit',
        );
    }

    public function test_isolated_temp_repo_is_not_blocked_by_the_live_repo_guard(): void
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'forge-rivals-guard-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0o755, true);
        (new Process(['git', 'init', '-q'], $tmp))->run();

        try {
            $result = app(AtlasForgeRivalsSetupService::class)->provision([
                'repo_root' => $tmp,
            ]);

            // The isolated repo may still be blocked for other reasons (empty repo
            // has no HEAD), but NEVER by the live-repo guard.
            $this->assertNotContains(
                'forge_rivals_worktree_in_live_repo_forbidden_under_tests',
                $result['blockers'] ?? [],
            );
        } finally {
            (new Process(['rm', '-rf', $tmp]))->run();
        }
    }

    private function liveForgeRivalsBranchCount(): int
    {
        $proc = new Process(['git', '-C', base_path(), 'for-each-ref', '--format=%(refname)', 'refs/heads/forge-rivals/']);
        $proc->run();
        $out = trim((string) $proc->getOutput());

        return $out === '' ? 0 : count(explode("\n", $out));
    }
}
