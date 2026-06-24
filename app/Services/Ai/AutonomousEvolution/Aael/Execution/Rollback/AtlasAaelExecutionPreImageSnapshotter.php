<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback;

use RuntimeException;

final class AtlasAaelExecutionPreImageSnapshotter
{
    public const SCHEMA = 'atlas.aael.execution.preimage_snapshotter.v1';

    /**
     * @param  null|callable(string,string):void  $beforeLock
     */
    public function __construct(
        private readonly mixed $beforeLock = null,
    ) {}

    /**
     * @param  array<string,mixed>|list<mixed>  $executionPlan
     * @return array{
     *   schema_version:string,
     *   execution_id:string,
     *   manifest_path:string,
     *   targets:list<array{
     *     path:string,
     *     sha256:string,
     *     bytes:int,
     *     mode:int|null,
     *     mtime:int|null,
     *     content_blob_path:string
     *   }>
     * }
     */
    public function snapshot(string $executionId, array $executionPlan): array
    {
        $targets = $this->extractTargets($executionPlan);
        if ($targets === []) {
            throw new AtlasAaelRollbackEmptyTargetsViolation('aael_rollback_empty_targets');
        }

        $snapshotRoot = storage_path('atlas/aael/preimage/'.$executionId);
        $blobRoot = $snapshotRoot.'/blobs';

        if (! is_dir($blobRoot) && ! mkdir($blobRoot, 0777, true) && ! is_dir($blobRoot)) {
            throw new RuntimeException('aael_preimage_blob_root_create_failed');
        }

        $manifestTargets = [];
        foreach ($targets as $target) {
            $normalizedPath = $this->normalizeRelativePath($target);
            $this->assertSandboxFloor($normalizedPath);

            $absolutePath = base_path($normalizedPath);
            $initialHash = $this->hashPath($absolutePath);

            $content = '';
            $mode = null;
            $mtime = null;
            $postLockHash = $initialHash;

            if (is_file($absolutePath)) {
                if (is_callable($this->beforeLock)) {
                    ($this->beforeLock)($normalizedPath, $absolutePath);
                }

                $handle = fopen($absolutePath, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('aael_preimage_target_open_failed');
                }

                try {
                    if (! flock($handle, LOCK_EX)) {
                        throw new RuntimeException('aael_preimage_target_lock_failed');
                    }

                    clearstatcache(true, $absolutePath);
                    $postLockHash = hash_file('sha256', $absolutePath) ?: hash('sha256', '');
                    if ($postLockHash !== $initialHash) {
                        throw new AtlasAaelRollbackToctouViolation('aael_rollback_toctou_violation:'.$normalizedPath);
                    }

                    rewind($handle);
                    $stream = stream_get_contents($handle);
                    if ($stream === false) {
                        throw new RuntimeException('aael_preimage_target_read_failed');
                    }

                    $content = $stream;
                    $stat = fstat($handle) ?: [];
                    $mode = isset($stat['mode']) && is_int($stat['mode']) ? $stat['mode'] : null;
                    $mtime = isset($stat['mtime']) && is_int($stat['mtime']) ? $stat['mtime'] : null;
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }

            $blobHash = $postLockHash;
            $blobPath = $blobRoot.'/'.$blobHash.'.blob';
            $this->writeAndSync($blobPath, $content);

            $manifestTargets[] = [
                'path' => $normalizedPath,
                'sha256' => $postLockHash,
                'bytes' => strlen($content),
                'mode' => $mode,
                'mtime' => $mtime,
                'content_blob_path' => $blobPath,
            ];
        }

        $manifestPath = $snapshotRoot.'/manifest.json';
        $manifest = [
            'schema_version' => self::SCHEMA,
            'execution_id' => $executionId,
            'targets' => $manifestTargets,
        ];

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->writeAndSync($manifestPath, $encoded."\n");

        return [
            'schema_version' => self::SCHEMA,
            'execution_id' => $executionId,
            'manifest_path' => $manifestPath,
            'targets' => $manifestTargets,
        ];
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $executionPlan
     * @return list<string>
     */
    private function extractTargets(array $executionPlan): array
    {
        $targets = [];

        if (isset($executionPlan['allowed_files']) && is_array($executionPlan['allowed_files'])) {
            foreach ($executionPlan['allowed_files'] as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $targets[] = $path;
                }
            }
        }

        if (isset($executionPlan['tasks']) && is_array($executionPlan['tasks'])) {
            foreach ($executionPlan['tasks'] as $task) {
                if (! is_array($task) || ! isset($task['allowed_files']) || ! is_array($task['allowed_files'])) {
                    continue;
                }

                foreach ($task['allowed_files'] as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $targets[] = $path;
                    }
                }
            }
        }

        $normalized = array_map([$this, 'normalizeRelativePath'], $targets);
        $normalized = array_values(array_unique(array_filter($normalized, static fn (string $path): bool => $path !== '')));
        sort($normalized);

        return $normalized;
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

    private function hashPath(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return hash('sha256', '');
        }

        return hash_file('sha256', $absolutePath) ?: hash('sha256', '');
    }

    private function writeAndSync(string $path, string $contents): void
    {
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('aael_preimage_write_open_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('aael_preimage_write_lock_failed');
            }

            ftruncate($handle, 0);
            rewind($handle);

            $written = fwrite($handle, $contents);
            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException('aael_preimage_write_failed');
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

final class AtlasAaelRollbackSandboxViolation extends RuntimeException {}

final class AtlasAaelRollbackToctouViolation extends RuntimeException {}

final class AtlasAaelRollbackEmptyTargetsViolation extends RuntimeException {}
