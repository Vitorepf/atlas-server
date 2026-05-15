<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Services\AtlasCode\GitWorkspaceInspector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Tests for the read-only GitWorkspaceInspector. Creates a tiny temporary
 * git repo on disk, makes a real change, runs the inspector and asserts the
 * structured snapshot. Skipped when `git` is not available on PATH.
 */
class GitWorkspaceInspectorTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->gitAvailable()) {
            $this->markTestSkipped('git binary not available on PATH');
        }
        // Use system tmp dir (NOT inside the Atlas repo) so non-git tests
        // are honest — otherwise git would resolve to the enclosing repo.
        $base = sys_get_temp_dir().'/atlas-code-test-git-'.Str::random(10);
        $this->tmp = $base;
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    public function test_returns_blocker_when_path_missing(): void
    {
        $inspector = new GitWorkspaceInspector();
        $snap = $inspector->captureSnapshot('/tmp/atlas-nonexistent-'.bin2hex(random_bytes(4)));
        $this->assertFalse($snap['success']);
        $this->assertFalse($snap['workspace_path_exists']);
        $this->assertSame('workspace_path_missing_or_unreadable', $snap['blocker_reason']);
    }

    public function test_returns_blocker_for_non_git_directory(): void
    {
        File::put($this->tmp.'/README.md', 'not a repo');
        $inspector = new GitWorkspaceInspector();
        $snap = $inspector->captureSnapshot($this->tmp);
        $this->assertFalse($snap['success']);
        $this->assertTrue($snap['workspace_path_exists']);
        $this->assertFalse($snap['is_git']);
        $this->assertSame('not_a_git_repository', $snap['blocker_reason']);
    }

    public function test_captures_diff_and_files_for_real_git_repo(): void
    {
        $this->initRepo($this->tmp, [
            'README.md' => 'hello',
            'src/login.ts' => "export const Login = () => 'old'\n",
        ]);
        // Mutate after initial commit so we have a real diff vs HEAD.
        File::put($this->tmp.'/src/login.ts', "export const Login = () => 'new'\n");
        File::ensureDirectoryExists($this->tmp.'/src/__tests__');
        File::put($this->tmp.'/src/__tests__/login.spec.ts', "test('renders', () => {})\n");

        $inspector = new GitWorkspaceInspector();
        $snap = $inspector->captureSnapshot($this->tmp);

        $this->assertTrue($snap['success']);
        $this->assertTrue($snap['is_git']);
        $this->assertNotNull($snap['head_sha']);
        $this->assertNotNull($snap['branch']);
        $this->assertContains('src/login.ts', $snap['files_changed']);
        $this->assertContains('src/__tests__/login.spec.ts', $snap['files_changed']);
        $this->assertIsString($snap['diff_excerpt']);
        $this->assertStringContainsString('login.ts', $snap['diff_excerpt']);
        $this->assertNotNull($snap['diff_hash']);
    }

    private function gitAvailable(): bool
    {
        $p = new Process(['git', '--version']);
        $p->run();
        return $p->isSuccessful();
    }

    /**
     * @param  array<string, string>  $files
     */
    private function initRepo(string $cwd, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $full = $cwd.'/'.$rel;
            File::ensureDirectoryExists(dirname($full));
            File::put($full, $contents);
        }
        foreach ([
            ['git', 'init', '-q', '-b', 'main'],
            ['git', 'config', 'user.email', 'test@atlas.local'],
            ['git', 'config', 'user.name', 'Atlas Test'],
            ['git', 'config', 'commit.gpgsign', 'false'],
            ['git', 'add', '.'],
            ['git', 'commit', '-q', '-m', 'init'],
        ] as $cmd) {
            $p = new Process($cmd, $cwd);
            $p->run();
            if (! $p->isSuccessful()) {
                $this->fail('git setup failed: '.implode(' ', $cmd).' · '.$p->getErrorOutput());
            }
        }
    }
}
