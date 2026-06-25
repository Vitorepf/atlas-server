<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Simulation;

use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationSandboxBuilder;
use App\Services\Ai\AutonomousEvolution\Simulation\SandboxHandle;
use LogicException;
use Tests\TestCase;

class AtlasLoopSimulationSandboxBuilderTest extends TestCase
{
    private string $sourceRoot = '';

    private string $sandboxParent = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->sourceRoot = sys_get_temp_dir().'/atlas-sim-src-'.$tag;
        $this->sandboxParent = sys_get_temp_dir().'/atlas-sim-out-'.$tag;
        mkdir($this->sourceRoot, 0o755, true);
        mkdir($this->sandboxParent, 0o755, true);

        // Initialize a deterministic mini-repo.
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        file_put_contents($this->sourceRoot.'/hello.txt', "hi\n");
        $this->git('add', 'hello.txt');
        $this->git('commit', '-q', '-m', 'seed');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sourceRoot);
        $this->rrmdir($this->sandboxParent);
        parent::tearDown();
    }

    public function test_build_returns_handle_with_required_facts_outside_live_source(): void
    {
        $builder = new AtlasLoopSimulationSandboxBuilder($this->sandboxParent);
        $handle = $builder->build($this->sourceRoot, 'first');

        self::assertInstanceOf(SandboxHandle::class, $handle);
        self::assertStringStartsWith($this->sandboxParent, $handle->sandboxPath);
        self::assertDirectoryExists($handle->sandboxPath);
        self::assertNotEmpty($handle->sourceCommitSha);
        self::assertNotSame('unknown', $handle->sourceCommitSha);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $handle->createdAt);
        self::assertSame(64, strlen($handle->contentChecksum));
        self::assertFileExists($handle->sandboxPath.'/hello.txt');
        // Outside app_path() — guarded by assertOutsideLiveSource.
        self::assertStringStartsNotWith(app_path(), $handle->sandboxPath);
    }

    public function test_two_builds_with_same_input_produce_identical_content_checksum(): void
    {
        $builder = new AtlasLoopSimulationSandboxBuilder($this->sandboxParent);
        $a = $builder->build($this->sourceRoot, 'twin');
        $b = $builder->build($this->sourceRoot, 'twin');

        self::assertSame($a->contentChecksum, $b->contentChecksum);
        self::assertSame($a->sourceCommitSha, $b->sourceCommitSha);
        self::assertSame($a->dirtyFiles, $b->dirtyFiles);
    }

    public function test_build_into_app_path_fails_closed_with_logic_exception_and_zero_writes(): void
    {
        $live = app_path();
        $mtimesBefore = $this->snapshotMtimes($live);

        $builder = new AtlasLoopSimulationSandboxBuilder($live);

        try {
            $builder->build($this->sourceRoot, 'evil');
            self::fail('expected LogicException');
        } catch (LogicException $e) {
            self::assertStringContainsString('live source', $e->getMessage());
        }

        $mtimesAfter = $this->snapshotMtimes($live);
        self::assertSame($mtimesBefore, $mtimesAfter, 'live source tree mtimes must be unchanged');
    }

    public function test_dirty_files_are_fingerprinted_with_sha256(): void
    {
        file_put_contents($this->sourceRoot.'/wip.txt', 'work-in-progress');
        $builder = new AtlasLoopSimulationSandboxBuilder($this->sandboxParent);
        $handle = $builder->build($this->sourceRoot, 'dirty');

        $paths = array_column($handle->dirtyFiles, 'path');
        self::assertContains('wip.txt', $paths);
        foreach ($handle->dirtyFiles as $entry) {
            self::assertSame(64, strlen($entry['sha256']));
        }
    }

    private function git(string ...$args): void
    {
        $cmd = 'git -C '.escapeshellarg($this->sourceRoot);
        foreach ($args as $a) {
            $cmd .= ' '.escapeshellarg($a);
        }
        exec($cmd.' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException('git failed: '.implode("\n", $out));
        }
    }

    /**
     * @return array<string,int>
     */
    private function snapshotMtimes(string $root): array
    {
        $map = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile()) {
                $map[$info->getPathname()] = $info->getMTime();
            }
        }
        ksort($map, SORT_STRING);

        return $map;
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
