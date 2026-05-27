<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipMergeQueueService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchReviewPacketService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipRepoMergeLeaseService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipMergeQueueServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap772_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-772 merge queue tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipMergeQueueService
    {
        $service = app(StewardshipMergeQueueService::class);
        $service->setStorageRootForTesting($this->tmp.'/queue');

        return $service;
    }

    public function test_plans_visible_order_without_merging(): void
    {
        $repo = $this->repoWithBranches();
        $main = $this->gitHead($repo, 'main');

        $queue = $this->service()->run([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/docs-a', 'atlas/area-focus/code-change'],
        ]);

        $this->assertSame(StewardshipMergeQueueService::STATUS_READY, $queue['status']);
        $this->assertSame(2, $queue['branch_count']);
        $this->assertCount(2, $queue['planned_order']);
        $this->assertSame('planned_review_or_manual_merge', $queue['results'][0]['queue_action']);
        $this->assertCount(2, $queue['branch_review_packets']);
        $this->assertSame(
            StewardshipBranchReviewPacketService::PACKET_SCHEMA,
            $queue['results'][0]['branch_review_packet']['schema_version']
        );
        $this->assertSame('AP-780', $queue['results'][0]['branch_review_packet']['ap_contract']);
        $this->assertNotEmpty($queue['results'][0]['branch_review_packet']['decision_options']);
        $this->assertNotEmpty($queue['results'][0]['branch_review_packet']['operator_next_action']);
        $this->assertSame(
            $queue['results'][0]['branch_review_packet'],
            $queue['branch_review_packets'][0]
        );
        $this->assertSame(2, $queue['summary']['branch_review_packets']);
        $this->assertSame($main, $this->gitHead($repo, 'main'));
        $this->assertFalse($queue['claim_policy']['parallel_merge_performed']);
    }

    public function test_execute_queue_rechecks_live_base_between_auto_merges(): void
    {
        $repo = $this->repoWithBranches();

        $queue = $this->service()->run([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/docs-a', 'atlas/area-focus/docs-b'],
            'auto_merge' => true,
            'execute_queue' => true,
            'record_queue' => true,
            'lease_owner' => 'test-runner',
        ]);

        $this->assertSame(StewardshipMergeQueueService::STATUS_EXECUTED, $queue['status']);
        $this->assertSame('acquired', $queue['repo_merge_lease']['status']);
        $this->assertSame('released', $queue['repo_merge_lease_release']['status']);
        $this->assertSame(1, $queue['summary']['auto_merged']);
        $this->assertSame('auto_merged_ff_only', $queue['results'][0]['queue_action']);
        $this->assertSame('stopped_or_review_required_after_live_recheck', $queue['results'][1]['queue_action']);
        $this->assertSame('blocked', $queue['results'][1]['governance_status']);
        $this->assertSame(
            StewardshipBranchReviewPacketService::STATUS_MERGED,
            $queue['results'][0]['branch_review_packet_status']
        );
        $this->assertSame(
            StewardshipBranchReviewPacketService::STATUS_BLOCKED,
            $queue['results'][1]['branch_review_packet_status']
        );
        $this->assertSame(
            $queue['results'][1]['governance']['repo']['base_commit'],
            $queue['results'][1]['branch_review_packet']['branch_identity']['base_commit']
        );
        $this->assertNotEmpty($queue['results'][1]['branch_review_packet']['blockers']);
        $this->assertSame(
            'Repair branch first',
            $queue['results'][1]['branch_review_packet']['decision_options'][0]['label']
        );
        $this->assertSame(1, $queue['claim_policy']['ff_only_merges_performed']);
        $this->assertSame('recorded', $queue['queue_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os'));
    }

    public function test_execute_queue_blocks_when_another_runner_owns_repo_merge_lease(): void
    {
        $repo = $this->repoWithBranches();
        $service = $this->service();
        $lease = app(StewardshipRepoMergeLeaseService::class);
        $lease->setStorageRootForTesting($this->tmp.'/queue/repo_merge_lease');
        $lease->acquire([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'owner' => 'runner-a',
            'ttl_seconds' => 3600,
        ]);

        $queue = $service->run([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/docs-b'],
            'auto_merge' => true,
            'execute_queue' => true,
            'lease_owner' => 'runner-b',
        ]);

        $this->assertSame(StewardshipMergeQueueService::STATUS_BLOCKED, $queue['status']);
        $this->assertSame('active_merge_lease_exists', $queue['reason']);
        $this->assertSame('active_merge_lease_exists', $queue['repo_merge_lease']['reason']);
    }

    public function test_blocked_branch_still_gets_operator_review_packet(): void
    {
        $repo = $this->repoWithConflictBranch();

        $queue = $this->service()->run([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/conflict-doc'],
        ]);

        $this->assertSame(StewardshipMergeQueueService::STATUS_READY, $queue['status']);
        $this->assertSame('blocked', $queue['results'][0]['governance_status']);
        $this->assertSame(
            StewardshipBranchReviewPacketService::STATUS_BLOCKED,
            $queue['results'][0]['branch_review_packet_status']
        );
        $this->assertSame(
            StewardshipBranchReviewPacketService::PACKET_SCHEMA,
            $queue['branch_review_packets'][0]['schema_version']
        );
        $this->assertSame(
            'Repair branch first',
            $queue['branch_review_packets'][0]['decision_options'][0]['label']
        );
        $this->assertNotEmpty($queue['branch_review_packets'][0]['operator_next_action']);
    }

    public function test_blocks_without_branches(): void
    {
        $queue = $this->service()->run([]);

        $this->assertSame(StewardshipMergeQueueService::STATUS_BLOCKED, $queue['status']);
        $this->assertSame('branch_refs_required', $queue['reason']);
    }

    private function repoWithBranches(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs');
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/docs/a.md', "a\n");
        file_put_contents($repo.'/docs/b.md', "b\n");
        file_put_contents($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/docs-a'], $repo);
        file_put_contents($repo.'/docs/a.md', "a\nbranch a\n");
        $this->runGit(['git', 'add', 'docs/a.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Docs A'], $repo);

        $this->runGit(['git', 'checkout', 'main'], $repo);
        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/docs-b'], $repo);
        file_put_contents($repo.'/docs/b.md', "b\nbranch b\n");
        $this->runGit(['git', 'add', 'docs/b.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Docs B'], $repo);

        $this->runGit(['git', 'checkout', 'main'], $repo);
        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/code-change'], $repo);
        file_put_contents($repo.'/app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n");
        $this->runGit(['git', 'add', 'app/Foo.php'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Code change'], $repo);
        $this->runGit(['git', 'checkout', 'main'], $repo);

        return $repo;
    }

    private function repoWithConflictBranch(): string
    {
        $repo = $this->tmp.'/repo_conflict';
        File::ensureDirectoryExists($repo.'/docs');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/docs/conflict.md', "base\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/conflict-doc'], $repo);
        file_put_contents($repo.'/docs/conflict.md', "branch\n");
        $this->runGit(['git', 'add', 'docs/conflict.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Branch conflict'], $repo);

        $this->runGit(['git', 'checkout', 'main'], $repo);
        file_put_contents($repo.'/docs/conflict.md', "main\n");
        $this->runGit(['git', 'add', 'docs/conflict.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Main conflict'], $repo);

        return $repo;
    }

    private function gitHead(string $repo, string $ref): string
    {
        $process = new Process(['git', 'rev-parse', '--short', $ref], $repo);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput().$process->getOutput());
    }
}
