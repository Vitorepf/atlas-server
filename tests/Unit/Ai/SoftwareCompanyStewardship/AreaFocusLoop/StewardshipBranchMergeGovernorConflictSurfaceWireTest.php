<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: the governor report now carries the advisory
 * `conflict_surface` classification parsed from the same merge-tree output it
 * already emits raw as `merge_conflict_check.error_excerpt`. The
 * status/blockers verdict is byte-identical to before.
 */
final class StewardshipBranchMergeGovernorConflictSurfaceWireTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap769_surface_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for merge governor tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_content_conflict_is_classified_on_the_report(): void
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

        // Existing verdict byte-identical: the conflict still blocks.
        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('merge_conflict_detected', $report['blockers']);
        $this->assertFalse($report['merge_conflict_check']['clean']);

        $surface = $report['conflict_surface'];
        $this->assertIsArray($surface);
        $this->assertSame('atlas.loop.merge_conflict_surface.v1', $surface['schema_version']);
        $this->assertSame(1, $surface['conflict_file_count']);
        $this->assertSame(['docs/README.md'], $surface['conflict_paths']);
        $this->assertSame('content', $surface['conflict_kind']);
        $this->assertSame('low', $surface['worst_severity']);
        $this->assertFalse($surface['governance_path_in_conflict']);
    }

    public function test_clean_branch_reports_zero_conflict_surface(): void
    {
        $repo = $this->repo();
        $this->branch($repo, 'atlas/area-focus/clean');
        $this->commitFile($repo, 'docs/NOTES.md', "new notes\n", 'Add notes');
        $this->checkout($repo, 'main');

        $report = $this->service()->evaluate([
            'repo_root' => $repo,
            'base_ref' => 'main',
            'branch_ref' => 'atlas/area-focus/clean',
        ]);

        $this->assertTrue($report['merge_conflict_check']['clean']);
        $surface = $report['conflict_surface'];
        $this->assertIsArray($surface);
        $this->assertSame(0, $surface['conflict_file_count']);
        $this->assertSame([], $surface['conflict_paths']);
        $this->assertFalse($surface['governance_path_in_conflict']);
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
}
