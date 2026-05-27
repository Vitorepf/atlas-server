<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipIntegrationLanePromotionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipRepoMergeLeaseService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipIntegrationLanePromotionServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap783_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-783 integration lane promotion tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipIntegrationLanePromotionService
    {
        $service = app(StewardshipIntegrationLanePromotionService::class);
        $service->setStorageRootForTesting($this->tmp.'/promotion');

        return $service;
    }

    public function test_promotes_clean_lane_to_base_with_lease_released(): void
    {
        $repo = $this->repo();
        $laneRef = 'atlas/integration/agentic_engineering_os/main';
        $this->runGit(['git', 'branch', $laneRef, 'main'], $repo);
        $this->runGit(['git', 'checkout', $laneRef], $repo);
        $this->commitFile($repo, 'docs/lane.md', "lane docs\n", 'Lane docs');
        $laneHead = $this->gitOut(['git', 'rev-parse', $laneRef], $repo);
        $this->checkout($repo, 'main');
        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);

        $report = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => $laneRef,
            'record' => true,
        ]);

        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_PROMOTED, $report['status']);
        $this->assertTrue($report['promoted']);
        $this->assertSame($laneHead, $report['base_after']);
        $this->assertSame($laneHead, $this->gitOut(['git', 'rev-parse', 'main'], $repo));
        $this->assertNotSame($mainBefore, $report['base_after']);
        $this->assertSame('released', $report['lease_status']);
        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_MERGED, $report['governance_status']);
        $this->assertFalse($report['evidence']['dangerous_actions']);
        $this->assertFalse($report['claim_policy']['dangerous_actions']);
        $this->assertSame('recorded', $report['promotion_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os'));
    }

    public function test_blocks_when_base_worktree_is_dirty_without_touching_main_or_lease(): void
    {
        $repo = $this->repo();
        $laneRef = 'atlas/integration/agentic_engineering_os/main';
        $this->runGit(['git', 'branch', $laneRef, 'main'], $repo);
        $this->runGit(['git', 'checkout', $laneRef], $repo);
        $this->commitFile($repo, 'docs/lane.md', "lane docs\n", 'Lane docs');
        $this->checkout($repo, 'main');
        File::put($repo.'/operator-scratch.txt', "dirty\n");
        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);

        $lease = app(StewardshipRepoMergeLeaseService::class);
        $lease->setStorageRootForTesting($this->tmp.'/promotion/repo_merge_lease');
        $lease->acquire([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'owner' => 'other-runner',
            'ttl_seconds' => 3600,
        ]);

        $report = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => $laneRef,
        ]);

        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('base_worktree_dirty', $report['blockers']);
        $this->assertTrue($report['base_untouched_on_block']);
        $this->assertSame($mainBefore, $this->gitOut(['git', 'rev-parse', 'main'], $repo));
        $this->assertSame('not_attempted', $report['lease_status']);
        $this->assertSame('not_run', $report['governance_status']);
    }

    public function test_blocks_when_another_runner_holds_repo_merge_lease(): void
    {
        $repo = $this->repo();
        $laneRef = 'atlas/integration/agentic_engineering_os/main';
        $this->runGit(['git', 'branch', $laneRef, 'main'], $repo);
        $this->runGit(['git', 'checkout', $laneRef], $repo);
        $this->commitFile($repo, 'docs/lane.md', "lane docs\n", 'Lane docs');
        $this->checkout($repo, 'main');
        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);

        $lease = app(StewardshipRepoMergeLeaseService::class);
        $lease->setStorageRootForTesting($this->tmp.'/promotion/repo_merge_lease');
        $lease->acquire([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'owner' => 'runner-a',
            'ttl_seconds' => 3600,
        ]);

        $report = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => $laneRef,
            'lease_owner' => 'runner-b',
        ]);

        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('active_merge_lease_exists', $report['reason']);
        $this->assertSame($mainBefore, $this->gitOut(['git', 'rev-parse', 'main'], $repo));
        $this->assertFalse($report['promoted']);
    }

    public function test_blocks_invalid_or_missing_lane_ref(): void
    {
        $repo = $this->repo();

        $invalid = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => 'atlas/area-focus/not-integration',
        ]);
        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_BLOCKED, $invalid['status']);
        $this->assertSame('invalid_lane_ref', $invalid['reason']);

        $missing = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => 'atlas/integration/agentic_engineering_os/missing',
        ]);
        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_BLOCKED, $missing['status']);
        $this->assertSame('lane_ref_not_found', $missing['reason']);
    }

    public function test_blocks_when_lane_is_not_auto_merge_eligible(): void
    {
        $repo = $this->repo();
        $laneRef = 'atlas/integration/agentic_engineering_os/main';
        $this->runGit(['git', 'branch', $laneRef, 'main'], $repo);
        $this->runGit(['git', 'checkout', $laneRef], $repo);
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Code on lane');
        $this->checkout($repo, 'main');
        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);

        $report = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => $laneRef,
        ]);

        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('governance_blocked', $report['reason']);
        $this->assertSame($mainBefore, $this->gitOut(['git', 'rev-parse', 'main'], $repo));
        $this->assertFalse($report['promoted']);
        $this->assertContains('auto_merge_policy_not_satisfied', $report['blockers']);
    }

    public function test_returns_already_promoted_when_base_already_matches_lane(): void
    {
        $repo = $this->repo();
        $laneRef = 'atlas/integration/agentic_engineering_os/main';
        $this->runGit(['git', 'branch', $laneRef, 'main'], $repo);
        $this->runGit(['git', 'checkout', $laneRef], $repo);
        $this->commitFile($repo, 'docs/lane.md', "lane docs\n", 'Lane docs');
        $laneHead = $this->gitOut(['git', 'rev-parse', $laneRef], $repo);
        $this->checkout($repo, 'main');
        $this->runGit(['git', 'merge', '--ff-only', $laneRef], $repo);

        $report = $this->service()->promote([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'lane_ref' => $laneRef,
        ]);

        $this->assertSame(StewardshipIntegrationLanePromotionService::STATUS_ALREADY_PROMOTED, $report['status']);
        $this->assertFalse($report['promoted']);
        $this->assertSame($laneHead, $report['base_before']);
        $this->assertSame($laneHead, $report['base_after']);
        $this->assertSame('not_required', $report['lease_status']);
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs');
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        File::put($repo.'/docs/README.md', "base docs\n");
        File::put($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
    }

    private function checkout(string $repo, string $name): void
    {
        $this->runGit(['git', 'checkout', $name], $repo);
    }

    private function commitFile(string $repo, string $path, string $contents, string $message): void
    {
        File::ensureDirectoryExists(dirname($repo.'/'.$path));
        File::put($repo.'/'.$path, $contents);
        $this->runGit(['git', 'add', $path], $repo);
        $this->runGit(['git', 'commit', '-m', $message], $repo);
    }

    /**
     * @param  list<string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput().$process->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    private function gitOut(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
