<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopBatteryRunner;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4.5 — the runner's fail-closed CONTRACT: only exit 0 is PASS; every other code
 * (and null/unknown) is a REJECT with a specific reason; a non-applying candidate diff fails closed BEFORE
 * the probe; a worktree without the probe is bytes_not_proven. (The candidate-bytes end-to-end proof over the
 * genesis seeds is the next piece; this pins the deterministic spine the sentinel relies on.)
 */
final class AtlasLoopBatteryRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private AtlasLoopBatteryRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new AtlasLoopBatteryRunner();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    public function test_score_is_fail_closed_only_zero_passes(): void
    {
        $this->assertTrue($this->runner->score(0)['pass']);

        foreach ([
            10 => 'surviving_bad',
            20 => 'good_not_certified',
            30 => 'robustness_violation',
            64 => 'probe_crashed',
            65 => 'probe_timeout',
            66 => 'bytes_not_proven',
        ] as $exit => $reason) {
            $v = $this->runner->score($exit);
            $this->assertFalse($v['pass'], "exit $exit must REJECT");
            $this->assertSame($reason, $v['reason']);
        }

        // null AND any unmapped code REJECT as unknown_exit — stricter than FrozenJudge's `?? 1`.
        $this->assertFalse($this->runner->score(null)['pass']);
        $this->assertSame('unknown_exit', $this->runner->score(null)['reason']);
        $this->assertSame('unknown_exit', $this->runner->score(99)['reason']);
    }

    public function test_strict_apply_applies_a_clean_diff_and_fail_closes_on_a_rejectable_one(): void
    {
        $repo = $this->repo("alpha\nbeta\ngamma\n");

        // A clean diff produced from this very tree applies and mutates the file.
        File::put($repo.'/f.txt', "alpha\nBETA\ngamma\n");
        $clean = $this->diff($repo);
        $this->git($repo, ['checkout', '--', 'f.txt']);
        $this->assertTrue($this->runner->strictApply($repo, $clean));
        $this->assertStringContainsString('BETA', (string) File::get($repo.'/f.txt'));

        // A diff whose context does not match the tree must FAIL-CLOSED (false), never partially apply.
        $bogus = "--- a/f.txt\n+++ b/f.txt\n@@ -1,2 +1,2 @@\n-nonexistent line\n+replacement\n context\n";
        $this->assertFalse($this->runner->strictApply($repo, $bogus));
    }

    public function test_probe_on_a_worktree_without_the_entrypoint_is_bytes_not_proven(): void
    {
        $repo = $this->repo("x\n"); // no Constitution/probe/ entrypoint in this bare tree
        $v = $this->runner->probe($repo, $repo.'/battery');
        $this->assertFalse($v['pass']);
        $this->assertSame('bytes_not_proven', $v['reason']);
    }

    private function repo(string $seed): string
    {
        $d = sys_get_temp_dir().'/atlas-runner-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        File::put($d.'/f.txt', $seed);
        foreach ([['init', '-q'], ['config', 'user.email', 't@t'], ['config', 'user.name', 't'], ['add', '-A'], ['commit', '-q', '-m', 'seed', '--no-gpg-sign']] as $argv) {
            (new Process(array_merge(['git'], $argv), $d))->run();
        }

        return $d;
    }

    private function diff(string $repo): string
    {
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    private function git(string $repo, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $repo))->run();
    }
}
