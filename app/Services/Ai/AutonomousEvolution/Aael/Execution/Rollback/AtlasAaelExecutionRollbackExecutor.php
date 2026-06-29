<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback;

require_once __DIR__.'/AtlasAaelExecutionPreImageSnapshotter.php';

use RuntimeException;

final class AtlasAaelExecutionRollbackExecutor
{
    public const SCHEMA = 'atlas.aael.execution.rollback_executor.v1';

    /**
     * @param  array<string,string>  $recordedPostExecutionShas
     * @return array{
     *   schema_version:string,
     *   status:string,
     *   execution_id:string,
     *   restored_paths:list<string>,
     *   manifest_path:string,
     *   receipt_path:string
     * }
     */
    public function execute(string $executionId, array $recordedPostExecutionShas = []): array
    {
        $snapshotRoot = storage_path('atlas/aael/preimage/'.$executionId);
        $manifestPath = $snapshotRoot.'/manifest.json';
        $receiptPath = $snapshotRoot.'/restore_receipt.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException('aael_rollback_manifest_missing');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $targets = array_values(array_filter((array) ($manifest['targets'] ?? []), 'is_array'));
        $this->assertManifestIntegrity($manifest, $targets);

        if (is_file($receiptPath)) {
            return [
                'schema_version' => self::SCHEMA,
                'status' => 'already_restored',
                'execution_id' => $executionId,
                'restored_paths' => [],
                'manifest_path' => $manifestPath,
                'receipt_path' => $receiptPath,
            ];
        }

        $restoredPaths = [];

        try {
            foreach ($targets as $target) {
                $path = $this->normalizeRelativePath((string) ($target['path'] ?? ''));
                $this->assertSandboxFloor($path);

                $absolutePath = base_path($path);
                $expectedSha = (string) ($target['sha256'] ?? '');
                $currentSha = is_file($absolutePath) ? (hash_file('sha256', $absolutePath) ?: hash('sha256', '')) : hash('sha256', '');

                if ($currentSha === $expectedSha) {
                    continue;
                }

                $recordedPostSha = (string) ($recordedPostExecutionShas[$path] ?? '');
                if ($recordedPostSha === '' || $currentSha !== $recordedPostSha) {
                    throw new AtlasAaelRollbackUnknownMutationViolation('aael_rollback_unknown_mutation:'.$path);
                }

                $blobPath = (string) ($target['content_blob_path'] ?? '');
                if (! is_file($blobPath)) {
                    throw new RuntimeException('aael_rollback_blob_missing');
                }

                $content = file_get_contents($blobPath);
                if ($content === false) {
                    throw new RuntimeException('aael_rollback_blob_read_failed');
                }

                $this->restoreAtomically($absolutePath, $content);

                clearstatcache(true, $absolutePath);
                $restoredSha = is_file($absolutePath) ? (hash_file('sha256', $absolutePath) ?: hash('sha256', '')) : hash('sha256', '');
                if ($restoredSha !== $expectedSha) {
                    throw new RuntimeException('aael_rollback_post_restore_sha_mismatch');
                }

                $restoredPaths[] = $path;
            }
        } catch (AtlasAaelRollbackSandboxViolation|AtlasAaelRollbackUnknownMutationViolation $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $partialPath = $snapshotRoot.'/partial_restore_receipt.json';
            $this->writeJson($partialPath, [
                'schema_version' => self::SCHEMA,
                'status' => 'partial_failure',
                'execution_id' => $executionId,
                'restored_paths' => $restoredPaths,
                'error' => $exception->getMessage(),
            ]);

            throw new AtlasAaelRollbackPartialFailure('aael_rollback_partial_failure', previous: $exception);
        }

        $receipt = [
            'schema_version' => self::SCHEMA,
            'status' => 'restored',
            'execution_id' => $executionId,
            'restored_paths' => $restoredPaths,
            'manifest_path' => $manifestPath,
        ];
        $this->writeJson($receiptPath, $receipt);

        return $receipt + ['receipt_path' => $receiptPath];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $targets
     */
    private function assertManifestIntegrity(array $manifest, array $targets): void
    {
        $shaRows = [];
        foreach ($targets as $target) {
            $shaRows[] = sprintf(
                '%s|%s|%s',
                (string) ($target['path'] ?? ''),
                (string) ($target['sha256'] ?? ''),
                (string) ($target['content_blob_path'] ?? ''),
            );
        }

        sort($shaRows);
        $computed = hash('sha256', implode("\n", $shaRows));

        if (! isset($manifest['sha_of_shas']) || ! is_string($manifest['sha_of_shas']) || $manifest['sha_of_shas'] === '') {
            throw new RuntimeException('aael_rollback_manifest_integrity_missing');
        }

        $recorded = (string) $manifest['sha_of_shas'];

        if ($computed !== $recorded) {
            throw new RuntimeException('aael_rollback_manifest_integrity_mismatch');
        }
    }

    private function assertSandboxFloor(string $path): void
    {
        foreach ([
            'app/Services/Ai/AutonomousEvolution/',
            'app/Services/Ai/SelfConstruction/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        throw new AtlasAaelRollbackSandboxViolation('aael_rollback_sandbox_violation:'.$path);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function restoreAtomically(string $absolutePath, string $content): void
    {
        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('aael_rollback_target_directory_create_failed');
        }

        $temporaryPath = $absolutePath.'.rollback.tmp';
        $handle = fopen($temporaryPath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('aael_rollback_tmp_open_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('aael_rollback_tmp_lock_failed');
            }

            ftruncate($handle, 0);
            rewind($handle);

            $written = fwrite($handle, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new RuntimeException('aael_rollback_tmp_write_failed');
            }

            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (! rename($temporaryPath, $absolutePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('aael_rollback_atomic_rename_failed');
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('aael_rollback_receipt_directory_create_failed');
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('aael_rollback_receipt_open_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('aael_rollback_receipt_lock_failed');
            }

            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, $encoded);
            if ($written === false || $written !== strlen($encoded)) {
                throw new RuntimeException('aael_rollback_receipt_write_failed');
            }

            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

final class AtlasAaelRollbackUnknownMutationViolation extends RuntimeException {}

final class AtlasAaelRollbackPartialFailure extends RuntimeException {}
