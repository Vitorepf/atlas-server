<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Simulation;

use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationDryRunner;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationSandboxBuilder;
use App\Services\Ai\AutonomousEvolution\Simulation\DryRunReceipt;
use App\Services\Ai\AutonomousEvolution\Simulation\SandboxHandle;
use Tests\TestCase;

// SandboxHandle is declared in the same file as AtlasLoopSimulationSandboxBuilder; force its load.
\class_exists(AtlasLoopSimulationSandboxBuilder::class);

class AtlasLoopSimulationDryRunnerTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-dryrun-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0o755, true);
        // Make the sandbox a git work-tree so `git apply` is happy.
        exec('git -C '.escapeshellarg($this->sandbox).' init -q 2>&1');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
        parent::tearDown();
    }

    private function handle(): SandboxHandle
    {
        return new SandboxHandle(
            sandboxPath: $this->sandbox,
            sourceCommitSha: 'abc123',
            dirtyFiles: [],
            createdAt: '2026-06-25T00:00:00Z',
            contentChecksum: str_repeat('0', 64),
            seed: 'test',
        );
    }

    public function test_run_applies_diff_only_inside_sandbox_and_does_not_touch_live_source(): void
    {
        $sandboxFile = 'hello.txt';
        file_put_contents($this->sandbox.'/'.$sandboxFile, "old\n");

        // A live-source file that ALSO exists at the same relative path concept.
        $liveFile = base_path('artisan');
        self::assertFileExists($liveFile);
        $preLiveSha = hash_file('sha256', $liveFile);
        $preLiveMtime = filemtime($liveFile);

        $diff = "--- a/hello.txt\n+++ b/hello.txt\n@@ -1 +1 @@\n-old\n+new\n";

        $runner = new AtlasLoopSimulationDryRunner();
        $receipt = $runner->run($this->handle(), $diff);

        self::assertInstanceOf(DryRunReceipt::class, $receipt);
        self::assertSame(0, $receipt->patchApplyExitCode);
        self::assertSame("new\n", file_get_contents($this->sandbox.'/'.$sandboxFile));

        // Live source untouched.
        clearstatcache();
        self::assertSame($preLiveSha, hash_file('sha256', $liveFile));
        self::assertSame($preLiveMtime, filemtime($liveFile));
    }

    public function test_receipt_carries_facts_only_with_no_score_or_verdict_fields(): void
    {
        file_put_contents($this->sandbox.'/foo.php', "<?php\n\$a = 1;\n");
        $diff = "--- a/foo.php\n+++ b/foo.php\n@@ -1,2 +1,2 @@\n <?php\n-\$a = 1;\n+\$a = 2;\n";

        $runner = new AtlasLoopSimulationDryRunner();
        $receipt = $runner->run($this->handle(), $diff);
        $array = $receipt->toArray();

        foreach (['patch_apply_exit_code', 'changed_files', 'php_lint_exit_code_per_file', 'frozen_test_exit_code', 'frozen_test_stdout_tail', 'run_started_at', 'run_finished_at'] as $k) {
            self::assertArrayHasKey($k, $array);
        }
        foreach (['score', 'quality', 'rating', 'grade', 'verdict', 'accept', 'reject', 'provider_response'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $array);
        }
        self::assertArrayHasKey('foo.php', $receipt->phpLintExitCodePerFile);
        self::assertSame(0, $receipt->phpLintExitCodePerFile['foo.php']);
    }

    public function test_same_input_twice_yields_identical_apply_exit_and_changed_set(): void
    {
        // Two independent sandboxes seeded identically.
        $sb2 = sys_get_temp_dir().'/atlas-dryrun-twin-'.bin2hex(random_bytes(4));
        mkdir($sb2, 0o755, true);
        exec('git -C '.escapeshellarg($sb2).' init -q 2>&1');
        try {
            file_put_contents($this->sandbox.'/hello.txt', "old\n");
            file_put_contents($sb2.'/hello.txt', "old\n");

            $diff = "--- a/hello.txt\n+++ b/hello.txt\n@@ -1 +1 @@\n-old\n+new\n";

            $runner = new AtlasLoopSimulationDryRunner();
            $a = $runner->run($this->handle(), $diff);
            $b = $runner->run(new SandboxHandle(
                sandboxPath: $sb2,
                sourceCommitSha: 'abc123',
                dirtyFiles: [],
                createdAt: '2026-06-25T00:00:00Z',
                contentChecksum: str_repeat('0', 64),
                seed: 'twin',
            ), $diff);

            self::assertSame($a->patchApplyExitCode, $b->patchApplyExitCode);
            self::assertSame(
                array_column($a->changedFiles, 'path'),
                array_column($b->changedFiles, 'path'),
            );
            self::assertSame($a->phpLintExitCodePerFile, $b->phpLintExitCodePerFile);
        } finally {
            $this->rrmdir($sb2);
        }
    }

    public function test_invalid_diff_yields_nonzero_patch_apply_exit_without_throwing(): void
    {
        $runner = new AtlasLoopSimulationDryRunner();
        $receipt = $runner->run($this->handle(), "this is not a valid unified diff\n");

        self::assertNotSame(0, $receipt->patchApplyExitCode);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
