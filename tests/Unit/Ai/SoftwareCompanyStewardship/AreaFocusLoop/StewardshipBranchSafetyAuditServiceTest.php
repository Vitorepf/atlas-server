<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchLifecycleRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSafetyAuditService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipBranchSafetyAuditServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap773_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-773 branch safety audit tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_audits_queue_ready_and_blocked_branches_without_mutation(): void
    {
        $repo = $this->repoWithBranches();
        $service = $this->service();
        $mainBefore = $this->gitHead($repo, 'main');
        $this->reserveLifecycle('atlas/area-focus/safe-docs', $repo);
        $this->reserveLifecycle('atlas/area-focus/stale-docs', $repo);

        $audit = $service->audit([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_refs' => ['atlas/area-focus/safe-docs', 'atlas/area-focus/stale-docs', 'atlas/area-focus/orphan-docs'],
        ]);

        $this->assertSame(StewardshipBranchSafetyAuditService::STATUS_READY, $audit['status']);
        $this->assertSame(3, $audit['branch_count']);
        $this->assertSame(1, $audit['summary']['queue_ready']);
        $this->assertSame(2, $audit['summary']['blocked']);
        $this->assertSame(['atlas/area-focus/safe-docs'], $audit['queue_ready_branch_refs']);
        $this->assertSame($mainBefore, $this->gitHead($repo, 'main'));

        $byBranch = collect($audit['branches'])->keyBy('branch_ref');
        $this->assertSame('queue_ready', $byBranch['atlas/area-focus/safe-docs']['safety_state']);
        $this->assertSame('blocked', $byBranch['atlas/area-focus/stale-docs']['safety_state']);
        $this->assertContains('branch_not_rebased_on_current_base', $byBranch['atlas/area-focus/stale-docs']['blockers']);
        $this->assertSame('blocked', $byBranch['atlas/area-focus/orphan-docs']['safety_state']);
        $this->assertContains('missing_active_lifecycle_registry_record', $byBranch['atlas/area-focus/orphan-docs']['blockers']);
        $this->assertTrue($audit['claim_policy']['read_only']);
        $this->assertFalse($audit['claim_policy']['merge_performed']);
    }

    public function test_discovers_prefixed_local_branches_and_records_audit(): void
    {
        $repo = $this->repoWithBranches();
        $this->reserveLifecycle('atlas/area-focus/safe-docs', $repo);

        $audit = $this->service()->audit([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_prefix' => 'atlas/area-focus/safe',
            'record_audit' => true,
        ]);

        $this->assertSame(StewardshipBranchSafetyAuditService::STATUS_READY, $audit['status']);
        $this->assertSame(1, $audit['branch_count']);
        $this->assertSame('recorded', $audit['audit_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os'));
    }

    public function test_blocks_without_git_repo_or_branches(): void
    {
        $missingRepo = $this->service()->audit(['repo_root' => $this->tmp.'/missing']);
        $this->assertSame(StewardshipBranchSafetyAuditService::STATUS_BLOCKED, $missingRepo['status']);
        $this->assertSame('repo_root_not_git_repository', $missingRepo['reason']);

        $repo = $this->emptyRepo();
        $noBranches = $this->service()->audit(['repo_root' => $repo, 'branch_prefix' => 'atlas/area-focus/']);
        $this->assertSame(StewardshipBranchSafetyAuditService::STATUS_BLOCKED, $noBranches['status']);
        $this->assertSame('no_cycle_branches_found', $noBranches['reason']);
    }

    private function service(): StewardshipBranchSafetyAuditService
    {
        $service = app(StewardshipBranchSafetyAuditService::class);
        $service->setStorageRootForTesting($this->tmp.'/audit');

        return $service;
    }

    private function reserveLifecycle(string $branch, string $repo): void
    {
        $registry = app(StewardshipBranchLifecycleRegistryService::class);
        $registry->setStorageRootForTesting($this->tmp.'/audit/branch_lifecycle_registry');
        $record = $registry->reserve([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_name' => $branch,
            'handoff_hash' => hash('sha256', $branch),
            'sandbox_id' => 'sandbox_'.substr(hash('sha256', $branch), 0, 8),
            'record_branch_registry' => true,
        ]);

        $this->assertSame(StewardshipBranchLifecycleRegistryService::STATUS_RESERVED, $record['status']);
    }

    private function repoWithBranches(): string
    {
        $repo = $this->emptyRepo();
        File::ensureDirectoryExists($repo.'/docs');
        file_put_contents($repo.'/docs/a.md', "a\n");
        file_put_contents($repo.'/docs/b.md', "b\n");
        file_put_contents($repo.'/docs/c.md', "c\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);

        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/stale-docs'], $repo);
        file_put_contents($repo.'/docs/b.md', "b\nstale\n");
        $this->runGit(['git', 'add', 'docs/b.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Stale docs'], $repo);

        $this->runGit(['git', 'checkout', 'main'], $repo);
        file_put_contents($repo.'/docs/main.md', "main advanced\n");
        $this->runGit(['git', 'add', 'docs/main.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Advance main'], $repo);

        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/safe-docs'], $repo);
        file_put_contents($repo.'/docs/a.md', "a\nsafe\n");
        $this->runGit(['git', 'add', 'docs/a.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Safe docs'], $repo);

        $this->runGit(['git', 'checkout', 'main'], $repo);
        $this->runGit(['git', 'checkout', '-b', 'atlas/area-focus/orphan-docs'], $repo);
        file_put_contents($repo.'/docs/c.md', "c\norphan\n");
        $this->runGit(['git', 'add', 'docs/c.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Orphan docs'], $repo);
        $this->runGit(['git', 'checkout', 'main'], $repo);

        return $repo;
    }

    private function emptyRepo(): string
    {
        $repo = $this->tmp.'/repo_'.uniqid();
        File::ensureDirectoryExists($repo);
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/.gitkeep', "\n");
        $this->runGit(['git', 'add', '.gitkeep'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Bootstrap'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

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
