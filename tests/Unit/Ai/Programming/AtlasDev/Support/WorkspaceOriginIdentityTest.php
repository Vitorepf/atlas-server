<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorkspaceOriginIdentityTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        parent::tearDown();
    }

    private function tmpDir(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/').'/atlas-origin-'.bin2hex(random_bytes(5));
        mkdir($dir, 0o755, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    public function test_two_checkouts_of_the_same_remote_share_the_same_identity(): void
    {
        $a = $this->tmpDir();
        $b = $this->tmpDir();
        foreach ([$a, $b] as $dir) {
            (new Process(['git', 'init', '-q'], $dir))->run();
            (new Process(['git', 'remote', 'add', 'origin', 'https://example.test/atlas-server.git'], $dir))->run();
        }

        $this->assertSame(
            WorkspaceOriginIdentity::hash($a),
            WorkspaceOriginIdentity::hash($b),
            'per-run sandboxes of the same repo must share the origin identity',
        );
        $this->assertSame('https://example.test/atlas-server.git', WorkspaceOriginIdentity::slug($a));
    }

    public function test_remote_url_userinfo_is_stripped(): void
    {
        $dir = $this->tmpDir();
        (new Process(['git', 'init', '-q'], $dir))->run();
        (new Process(['git', 'remote', 'add', 'origin', 'https://user:supersecrettoken@example.test/repo.git'], $dir))->run();

        $slug = WorkspaceOriginIdentity::slug($dir);

        $this->assertSame('https://example.test/repo.git', $slug, 'credentials embedded in the remote URL must never survive into the slug');
    }

    public function test_workspace_without_git_falls_back_to_the_path_itself(): void
    {
        $dir = $this->tmpDir();

        $this->assertSame($dir, WorkspaceOriginIdentity::slug($dir));
        $this->assertSame(hash('sha256', $dir), WorkspaceOriginIdentity::hash($dir));
    }

    public function test_empty_workspace_yields_empty_identity(): void
    {
        $this->assertSame('', WorkspaceOriginIdentity::slug(''));
        $this->assertSame('', WorkspaceOriginIdentity::hash('  '));
    }
}
