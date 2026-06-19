<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * THE CYCLE GIT CONTRACT — frozen proof of the operator's mandate (2026-06-17):
 *   commit -> merge na main -> NOVA branch do próximo ciclo a partir da MAIN FRESCA.
 *
 * The load-bearing assertion is the NO-DRIFT INVARIANT: across N cycles, every new cycle branch is cut from
 * the CURRENT main HEAD (which contains every prior merge), so a freshly-started cycle is ALWAYS 0 commits
 * behind main. The 579-behind incident becomes structurally impossible.
 *
 * Real git, in a throwaway repo (no DB).
 */
final class AtlasLoopCycleGitContractTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-cyclegit-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0o755, true);
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 't@atlas']);
        $this->git(['config', 'user.name', 'test']);
        @file_put_contents($this->repo.'/README.md', "base\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'initial', '--no-gpg-sign']);
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
        parent::tearDown();
    }

    private function svc(): AtlasLoopCycleGitContract
    {
        return new AtlasLoopCycleGitContract;
    }

    private function git(array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $this->repo, null, null, 60.0);
        $p->run();

        return trim($p->getOutput());
    }

    private function mainHead(): string
    {
        return $this->git(['rev-parse', 'main']);
    }

    /** Run one full cycle that writes $file=$content; returns the contract results. */
    private function runCycle(AtlasLoopCycleGitContract $c, string $id, string $file, string $content): array
    {
        $start = $c->startCycle($this->repo, $id);
        $commit = $c->commitCycle($this->repo, (string) $start['branch'], 'cycle '.$id, function (string $wt) use ($file, $content): void {
            @file_put_contents($wt.'/'.$file, $content);
        });
        $merge = $c->mergeToMain($this->repo, (string) $start['branch']);

        return compact('start', 'commit', 'merge');
    }

    public function test_no_drift_invariant_across_three_cycles(): void
    {
        $c = $this->svc();

        foreach (['c1' => 'a.txt', 'c2' => 'b.txt', 'c3' => 'c.txt'] as $id => $file) {
            $mainBefore = $this->mainHead();

            $r = $this->runCycle($c, $id, $file, "from $id\n");

            // 1. the branch was cut from the CURRENT main HEAD => 0 behind, BY CONSTRUCTION.
            $this->assertTrue($r['start']['ok'], (string) ($r['start']['reason'] ?? ''));
            $this->assertSame($mainBefore, $r['start']['base_head'], "cycle $id branch is cut from fresh main HEAD");
            $this->assertSame(0, $r['start']['behind'], "cycle $id starts 0 commits behind main");

            // 2. commit landed.
            $this->assertTrue($r['commit']['ok'], (string) ($r['commit']['reason'] ?? ''));
            $this->assertContains($file, $r['commit']['files']);

            // 3. merge advanced main, and main now CONTAINS the cycle's file.
            $this->assertTrue($r['merge']['merged'], (string) ($r['merge']['reason'] ?? ''));
            $this->assertSame($mainBefore, $r['merge']['main_head_before']);
            $this->assertNotSame($mainBefore, $r['merge']['main_head_after'], "main advanced after $id");
            $this->assertSame($r['merge']['main_head_after'], $this->mainHead());
            $this->assertStringContainsString($file, $this->git(['ls-tree', '--name-only', 'main']));
        }

        // After 3 cycles, ALL three files are on main (each cycle built on the prior merged main = no drift).
        $tree = $this->git(['ls-tree', '--name-only', 'main']);
        foreach (['a.txt', 'b.txt', 'c.txt'] as $f) {
            $this->assertStringContainsString($f, $tree, "main accumulated $f across cycles with zero drift");
        }
    }

    public function test_next_cycle_branch_contains_the_previous_cycle_merge(): void
    {
        // The crux: cycle 2's branch, cut AFTER cycle 1 merged, must already contain cycle 1's work.
        $c = $this->svc();
        $this->runCycle($c, 'first', 'first.txt', "1\n");

        $start2 = $c->startCycle($this->repo, 'second');
        $this->assertTrue($start2['ok']);
        // first.txt (cycle 1) is present on cycle 2's branch tip => cycle 2 starts from the fresh, merged main.
        $this->assertStringContainsString('first.txt', $this->git(['ls-tree', '--name-only', (string) $start2['branch']]));
        $this->assertSame(0, $c->commitsBehindMain($this->repo, (string) $start2['branch'], 'main'));
    }

    public function test_staleness_guard_refuses_a_base_too_far_behind(): void
    {
        $c = $this->svc();
        // cut a branch at the current main, then advance main twice so that branch is 2 behind.
        $this->git(['branch', 'stale-base', 'main']);
        $this->runCycle($c, 'adv1', 'x.txt', "x\n");
        $this->runCycle($c, 'adv2', 'y.txt', "y\n");
        // each --no-ff cycle adds the cycle commit + a merge commit, so stale-base is several commits behind.
        $behind = $c->commitsBehindMain($this->repo, 'stale-base', 'main');
        $this->assertGreaterThanOrEqual(2, $behind, 'stale-base drifted behind main across cycles');

        // starting a cycle while guarding on the stale base with maxBehind=0 must REFUSE (anti-579).
        $refused = $c->startCycle($this->repo, 'guarded', 'main', 0, 'stale-base');
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('base_too_stale', (string) $refused['reason']);
        $this->assertSame($behind, $refused['behind']);

        // a threshold above the drift lets it through (and it still cuts from FRESH main).
        $ok = $c->startCycle($this->repo, 'guarded2', 'main', $behind + 5, 'stale-base');
        $this->assertTrue($ok['ok']);
        $this->assertSame($this->mainHead(), $ok['base_head']);
    }

    public function test_merge_conflict_is_fail_closed_main_untouched(): void
    {
        $c = $this->svc();
        // two cycles edit the SAME file/line; cycle A merges, cycle B then conflicts.
        $startA = $c->startCycle($this->repo, 'conflictA');
        $c->commitCycle($this->repo, (string) $startA['branch'], 'A', fn (string $wt) => @file_put_contents($wt.'/clash.txt', "A\n"));
        $startB = $c->startCycle($this->repo, 'conflictB');
        $c->commitCycle($this->repo, (string) $startB['branch'], 'B', fn (string $wt) => @file_put_contents($wt.'/clash.txt', "B\n"));

        $this->assertTrue($c->mergeToMain($this->repo, (string) $startA['branch'])['merged']);
        $mainAfterA = $this->mainHead();

        $mergeB = $c->mergeToMain($this->repo, (string) $startB['branch']);
        $this->assertFalse($mergeB['merged'], 'a conflicting cycle is fail-closed');
        $this->assertStringContainsString('merge_conflict_or_failed', (string) $mergeB['reason']);
        $this->assertSame($mainAfterA, $this->mainHead(), 'main is byte-identical after a refused conflict merge');
    }

    public function test_concurrent_merge_is_refused_by_the_exclusive_lock(): void
    {
        // The adversarial-verify data-loss fix: a second crossing must be REFUSED, never raced (so it can
        // never reset main over a concurrent merge's commit).
        $c = $this->svc();
        $start = $c->startCycle($this->repo, 'locked');
        $c->commitCycle($this->repo, (string) $start['branch'], 'x', fn (string $wt) => @file_put_contents($wt.'/z.txt', "z\n"));

        // Simulate a concurrent crossing holding the SINGLE main-merge lock (collapsed from the old per-path
        // locks in LOOP-OS Slice 1 — the cycle contract now shares atlas-main-merge.lock with drain + obra).
        $lock = fopen($this->repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $mainBefore = $this->mainHead();

        $refused = $c->mergeToMain($this->repo, (string) $start['branch']);
        $this->assertFalse($refused['merged'], 'a concurrent merge is refused while the lock is held');
        $this->assertSame('merge_lock_held_by_another_crossing', $refused['reason']);
        $this->assertSame($mainBefore, $this->mainHead(), 'main untouched by the refused crossing');

        // Releasing the lock lets the merge proceed.
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertTrue($c->mergeToMain($this->repo, (string) $start['branch'])['merged'], 'merge proceeds once the lock frees');
    }

    public function test_guard_floors_and_refusals(): void
    {
        $c = $this->svc();
        // nothing-to-commit (write nothing) => ok=false, no empty commit.
        $start = $c->startCycle($this->repo, 'empty');
        $commit = $c->commitCycle($this->repo, (string) $start['branch'], 'noop', fn (string $wt) => null);
        $this->assertFalse($commit['ok']);
        $this->assertSame('nothing_to_commit', $commit['reason']);

        // a non-cycle branch can never be merged or discarded by this contract.
        $this->assertSame('not_a_governed_cycle_branch', $c->mergeToMain($this->repo, 'main')['reason']);
        $this->assertSame('refused_non_cycle_branch', $c->discardCycle($this->repo, 'main')['reason']);

        // discard is idempotent.
        $this->assertTrue($c->discardCycle($this->repo, (string) $start['branch'])['discarded']);
        $this->assertSame('already_absent', $c->discardCycle($this->repo, (string) $start['branch'])['reason']);
    }
}
