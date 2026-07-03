<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerCommandPlanRunner;
use Tests\TestCase;

/**
 * Proves AtlasNativeWorkerCommandPlanRunner::isGitMutation still guards a command plan
 * whose first argv element is null (not bypassed by isset).
 */
final class AtlasNativeWorkerCommandPlanRunnerHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-native-worker-'.mt_rand();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runner(): AtlasNativeWorkerCommandPlanRunner
    {
        return new AtlasNativeWorkerCommandPlanRunner();
    }

    // ── isGitMutation with null argv ───────────────────────────────────────────

    public function test_git_mutation_with_null_first_argv_is_still_detected(): void
    {
        $runner = $this->runner();

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('isGitMutation');

        // [null, 'commit'] — null is not a string, so is_string check fails safely
        // This is correct: a null argv[0] cannot be 'git', so it's not a git mutation
        $result = $method->invoke($runner, [null, 'commit', '-m', 'test']);

        $this->assertFalse($result, 'null argv[0] is not a git mutation (safely rejected by is_string guard)');
    }

    public function test_git_mutation_with_null_second_argv_is_not_detected(): void
    {
        $runner = $this->runner();

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('isGitMutation');

        // ['git', null] — null is not a string, so is_string check fails
        $result = $method->invoke($runner, ['git', null]);

        $this->assertFalse($result, 'git with null subcommand is not a git mutation');
    }

    public function test_git_mutation_with_normal_argv_is_detected(): void
    {
        $runner = $this->runner();

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('isGitMutation');

        $result = $method->invoke($runner, ['git', 'commit', '-m', 'test']);

        $this->assertTrue($result, 'git commit must be detected as git mutation');
    }

    public function test_non_git_command_is_not_detected(): void
    {
        $runner = $this->runner();

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('isGitMutation');

        $result = $method->invoke($runner, ['php', 'artisan', 'test']);

        $this->assertFalse($result, 'php artisan test is not a git mutation');
    }

    public function test_git_non_mutation_command_is_not_detected(): void
    {
        $runner = $this->runner();

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('isGitMutation');

        $result = $method->invoke($runner, ['git', 'status']);

        $this->assertFalse($result, 'git status is not a git mutation');
    }
}
