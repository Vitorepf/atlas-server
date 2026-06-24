<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergePreFlightGate;
use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopAutoMergePreFlightGateTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-preflight-'.bin2hex(random_bytes(6));
        @mkdir($this->repo, 0o755, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'loop@atlas']);
        $this->git(['config', 'user.name', 'atlas-loop']);
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
        parent::tearDown();
    }

    public function test_allows_when_base_sha_equals_current_main_head(): void
    {
        $head = $this->commit('a.txt', 'one');

        $gate = $this->app->make(AtlasLoopAutoMergePreFlightGate::class);
        $verdict = $gate->check($head, $this->repo);

        $this->assertTrue($verdict['allow']);
        $this->assertTrue($verdict['equal']);
        $this->assertSame($head, $verdict['base_sha']);
        $this->assertSame($head, $verdict['head_sha']);
        $this->assertNull($verdict['reason']);
    }

    public function test_refuses_stale_branch_and_never_invokes_the_merge_executor(): void
    {
        $staleBase = $this->commit('a.txt', 'one'); // proposal recorded this base
        $this->commit('b.txt', 'two');               // main MOVED since then

        $merged = false;
        $service = $this->app->make(AtlasLoopAutoMergeService::class);
        $result = $service->autoMerge(
            ['base_sha' => $staleBase, 'branch' => 'atlas/loop/stale'],
            $this->repo,
            function (array $p, string $root) use (&$merged): array {
                $merged = true;

                return ['status' => 'should_not_run'];
            },
        );

        $this->assertFalse($result['merged']);
        $this->assertSame('main_moved', $result['reason']);
        $this->assertFalse($merged, 'the merge executor must NOT be invoked when the gate refuses');
        $this->assertNull($result['merge_result']);
    }

    public function test_happy_path_invokes_the_merge_executor_when_base_matches(): void
    {
        $head = $this->commit('a.txt', 'one');

        $merged = false;
        $service = $this->app->make(AtlasLoopAutoMergeService::class);
        $result = $service->autoMerge(
            ['base_sha' => $head, 'branch' => 'atlas/loop/fresh'],
            $this->repo,
            function (array $p, string $root) use (&$merged): array {
                $merged = true;

                return ['status' => 'merged'];
            },
        );

        $this->assertTrue($result['merged']);
        $this->assertTrue($merged);
    }

    public function test_exclusive_flock_means_a_second_worker_cannot_pass(): void
    {
        $head = $this->commit('a.txt', 'one');
        $gate = $this->app->make(AtlasLoopAutoMergePreFlightGate::class);

        // A concurrent worker holds the pre-flight lock for this repo.
        $held = fopen($gate->lockPath($this->repo), 'c');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $verdict = $gate->check($head, $this->repo); // would otherwise allow (base==head)

        $this->assertFalse($verdict['allow'], 'a second worker cannot pass while the lock is held');
        $this->assertSame('preflight_lock_busy', $verdict['reason']);

        flock($held, LOCK_UN);
        fclose($held);
    }

    private function commit(string $file, string $content): string
    {
        file_put_contents($this->repo.'/'.$file, $content."\n");
        $this->git(['add', $file]);
        $this->git(['commit', '-q', '-m', 'c '.$file, '--no-gpg-sign']);

        return trim((string) $this->gitOut(['rev-parse', 'HEAD']));
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): void
    {
        (new Process(array_merge(['git'], $args), $this->repo))->mustRun();
    }

    /**
     * @param  list<string>  $args
     */
    private function gitOut(array $args): string
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->mustRun();

        return $p->getOutput();
    }
}
