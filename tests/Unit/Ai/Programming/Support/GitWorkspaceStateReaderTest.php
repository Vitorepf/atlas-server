<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\Support\GitWorkspaceStateReader;
use PHPUnit\Framework\TestCase;

final class GitWorkspaceStateReaderTest extends TestCase
{
    public function test_missing_workspace_is_honest(): void
    {
        $state = GitWorkspaceStateReader::read('/tmp/atlas-full-pass-no-such-workspace-'.uniqid('', true));

        $this->assertFalse($state['is_git']);
        $this->assertFalse($state['clean']);
        $this->assertSame('workspace_missing', $state['status']);
        $this->assertSame(0, $state['dirty_count']);
        $this->assertSame([], $state['dirty_files_sample']);
    }

    public function test_non_git_directory_is_honest(): void
    {
        $dir = sys_get_temp_dir().'/atlas-not-git-'.uniqid('', true);
        mkdir($dir);

        try {
            $state = GitWorkspaceStateReader::read($dir);
            $this->assertFalse($state['is_git']);
            $this->assertSame('not_git_workspace', $state['status']);
        } finally {
            @rmdir($dir);
        }
    }

    public function test_repo_root_reports_git_workspace(): void
    {
        // tests/Unit/Ai/Programming/Support → 5 levels up = repo root
        $root = realpath(dirname(__DIR__, 5));
        $this->assertNotFalse($root);
        $this->assertDirectoryExists($root.'/.git');

        $state = GitWorkspaceStateReader::read($root);

        $this->assertTrue($state['is_git'], 'atlas-server checkout must be a git workspace');
        $this->assertArrayHasKey('clean', $state);
        $this->assertArrayHasKey('dirty_count', $state);
        $this->assertContains($state['status'], ['clean', 'dirty', 'git_status_unavailable']);
        $this->assertIsInt($state['dirty_count']);
        $this->assertIsArray($state['dirty_files_sample']);
    }
}
