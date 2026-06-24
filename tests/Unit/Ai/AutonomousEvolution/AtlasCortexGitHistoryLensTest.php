<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexGitHistoryLens;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use Tests\TestCase;

/**
 * Proves the git-history lens against a REAL tmp git fixture: commits-touching-file count, last_touched_sha,
 * authors, co_changed, churn. Plus fail-closed paths: git unavailable / path outside repo / no commits.
 * Config window override is also asserted.
 */
final class AtlasCortexGitHistoryLensTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = sys_get_temp_dir().'/atlas_cortex_gh_'.bin2hex(random_bytes(6));
        mkdir($this->repoRoot, 0775, true);
        $this->git('init -q -b main');
        $this->git('config user.email a@a');
        $this->git('config user.name AuthorA');

        // commit 1 — create foo.php
        file_put_contents($this->repoRoot.'/foo.php', "<?php\nfunction one(): int { return 1; }\n");
        $this->git('add foo.php');
        $this->git('commit -q -m "add foo"');

        // commit 2 — add another line to foo.php + create bar.php (co-changed)
        file_put_contents($this->repoRoot.'/foo.php', "<?php\nfunction one(): int { return 1; }\nfunction two(): int { return 2; }\n");
        file_put_contents($this->repoRoot.'/bar.php', "<?php\n// bar\n");
        $this->git('add foo.php bar.php');
        $this->git('commit -q -m "add two + bar"');

        // commit 3 — different author, edit foo.php with bar.php again
        $this->git('config user.email b@b');
        $this->git('config user.name AuthorB');
        file_put_contents($this->repoRoot.'/foo.php', "<?php\nfunction one(): int { return 1; }\nfunction two(): int { return 2; }\nfunction three(): int { return 3; }\n");
        file_put_contents($this->repoRoot.'/bar.php', "<?php\n// bar v2\n");
        $this->git('add foo.php bar.php');
        $this->git('commit -q -m "add three + bar v2"');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repoRoot)) {
            shell_exec('rm -rf '.escapeshellarg($this->repoRoot));
        }
        parent::tearDown();
    }

    private function git(string $cmd): void
    {
        shell_exec('cd '.escapeshellarg($this->repoRoot).' && git '.$cmd.' 2>&1');
    }

    private function lens(): AtlasCortexGitHistoryLens
    {
        return new AtlasCortexGitHistoryLens;
    }

    private function fooSubject(): CortexSubject
    {
        return new CortexSubject('subj-foo', 'php_source', [
            'file_path' => 'foo.php',
            'repo_root' => $this->repoRoot,
        ]);
    }

    public function test_emits_commit_count_authors_and_co_changed_for_real_repo(): void
    {
        $obs = $this->lens()->observe($this->fooSubject());

        $this->assertSame('githistory', $obs->lensId);
        $this->assertSame(3, $obs->facts['commit_count'], 'foo.php touched in 3 commits');
        $this->assertContains('AuthorA', $obs->facts['authors']);
        $this->assertContains('AuthorB', $obs->facts['authors']);
        $coNames = array_map(static fn (array $r): string => (string) $r['file'], (array) $obs->facts['co_changed']);
        $this->assertContains('bar.php', $coNames, 'bar.php co-changed twice with foo.php');
    }

    public function test_last_touched_sha_matches_real_head(): void
    {
        $obs = $this->lens()->observe($this->fooSubject());
        $expectedSha = trim((string) shell_exec('cd '.escapeshellarg($this->repoRoot).' && git rev-parse HEAD'));
        $this->assertSame($expectedSha, $obs->facts['last_touched_sha']);
        $this->assertNotEmpty($obs->facts['last_touched_at']);
    }

    public function test_churn_added_and_removed_are_positive_integers(): void
    {
        $obs = $this->lens()->observe($this->fooSubject());
        $this->assertIsInt($obs->facts['churn_added']);
        $this->assertIsInt($obs->facts['churn_removed']);
        $this->assertGreaterThan(0, $obs->facts['churn_added'], 'foo.php had additions across 3 commits');
    }

    public function test_fail_closed_when_repo_root_not_a_git_dir(): void
    {
        $bogus = sys_get_temp_dir().'/no_git_'.bin2hex(random_bytes(4));
        mkdir($bogus, 0775, true);

        $obs = $this->lens()->observe(new CortexSubject('subj-x', 'php_source', [
            'file_path' => 'whatever.php',
            'repo_root' => $bogus,
        ]));

        $this->assertContains('repo_root_not_a_git_dir', $obs->disagreementSignals);
        $this->assertSame(0, $obs->facts['commit_count']);

        @rmdir($bogus);
    }

    public function test_fail_closed_when_path_outside_repo(): void
    {
        $obs = $this->lens()->observe(new CortexSubject('subj-x', 'php_source', [
            'file_path' => '/etc/passwd',
            'repo_root' => $this->repoRoot,
        ]));

        $this->assertContains('path_outside_repo', $obs->disagreementSignals);
        $this->assertSame(0, $obs->facts['commit_count']);
    }

    public function test_fail_closed_when_git_runner_simulates_missing_git(): void
    {
        $lens = new AtlasCortexGitHistoryLens(static fn (string $r, array $args): array => ['exit' => 127, 'stdout' => '']);

        $obs = $lens->observe($this->fooSubject());

        $this->assertContains('git_unavailable', $obs->disagreementSignals);
        $this->assertSame(0, $obs->facts['commit_count']);
    }

    public function test_config_window_overrides_default_100(): void
    {
        config(['atlas.cortex.council.git_history_window' => 1]);

        $obs = $this->lens()->observe($this->fooSubject());

        $this->assertSame(1, $obs->facts['window']);
        $this->assertSame(1, $obs->facts['commit_count'], 'window=1 ⇒ only the most recent commit is read');

        config(['atlas.cortex.council.git_history_window' => 100]); // restore
    }
}
