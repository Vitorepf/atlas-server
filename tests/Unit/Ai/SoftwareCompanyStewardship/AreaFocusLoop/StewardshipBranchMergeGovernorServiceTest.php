<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipBranchMergeGovernorServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap769_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-769 merge governor tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipBranchMergeGovernorService
    {
        $service = app(StewardshipBranchMergeGovernorService::class);
        $service->setStorageRootForTesting($this->tmp.'/governor');

        return $service;
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs');
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/docs/README.md', "base docs\n");
        file_put_contents($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
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
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput().$process->getOutput());
    }

    private function branch(string $repo, string $name): void
    {
        $this->runGit(['git', 'checkout', '-b', $name], $repo);
    }

    private function checkout(string $repo, string $name): void
    {
        $this->runGit(['git', 'checkout', $name], $repo);
    }

    private function commitFile(string $repo, string $path, string $contents, string $message): void
    {
        File::ensureDirectoryExists(dirname($repo.'/'.$path));
        file_put_contents($repo.'/'.$path, $contents);
        $this->runGit(['git', 'add', $path], $repo);
        $this->runGit(['git', 'commit', '-m', $message], $repo);
    }

    public function test_gitkraken_surface_links_cycle_traceability_metadata(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/traceable');
        $this->commitFile($repo, 'docs/README.md', "base docs\ntraceable\n", 'Traceable docs');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/traceable',
            'finding_id' => 'finding_001',
            'spec_id' => 'spec_001',
            'receipt_id' => 'receipt_001',
        ]);

        $this->assertSame('main', $report['gitkraken_review_surface']['visible_base_ref']);
        $this->assertSame('atlas/area-focus/traceable', $report['gitkraken_review_surface']['visible_branch_ref']);
        $this->assertSame('finding_001', $report['gitkraken_review_surface']['cycle_traceability']['finding_id']);
        $this->assertSame('spec_001', $report['gitkraken_review_surface']['cycle_traceability']['spec_id']);
        $this->assertSame('receipt_001', $report['gitkraken_review_surface']['cycle_traceability']['receipt_id']);
    }

    public function test_docs_only_branch_is_auto_merge_eligible_and_gitkraken_visible(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-safe');
        $this->commitFile($repo, 'docs/README.md', "base docs\nsafe update\n", 'Docs safe update');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-safe',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $report['status']);
        $this->assertTrue($report['auto_merge_policy']['eligible']);
        $this->assertSame('documentation_only', $report['classification']['kind']);
        $this->assertSame('branch_on_top_of_base', $report['gitkraken_review_surface']['graph_shape']);
        $this->assertSame(['docs/README.md'], $report['gitkraken_review_surface']['changed_files']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
    }

    public function test_code_branch_requires_operator_review_without_validation_and_code_authorization(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/code-review');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Code change');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/code-review',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_REVIEW_REQUIRED, $report['status']);
        $this->assertFalse($report['auto_merge_policy']['eligible']);
        $this->assertContains('change_class_requires_operator_review', $report['auto_merge_policy']['reasons']);
        $this->assertSame('code_or_mixed', $report['classification']['kind']);
    }

    public function test_conflict_blocks_before_merge(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/conflict');
        $this->commitFile($repo, 'docs/README.md', "branch edit\n", 'Branch edit');
        $this->checkout($repo, 'main');
        $this->commitFile($repo, 'docs/README.md', "main edit\n", 'Main edit');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/conflict',
        ]);

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('branch_not_rebased_on_current_base', $report['blockers']);
        $this->assertContains('merge_conflict_detected', $report['blockers']);
        $this->assertFalse($report['merge_conflict_check']['clean']);
    }

    public function test_execute_merge_fast_forwards_only_when_policy_allows(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-auto');
        $this->commitFile($repo, 'docs/README.md', "base docs\nauto merge\n", 'Docs auto merge');
        $branchHead = trim((new Process(['git', 'rev-parse', '--short', 'HEAD'], $repo))->mustRun()->getOutput());
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-auto',
            'auto_merge' => true,
            'execute_merge' => true,
            'record_governance' => true,
        ]);

        $mainHead = trim((new Process(['git', 'rev-parse', '--short', 'main'], $repo))->mustRun()->getOutput());

        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_MERGED, $report['status']);
        $this->assertSame($branchHead, $mainHead);
        $this->assertTrue($report['claim_policy']['merge_performed']);
        $this->assertSame('recorded', $report['governance_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os'));
    }
}
