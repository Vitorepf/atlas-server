<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionPreImageSnapshotter;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelRollbackEmptyTargetsViolation;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelRollbackSandboxViolation;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelRollbackToctouViolation;
use Tests\TestCase;

final class AtlasAaelExecutionPreImageSnapshotterTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $cleanupPaths = [];

    public function test_it_writes_a_manifest_with_one_entry_per_target_and_content_addressed_blob(): void
    {
        $relativePath = 'app/Services/Ai/AutonomousEvolution/Runtime/Tmp/AtlasAaelSnapshotFixture.php';
        $absolutePath = $this->createFixture($relativePath, "<?php\n\nreturn 'aael-preimage';\n");
        $executionId = 'aael-preimage-manifest';

        $result = (new AtlasAaelExecutionPreImageSnapshotter)->snapshot($executionId, [
            'allowed_files' => [$relativePath],
        ]);

        $manifestPath = storage_path('atlas/aael/preimage/'.$executionId.'/manifest.json');
        $this->cleanupPaths[] = storage_path('atlas/aael/preimage/'.$executionId);

        $this->assertSame($manifestPath, $result['manifest_path']);
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.aael.execution.preimage_snapshotter.v1', $manifest['schema_version']);
        $this->assertCount(1, $manifest['targets']);

        $entry = $manifest['targets'][0];
        $expectedSha = hash_file('sha256', $absolutePath);

        $this->assertSame($relativePath, $entry['path']);
        $this->assertSame($expectedSha, $entry['sha256']);
        $this->assertSame(filesize($absolutePath), $entry['bytes']);
        $this->assertIsInt($entry['mode']);
        $this->assertIsInt($entry['mtime']);
        $this->assertFileExists($entry['content_blob_path']);
        $this->assertSame((string) file_get_contents($absolutePath), (string) file_get_contents($entry['content_blob_path']));
    }

    public function test_it_rejects_targets_outside_the_loop_sandbox_floor(): void
    {
        $this->expectException(AtlasAaelRollbackSandboxViolation::class);

        (new AtlasAaelExecutionPreImageSnapshotter)->snapshot('aael-preimage-sandbox', [
            'allowed_files' => ['app/Services/Ai/MarketingDomain/foo.php'],
        ]);
    }

    public function test_it_throws_when_a_file_changes_between_initial_hash_and_post_lock_hash(): void
    {
        $relativePath = 'app/Services/Ai/AutonomousEvolution/Runtime/Tmp/AtlasAaelToctouFixture.php';
        $absolutePath = $this->createFixture($relativePath, "<?php\n\nreturn 'before';\n");
        $executionId = 'aael-preimage-toctou';
        $this->cleanupPaths[] = storage_path('atlas/aael/preimage/'.$executionId);

        $snapshotter = new AtlasAaelExecutionPreImageSnapshotter(
            static function (string $path, string $resolvedPath) use ($relativePath): void {
                if ($path !== $relativePath) {
                    return;
                }

                file_put_contents($resolvedPath, "<?php\n\nreturn 'after';\n");
            }
        );

        $this->expectException(AtlasAaelRollbackToctouViolation::class);

        $snapshotter->snapshot($executionId, [
            'allowed_files' => [$relativePath],
        ]);
    }

    public function test_it_rejects_empty_allowed_files_without_writing_a_manifest(): void
    {
        $executionId = 'aael-preimage-empty';
        $manifestPath = storage_path('atlas/aael/preimage/'.$executionId.'/manifest.json');
        $this->cleanupPaths[] = storage_path('atlas/aael/preimage/'.$executionId);

        try {
            (new AtlasAaelExecutionPreImageSnapshotter)->snapshot($executionId, [
                'allowed_files' => [],
            ]);
            $this->fail('Expected empty targets violation to be thrown.');
        } catch (AtlasAaelRollbackEmptyTargetsViolation) {
            $this->assertFileDoesNotExist($manifestPath);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }

            @rmdir($path);
        }

        parent::tearDown();
    }

    private function createFixture(string $relativePath, string $contents): string
    {
        $absolutePath = base_path($relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            $this->fail('Unable to create fixture directory.');
        }

        file_put_contents($absolutePath, $contents);
        $this->cleanupPaths[] = $absolutePath;
        $this->cleanupPaths[] = $directory;

        return $absolutePath;
    }
}
