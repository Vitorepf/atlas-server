<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMainHealthSentinel;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\Rsi\RsiGitRevertPort;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 1 · Slice 1.5 — the post-merge health sentinel. The proof drives REAL loop commits (with
 * the literal production subject so the window-extraction filter fires) through the sentinel; the suite-run
 * and the git-revert are injected fakes (a real revert is never run in a test), so the test proves the
 * sentinel's LOGIC — window detection, union changed-files, culprit selection, revert call, park — not a
 * vacuous green.
 */
final class AtlasLoopMainHealthSentinelTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function git(string $repo, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $repo);
        $p->run();

        return trim($p->getOutput());
    }

    /** @return array{0:string,1:string,2:string} [repo, olderLoopSha, latestLoopSha] */
    private function repoWithTwoLoopCommits(): array
    {
        $d = sys_get_temp_dir().'/atlas-sentinel-'.bin2hex(random_bytes(4));
        mkdir($d.'/app/Svc', 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/README.md', "base\n");
        $this->git($d, ['init', '-q', '-b', 'main']);
        $this->git($d, ['config', 'user.email', 't@t']);
        $this->git($d, ['config', 'user.name', 't']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['commit', '-q', '-m', 'base', '--no-gpg-sign']);

        file_put_contents($d.'/app/Svc/Foo.php', "<?php\nclass Foo {}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['commit', '-q', '-m', 'atlas loop auto-merge: app/Svc/Foo.php [aaaaaaaaaaaa]', '--no-gpg-sign']);
        $older = $this->git($d, ['rev-parse', 'HEAD']);

        file_put_contents($d.'/app/Svc/Bar.php', "<?php\nclass Bar {}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['commit', '-q', '-m', 'atlas loop auto-merge: app/Svc/Bar.php [bbbbbbbbbbbb]', '--no-gpg-sign']);
        $latest = $this->git($d, ['rev-parse', 'HEAD']);

        return [$d, $older, $latest];
    }

    private function gate(bool $passed): BroaderRegressionGateContract
    {
        return new class($passed) implements BroaderRegressionGateContract
        {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(private bool $passed) {}

            public function evaluate(string $repoRoot, array $changedFiles): array
            {
                $this->calls[] = $changedFiles;

                return ['schema_version' => 'fake', 'passed' => $this->passed, 'reason' => $this->passed ? null : 'cross_file_red', 'suites' => [], 'boot_smoke' => [], 'php_lint' => [], 'selected_tests' => []];
            }
        };
    }

    private function revertPort(): RsiGitRevertPort
    {
        return new class implements RsiGitRevertPort
        {
            /** @var list<string> */
            public array $calls = [];

            public function revert(string $repoRoot, string $mergeHash): array
            {
                $this->calls[] = $mergeHash;

                return ['reverted' => true, 'revert_commit_hash' => 'fake-revert-sha', 'detail' => 'fake_port'];
            }
        };
    }

    private function mergedProposal(string $hash): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'sentinel proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = true;
        $p = AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'bar',
            'target_path' => 'app/Svc/Bar.php',
            'diff_text' => 'x',
            'proposal_hash' => $hash,
            'merged_to_main' => true,
            'quality' => [],
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        return $p;
    }

    public function test_red_main_reverts_the_latest_loop_commit_and_parks_it(): void
    {
        [$repo, $older, $latest] = $this->repoWithTwoLoopCommits();
        $proposal = $this->mergedProposal('bbbbbbbbbbbb'); // links to the LATEST loop commit's subject hash

        $gate = $this->gate(passed: false);
        $port = $this->revertPort();
        $res = (new AtlasLoopMainHealthSentinel($gate, $port))->verify($repo, 10);

        $this->assertSame('reverted', $res['status']);
        $this->assertSame($latest, $res['reverted_sha'], 'the LATEST loop commit is the reverted culprit');
        $this->assertCount(1, $port->calls);
        $this->assertSame($latest, $port->calls[0], 'the revert port was invoked with the latest loop sha');
        // The gate genuinely RAN on the UNION of the window's changed files (cross-file coupling visible).
        $this->assertNotEmpty($gate->calls);
        $this->assertContains('app/Svc/Bar.php', $gate->calls[0]);
        $this->assertContains('app/Svc/Foo.php', $gate->calls[0]);
        // The linked proposal is parked (reviewed_at terminates drainability).
        $proposal->refresh();
        $this->assertNotNull($proposal->reviewed_at);
        $this->assertSame('main_health_reverted', data_get($proposal->quality, '_operator_review.status'));
    }

    public function test_green_impacted_suite_leaves_main_untouched(): void
    {
        [$repo] = $this->repoWithTwoLoopCommits();
        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);

        $port = $this->revertPort();
        $res = (new AtlasLoopMainHealthSentinel($this->gate(passed: true), $port))->verify($repo, 10);

        $this->assertSame('healthy', $res['status']);
        $this->assertCount(0, $port->calls, 'a green impacted suite never reverts');
        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']));
    }

    public function test_no_loop_commits_in_window_is_healthy_without_calling_the_gate(): void
    {
        $d = sys_get_temp_dir().'/atlas-sentinel-noloop-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/README.md', "base\n");
        $this->git($d, ['init', '-q', '-b', 'main']);
        $this->git($d, ['config', 'user.email', 't@t']);
        $this->git($d, ['config', 'user.name', 't']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['commit', '-q', '-m', 'just a normal human commit', '--no-gpg-sign']);

        $gate = $this->gate(passed: false); // would revert if it ran — it must NOT run
        $res = (new AtlasLoopMainHealthSentinel($gate, $this->revertPort()))->verify($d, 10);

        $this->assertSame('healthy', $res['status']);
        $this->assertSame('no_loop_commits_in_window', $res['reason']);
        $this->assertCount(0, $gate->calls, 'no loop commit ⇒ nothing to verify, the gate never runs');
    }

    public function test_a_dirty_tree_defers_the_revert(): void
    {
        [$repo] = $this->repoWithTwoLoopCommits();
        file_put_contents($repo.'/app/Svc/uncommitted_grind.php', "<?php\n"); // active grind state

        $port = $this->revertPort();
        $res = (new AtlasLoopMainHealthSentinel($this->gate(passed: false), $port))->verify($repo, 10);

        $this->assertSame('deferred', $res['status']);
        $this->assertStringContainsString('tree_dirty', (string) $res['reason']);
        $this->assertCount(0, $port->calls, 'never revert over a dirty tree — defer to the next window');
    }
}
