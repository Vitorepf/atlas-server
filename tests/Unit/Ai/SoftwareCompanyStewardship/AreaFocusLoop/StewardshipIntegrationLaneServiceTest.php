<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipIntegrationLaneService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipIntegrationLaneServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap782_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-782 tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipIntegrationLaneService
    {
        $service = app(StewardshipIntegrationLaneService::class);
        $service->setStorageRootForTesting($this->tmp.'/lane');

        return $service;
    }

    public function test_integrates_docs_candidate_to_visible_lane_without_touching_main(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-safe');
        $this->commitFile($repo, 'docs/a.md', "base\nsafe\n", 'Docs safe');
        $candidateHead = $this->gitOut(['git', 'rev-parse', 'atlas/area-focus/docs-safe'], $repo);
        $this->checkout($repo, 'main');
        File::put($repo.'/operator-scratch.txt', "dirty\n");
        $mainBefore = $this->gitOut(['git', 'rev-parse', 'main'], $repo);

        $report = $this->service()->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-safe',
            'record' => true,
        ]);

        $this->assertSame(StewardshipIntegrationLaneService::STATUS_INTEGRATED, $report['status']);
        $this->assertSame($mainBefore, $this->gitOut(['git', 'rev-parse', 'main'], $repo));
        $this->assertTrue($report['repo']['base_untouched']);
        $this->assertSame($candidateHead, $report['integration_lane']['lane_commit_after']);
        $this->assertSame($candidateHead, $this->gitOut(['git', 'rev-parse', $report['integration_lane']['lane_ref']], $repo));
        $this->assertTrue($report['integration_lane']['gitkraken_visible']);
        $this->assertSame('auto_merge_candidate', $report['branch_review_packet']['status']);
        $this->assertFalse($report['claim_policy']['merge_performed_to_base']);
        $this->assertSame('recorded', $report['integration_storage_status']);
        $this->assertFileExists($this->service()->recordPath('agentic_engineering_os'));
    }

    public function test_blocks_non_auto_merge_candidate(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/code-review');
        $this->commitFile($repo, 'app/Foo.php', "<?php\n\nfinal class Foo { public function ok(): bool { return true; } }\n", 'Code review');
        $this->checkout($repo, 'main');

        $report = $this->service()->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/code-review',
        ]);

        $this->assertSame(StewardshipIntegrationLaneService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('candidate_not_auto_merge_eligible', $report['reason']);
        $this->assertFalse($report['claim_policy']['integration_branch_created_or_advanced']);
    }

    public function test_blocks_candidate_not_based_on_current_integration_lane(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/docs-a');
        $this->commitFile($repo, 'docs/a.md', "base\na\n", 'Docs A');
        $this->checkout($repo, 'main');
        $this->branch($repo, 'atlas/area-focus/docs-b');
        $this->commitFile($repo, 'docs/b.md', "b\n", 'Docs B');
        $this->checkout($repo, 'main');

        $service = $this->service();
        $first = $service->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-a',
        ]);
        $second = $service->integrate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/docs-b',
        ]);

        $this->assertSame(StewardshipIntegrationLaneService::STATUS_INTEGRATED, $first['status']);
        $this->assertSame(StewardshipIntegrationLaneService::STATUS_BLOCKED, $second['status']);
        $this->assertSame('branch_not_based_on_integration_lane', $second['reason']);
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/docs');
        File::ensureDirectoryExists($repo.'/app');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        File::put($repo.'/docs/a.md', "base\n");
        File::put($repo.'/app/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
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
