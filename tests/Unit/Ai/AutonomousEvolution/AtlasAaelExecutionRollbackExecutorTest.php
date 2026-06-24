<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionPreImageSnapshotter;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionRollbackExecutor;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelRollbackSandboxViolation;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelRollbackUnknownMutationViolation;
use Tests\TestCase;

final class AtlasAaelExecutionRollbackExecutorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $cleanupPaths = [];

    public function test_it_restores_mutated_targets_byte_identically_and_returns_a_receipt(): void
    {
        $relativePath = 'app/Services/Ai/AutonomousEvolution/Runtime/Tmp/AtlasAaelRollbackFixture.php';
        $absolutePath = $this->createFixture($relativePath, "<?php\n\nreturn 'before';\n");
        $executionId = 'aael-rollback-restore';

        (new AtlasAaelExecutionPreImageSnapshotter)->snapshot($executionId, [
            'allowed_files' => [$relativePath],
        ]);
        $this->cleanupPaths[] = storage_path('atlas/aael/preimage/'.$executionId);

        file_put_contents($absolutePath, "<?php\n\nreturn 'after';\n");
        $postExecutionSha = hash_file('sha256', $absolutePath);

        $result = (new AtlasAaelExecutionRollbackExecutor)->execute($executionId, [
            $relativePath => $postExecutionSha,
        ]);

        $manifest = json_decode(
            (string) file_get_contents(storage_path('atlas/aael/preimage/'.$executionId.'/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('restored', $result['status']);
        $this->assertSame([$relativePath], $result['restored_paths']);
        $this->assertFileExists($result['receipt_path']);
        $this->assertSame((string) file_get_contents($manifest['targets'][0]['content_blob_path']), (string) file_get_contents($absolutePath));
        $this->assertSame($manifest['targets'][0]['sha256'], hash_file('sha256', $absolutePath));
    }

    public function test_second_invocation_is_a_no_op_and_returns_already_restored(): void
    {
        $relativePath = 'app/Services/Ai/AutonomousEvolution/Runtime/Tmp/AtlasAaelRollbackIdempotentFixture.php';
        $absolutePath = $this->createFixture($relativePath, "<?php\n\nreturn 'before';\n");
        $executionId = 'aael-rollback-idempotent';

        (new AtlasAaelExecutionPreImageSnapshotter)->snapshot($executionId, [
            'allowed_files' => [$relativePath],
        ]);
        $this->cleanupPaths[] = storage_path('atlas/aael/preimage/'.$executionId);

        file_put_contents($absolutePath, "<?php\n\nreturn 'after';\n");
        $postExecutionSha = hash_file('sha256', $absolutePath);

        $executor = new AtlasAaelExecutionRollbackExecutor;
        $executor->execute($executionId, [$relativePath => $postExecutionSha]);
        $second = $executor->execute($executionId, [$relativePath => $postExecutionSha]);

        $this->assertSame('already_restored', $second['status']);
        $this->assertSame([], $second['restored_paths']);
    }

    public function test_unknown_third_party_mutation_throws_and_preserves_the_file(): void
    {
        $relativePath = 'app/Services/Ai/AutonomousEvolution/Runtime/Tmp/AtlasAaelRollbackUnknownMutationFixture.php';
        $absolutePath = $this->createFixture($relativePath, "<?php\n\nreturn 'before';\n");
        $executionId = 'aael-rollback-unknown-mutation';

        (new AtlasAaelExecutionPreImageSnapshotter)->snapshot($executionId, [
            'allowed_files' => [$relativePath],
        ]);
        $this->cleanupPaths[] = storage_path('atlas/aael/preimage/'.$executionId);

        file_put_contents($absolutePath, "<?php\n\nreturn 'after';\n");
        $recordedPostExecutionSha = hash_file('sha256', $absolutePath);
        file_put_contents($absolutePath, "<?php\n\nreturn 'third-party';\n");
        $currentContents = (string) file_get_contents($absolutePath);

        $this->expectException(AtlasAaelRollbackUnknownMutationViolation::class);

        try {
            (new AtlasAaelExecutionRollbackExecutor)->execute($executionId, [
                $relativePath => $recordedPostExecutionSha,
            ]);
        } finally {
            $this->assertSame($currentContents, (string) file_get_contents($absolutePath));
        }
    }

    public function test_manifest_targets_outside_the_sandbox_floor_are_rejected_without_writes(): void
    {
        $executionId = 'aael-rollback-sandbox';
        $snapshotRoot = storage_path('atlas/aael/preimage/'.$executionId);
        $blobRoot = $snapshotRoot.'/blobs';
        $blobPath = $blobRoot.'/'.hash('sha256', 'outside').'.blob';

        if (! is_dir($blobRoot) && ! mkdir($blobRoot, 0777, true) && ! is_dir($blobRoot)) {
            $this->fail('Unable to create blob directory.');
        }

        file_put_contents($blobPath, 'outside');
        file_put_contents($snapshotRoot.'/manifest.json', json_encode([
            'schema_version' => 'atlas.aael.execution.preimage_snapshotter.v1',
            'execution_id' => $executionId,
            'targets' => [[
                'path' => 'app/Services/Ai/MarketingDomain/foo.php',
                'sha256' => hash('sha256', 'outside'),
                'bytes' => 7,
                'mode' => null,
                'mtime' => null,
                'content_blob_path' => $blobPath,
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->cleanupPaths[] = $snapshotRoot;

        $this->expectException(AtlasAaelRollbackSandboxViolation::class);

        (new AtlasAaelExecutionRollbackExecutor)->execute($executionId, [
            'app/Services/Ai/MarketingDomain/foo.php' => hash('sha256', 'outside-mutated'),
        ]);
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
