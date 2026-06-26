<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Support;

use App\Services\Ai\AutonomousEvolution\Support\AtlasLoopObraGitWorktreeProbe;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive stateless git probe extracted from AtlasLoopObraExecutionAdapter.
 *
 * The probe is the only place the adapter shells out to `git -C <repo>` for the obra replay-worktree
 * lanes (aggregate-drop, net-diff full cert, node-interface contract, changed-symbol census). Its
 * contract is byte-identical to the previous private methods on the god-class, so these tests must:
 *  (a) prove success / failure routing for `git` (bool),
 *  (b) prove output capture for `gitOutput` (string|null on failure),
 *  (c) prove the line-splitting filter on `gitLines` (empty lines dropped, whitespace trimmed),
 *  (d) prove the methods compose correctly via a REAL git invocation against a temp repo
 *      (so any regression in `git -C` plumbing / timeout / cwd handling is caught).
 *
 * No DB, no Laravel container — pure core, hang-free.
 */
final class AtlasLoopObraGitWorktreeProbeTest extends TestCase
{
    private string $tmpRepo;

    private AtlasLoopObraGitWorktreeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRepo = sys_get_temp_dir().'/atlas-obra-git-probe-'.bin2hex(random_bytes(5));
        if (! mkdir($this->tmpRepo, 0o755, true) && ! is_dir($this->tmpRepo)) {
            $this->markTestSkipped('Could not create temp repo dir at '.$this->tmpRepo);
        }
        $this->probe = new AtlasLoopObraGitWorktreeProbe;
    }

    protected function tearDown(): void
    {
        // Clean up the temp git repo best-effort. We untracked-empty the worktree before removal
        // so `git -C <root> worktree remove` is a no-op (no worktrees were added by these tests).
        $this->rmRecursive($this->tmpRepo);
        parent::tearDown();
    }

    public function test_git_returns_true_on_successful_invocation_and_false_on_failure(): void
    {
        $this->initRepo();

        $ok = $this->probe->git($this->tmpRepo, ['status', '--porcelain']);
        $this->assertTrue($ok, '`git status --porcelain` against an empty repo must succeed');

        // Unknown subcommand => exit code != 0 => isSuccessful false.
        $bad = $this->probe->git($this->tmpRepo, ['no-such-git-subcommand-asdf']);
        $this->assertFalse($bad, 'unknown git subcommand must report false');
    }

    public function test_gitOutput_returns_string_on_success_and_null_on_failure(): void
    {
        $this->initRepo();
        $this->writeFile('hello.txt', "line one\nline two\n");

        $out = $this->probe->gitOutput($this->tmpRepo, ['status', '--porcelain']);
        $this->assertIsString($out);
        $this->assertStringContainsString('hello.txt', $out);

        $null = $this->probe->gitOutput($this->tmpRepo, ['no-such-git-subcommand-asdf']);
        $this->assertNull($null, 'failed invocation must surface as null');
    }

    public function test_gitLines_splits_output_drops_empty_lines_and_trims(): void
    {
        $this->initRepo();
        $this->writeFile('a.txt', '');
        $this->writeFile('b.txt', '');

        // `git ls-files` emits one path per line — perfect line-split input.
        $lines = $this->probe->gitLines($this->tmpRepo, ['ls-files']);
        sort($lines);
        $this->assertSame(['a.txt', 'b.txt'], $lines, 'lines must be trimmed + non-empty');
    }

    public function test_gitLines_returns_empty_array_on_failure(): void
    {
        // No `git init` — but `git -C <bad>` still runs; the subcommand we run is the failure case
        // (unknown subcommand). gitOutput returns null => gitLines returns [].
        $lines = $this->probe->gitLines($this->tmpRepo, ['no-such-git-subcommand-asdf']);
        $this->assertSame([], $lines, 'failure path must yield []');
    }

    public function test_methods_run_with_the_given_repo_root_and_do_not_share_state(): void
    {
        $this->initRepo();
        $this->writeFile('one.txt', 'one');

        // Second invocation on a separate repo must not see the first repo's file. We do NOT init
        // the second repo, so `git status` fails there; the first repo still succeeds.
        $otherRepo = sys_get_temp_dir().'/atlas-obra-git-probe-other-'.bin2hex(random_bytes(5));
        mkdir($otherRepo, 0o755, true);
        try {
            $first = $this->probe->gitOutput($this->tmpRepo, ['ls-files']);
            $other = $this->probe->gitOutput($otherRepo, ['ls-files']);
            $this->assertIsString($first);
            $this->assertStringContainsString('one.txt', $first);
            $this->assertNull($other, 'un-initialized second repo must yield null');
        } finally {
            $this->rmRecursive($otherRepo);
        }
    }

    private function initRepo(): void
    {
        // Real `git init` so `git -C` has something to run against.
        $p = new \Symfony\Component\Process\Process(['git', 'init', '-q'], $this->tmpRepo, null, null, 30.0);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->markTestSkipped('git init failed: '.trim($p->getErrorOutput()));
        }
        // Configure a committer so any future commit (not used in these tests) would not bail.
        $this->runGit(['config', 'user.email', 'probe@example.invalid']);
        $this->runGit(['config', 'user.name', 'Atlas Obra Git Probe Test']);
    }

    private function writeFile(string $name, string $contents): void
    {
        file_put_contents($this->tmpRepo.'/'.$name, $contents);
        // Stage so `git ls-files` / `git status --porcelain` surface it deterministically.
        $this->runGit(['add', '--', $name]);
    }

    /** @param  list<string>  $argv */
    private function runGit(array $argv): void
    {
        $p = new \Symfony\Component\Process\Process(array_merge(['git', '-C', $this->tmpRepo], $argv), null, null, null, 30.0);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->markTestSkipped('git '.implode(' ', $argv).' failed: '.trim($p->getErrorOutput()));
        }
    }

    private function rmRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $node) {
            /** @var \SplFileInfo $node */
            if ($node->isDir()) {
                @rmdir($node->getPathname());
            } else {
                @unlink($node->getPathname());
            }
        }
        @rmdir($path);
    }
}
