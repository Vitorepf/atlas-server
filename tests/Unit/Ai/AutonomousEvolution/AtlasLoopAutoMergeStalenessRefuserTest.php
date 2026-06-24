<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeStalenessRefuser;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopAutoMergeStalenessRefuserTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-staleness-'.bin2hex(random_bytes(6));
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

    public function test_base_at_head_yields_commits_behind_zero_and_allow_true(): void
    {
        $head = $this->commit('a.txt', 'one');

        $verdict = (new AtlasLoopAutoMergeStalenessRefuser(25))->check($head, $this->repo);

        $this->assertTrue($verdict['allow']);
        $this->assertSame(0, $verdict['commits_behind']);
        $this->assertSame($head, $verdict['head_sha']);
        $this->assertNull($verdict['reason']);
    }

    public function test_base_n_plus_one_commits_behind_is_refused_with_stale_base(): void
    {
        $n = 3;
        $base = $this->commit('a.txt', 'zero');
        for ($i = 1; $i <= $n + 1; $i++) {
            $this->commit('b'.$i.'.txt', 'c'.$i);
        }

        $verdict = (new AtlasLoopAutoMergeStalenessRefuser($n))->check($base, $this->repo);

        $this->assertFalse($verdict['allow']);
        $this->assertSame(AtlasLoopAutoMergeStalenessRefuser::REASON_STALE_BASE, $verdict['reason']);
        $this->assertSame($n + 1, $verdict['commits_behind']);
        $this->assertSame($n, $verdict['max_commits_behind']);
    }

    public function test_refuser_short_circuits_the_pipeline_before_preflight(): void
    {
        $base = $this->commit('a.txt', 'zero');
        for ($i = 1; $i <= 5; $i++) {
            $this->commit('b'.$i.'.txt', 'c'.$i);
        }

        $service = new AtlasLoopAutoMergeService(
            preFlight: new \App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergePreFlightGate,
            conflictDetector: null,
            reverseAuditor: null,
            receiptLedger: null,
            stalenessRefuser: new AtlasLoopAutoMergeStalenessRefuser(2),
        );

        $merged = false;
        $result = $service->autoMerge(
            ['base_sha' => $base, 'branch' => 'atlas/loop/stale'],
            $this->repo,
            function (array $p, string $root) use (&$merged): array {
                $merged = true;

                return ['status' => 'should_not_run'];
            },
        );

        $this->assertFalse($result['merged']);
        $this->assertSame(AtlasLoopAutoMergeStalenessRefuser::REASON_STALE_BASE, $result['reason']);
        $this->assertFalse($merged, 'merge executor must NOT be invoked when staleness refuses');
        $this->assertNull($result['preflight'], 'PreFlightGate was short-circuited (never ran)');
        $this->assertArrayHasKey('staleness', $result);
        $this->assertSame(5, $result['staleness']['commits_behind']);
    }

    public function test_fresh_base_passes_staleness_and_reaches_preflight(): void
    {
        $head = $this->commit('a.txt', 'one');

        $service = new AtlasLoopAutoMergeService(
            preFlight: new \App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergePreFlightGate,
            conflictDetector: null,
            reverseAuditor: null,
            receiptLedger: null,
            stalenessRefuser: new AtlasLoopAutoMergeStalenessRefuser(25),
        );

        $merged = false;
        $result = $service->autoMerge(
            ['base_sha' => $head, 'branch' => 'atlas/loop/fresh'],
            $this->repo,
            function () use (&$merged): array {
                $merged = true;

                return ['status' => 'merged'];
            },
        );

        $this->assertTrue($merged, 'staleness allows AND preflight allows => executor runs');
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
