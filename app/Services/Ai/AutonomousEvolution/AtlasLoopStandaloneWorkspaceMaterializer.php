<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;

/**
 * §5.6 · ORPHAN-WIRING execution — the ISOLATION primitive that makes the grinder's orphan-wiring route safe
 * under CONCURRENT grinders. The executor does `git commit` (freeze the authored test) + `git stash` (Guard 4e's
 * was-orphan baseline). In a shared-`.git` worktree those operations touch the shared `refs/stash` — two parallel
 * orphan-wiring tasks could interleave and corrupt each other. This materializer instead snapshots the base's
 * CURRENT working tree into a STANDALONE git repo (its own `.git` ⇒ its own `refs/stash`/HEAD/index), so the
 * executor's git surgery is fully isolated from the base AND from every other concurrent task.
 *
 * It copies the working tree (NOT the base's `.git` history — cheap, and it captures uncommitted state the loop
 * may hold) and seeds a single baseline commit. Read-only w.r.t. the base; never mutates it. Fail-closed: null on
 * any error. discard() only ever removes a temp dir it created.
 */
final class AtlasLoopStandaloneWorkspaceMaterializer
{
    public function materialize(string $baseWorkspace): ?string
    {
        $baseWorkspace = rtrim($baseWorkspace, '/');
        if ($baseWorkspace === '' || ! is_dir($baseWorkspace)) {
            return null;
        }

        $ws = sys_get_temp_dir().'/atlas-orphan-ws-'.bin2hex(random_bytes(6));

        // Copy the WORKING TREE without the base's .git (independent repo; captures uncommitted state). rsync is
        // present on macOS/Linux; fall back to cp -R + rm .git when it is not.
        $copied = $this->run(['rsync', '-a', '--exclude', '.git', $baseWorkspace.'/', $ws.'/'], 300.0);
        if (! $copied || ! is_dir($ws)) {
            if (is_dir($ws)) {
                $this->run(['rm', '-rf', $ws], 60.0);
            }
            if (! $this->run(['cp', '-R', $baseWorkspace, $ws], 300.0) || ! is_dir($ws)) {
                return null;
            }
            $this->run(['rm', '-rf', $ws.'/.git'], 60.0); // drop the copied history => independent repo
        }

        // Seed a single baseline commit so the executor has a HEAD to stash against / commit the test onto.
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'add', '-A'],
            ['git', '-c', 'user.email=loop@atlas', '-c', 'user.name=loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'orphan-wiring baseline'],
        ] as $argv) {
            $this->run($argv, 120.0, $ws);
        }

        return is_dir($ws.'/.git') ? $ws : null;
    }

    public function discard(string $workspace): void
    {
        $workspace = rtrim($workspace, '/');
        if ($workspace !== '' && str_starts_with($workspace, rtrim(sys_get_temp_dir(), '/').'/atlas-orphan-ws-') && is_dir($workspace)) {
            $this->run(['rm', '-rf', $workspace], 60.0);
        }
    }

    private function run(array $argv, float $timeout, ?string $cwd = null): bool
    {
        $p = new Process($argv, $cwd, null, null, $timeout);
        $p->run();

        return $p->isSuccessful();
    }
}
