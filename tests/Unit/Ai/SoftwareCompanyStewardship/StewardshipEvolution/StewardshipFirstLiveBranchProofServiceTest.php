<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchReviewPacketService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipFirstLiveBranchProofService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipFirstLiveBranchProofServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap781_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-781 tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipFirstLiveBranchProofService
    {
        $service = app(StewardshipFirstLiveBranchProofService::class);
        $service->setStorageRootForTesting($this->tmp.'/proofs');

        return $service;
    }

    public function test_creates_visible_branch_worktree_commit_and_review_packet_without_touching_main(): void
    {
        $repo = $this->makeRepo();
        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);

        $receipt = $this->service()->run([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'proof_nonce' => 'fixture-live-proof',
            'record' => true,
        ]);

        $this->assertSame(StewardshipFirstLiveBranchProofService::STATUS_PROVEN, $receipt['status']);
        $this->assertTrue($receipt['claim_policy']['real_git_branch_created']);
        $this->assertTrue($receipt['claim_policy']['real_git_worktree_created']);
        $this->assertTrue($receipt['claim_policy']['real_commit_created']);
        $this->assertFalse($receipt['claim_policy']['merge_performed']);
        $this->assertTrue($receipt['repo']['main_untouched']);
        $this->assertSame($mainBefore, $this->gitOut(['git', 'rev-parse', 'main'], $repo));

        $branch = $receipt['branch']['branch_ref'];
        $worktree = $receipt['branch']['worktree_path'];
        $proofFile = $receipt['branch']['proof_file'];

        $this->assertDirectoryExists($worktree);
        $this->assertFileExists($worktree.'/'.$proofFile);
        $this->assertSame($receipt['branch']['branch_commit'], $this->gitOut(['git', 'rev-parse', $branch], $repo));
        $this->assertStringContainsString($branch, $this->gitOut(['git', 'branch', '--list', $branch], $repo));
        $this->assertSame('AP-769', $receipt['governance_report']['ap_contract']);
        $this->assertSame(
            StewardshipBranchReviewPacketService::PACKET_SCHEMA,
            $receipt['branch_review_packet']['schema_version']
        );
        $this->assertNotEmpty($receipt['branch_review_packet']['decision_options']);
        $this->assertSame('recorded', $receipt['proof_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os', $repo));
    }

    public function test_blocks_when_branch_already_exists(): void
    {
        $repo = $this->makeRepo();
        $branch = 'atlas/area-focus/agentic_engineering_os/live-proof-existing';
        $this->runGit(['git', 'branch', $branch, 'main'], $repo);

        $receipt = $this->service()->run([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => $branch,
        ]);

        $this->assertSame(StewardshipFirstLiveBranchProofService::STATUS_BLOCKED, $receipt['status']);
        $this->assertSame('proof_branch_already_exists', $receipt['reason']);
        $this->assertFalse($receipt['claim_policy']['real_commit_created']);
    }

    private function makeRepo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs/ap');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        File::put($repo.'/docs/ap/root.md', "# Root\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
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
