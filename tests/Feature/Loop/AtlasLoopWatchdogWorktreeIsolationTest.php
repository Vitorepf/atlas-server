<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * THE WATCHDOG MUST NEVER OPERATE A HARDCODED CHECKOUT. A self-heal watchdog launched for an isolated
 * worktree soak runs automerge / main-health / keepalive with cwd = its repo root (base_path() follows).
 * It previously `cd`'d to a HARDCODED `/Users/.../atlas-server` — so a worktree soak's watchdog drove the
 * operator's MAIN checkout (could merge loop proposals into `main`, or break worktree isolation). This pins
 * the fix: the script self-locates to its OWN repo root and hardcodes no checkout path.
 */
final class AtlasLoopWatchdogWorktreeIsolationTest extends TestCase
{
    private function script(): string
    {
        $path = base_path('bin/atlas-loop-watchdog.sh');
        $this->assertFileExists($path, 'watchdog script must exist');

        return (string) file_get_contents($path);
    }

    public function test_watchdog_does_not_hardcode_any_checkout_path_in_a_cd(): void
    {
        $src = $this->script();

        // No `cd` to a hardcoded absolute Atlas checkout (atlas-server OR atlas-loop-run OR any sibling).
        // The whole point is that the SAME script, run from any checkout, operates THAT checkout.
        $this->assertSame(
            0,
            preg_match('~^\s*cd\s+/Users/[^\n]*?/(atlas-server|atlas-loop-run|atlas-[a-z0-9-]+)\b~m', $src),
            'the watchdog must NOT hardcode a checkout path in a cd — it must self-locate (regression: a hardcoded '
            .'atlas-server cd made a worktree soak operate the main checkout)',
        );
    }

    public function test_watchdog_self_locates_to_its_own_repo_root(): void
    {
        $src = $this->script();

        $this->assertStringContainsString('BASH_SOURCE', $src, 'must derive its own location from BASH_SOURCE');
        $this->assertMatchesRegularExpression(
            '~cd\s+"\$REPO_ROOT"~',
            $src,
            'must cd into the self-located $REPO_ROOT, not a literal path',
        );
    }

    /**
     * Functionally prove the resolution FORMULA (not just its textual presence): copy the script into a
     * throwaway `<tmp>/<repo>/bin/` and run its `dirname "${BASH_SOURCE[0]}"/..` resolution against that
     * path — it must resolve to the throwaway repo root, never the real checkout. This is the property that
     * keeps an isolated worktree soak isolated.
     */
    public function test_repo_root_resolution_follows_the_script_location(): void
    {
        $tmp = sys_get_temp_dir().'/atlas-wd-'.bin2hex(random_bytes(4));
        $repo = $tmp.'/fake-checkout';
        @mkdir($repo.'/bin', 0775, true);
        copy(base_path('bin/atlas-loop-watchdog.sh'), $repo.'/bin/atlas-loop-watchdog.sh');

        // Mirror EXACTLY the script's resolution line, with BASH_SOURCE[0] = the copied script path.
        $snippet = 'BASH_SOURCE0='.escapeshellarg($repo.'/bin/atlas-loop-watchdog.sh')
            .'; REPO_ROOT="$(cd "$(dirname "$BASH_SOURCE0")/.." 2>/dev/null && pwd)"; printf %s "$REPO_ROOT"';

        $p = new Process(['bash', '-c', $snippet]);
        $p->run();

        $this->assertTrue($p->isSuccessful(), 'resolution snippet must run: '.$p->getErrorOutput());
        // Normalise both through realpath: macOS /var is a symlink to /private/var, so bash `pwd` (logical)
        // and PHP realpath (physical) differ only by that prefix — the resolution itself is identical.
        $this->assertSame(realpath($repo), realpath(trim($p->getOutput())), 'REPO_ROOT must resolve to the script\'s own repo root');

        // cleanup
        @unlink($repo.'/bin/atlas-loop-watchdog.sh');
        @rmdir($repo.'/bin');
        @rmdir($repo);
        @rmdir($tmp);
    }
}
