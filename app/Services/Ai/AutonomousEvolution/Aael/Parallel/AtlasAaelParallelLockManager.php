<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Parallel;

/**
 * Immutable lock handle returned by {@see AtlasAaelParallelLockManager::acquire()}.
 */
final class LockHandle
{
    /**
     * @param  list<string>  $writeSet
     */
    public function __construct(
        public readonly string $lockId,
        public readonly string $stepId,
        public readonly array $writeSet,
        public readonly int $acquiredAtUnix,
    ) {}
}

/**
 * Per-file write-set lock manager for Aael grind workers. Locks are persisted to a deterministic
 * on-disk JSON ledger guarded by flock(LOCK_EX) so multiple parallel processes share the same
 * view.
 *
 * Overlap semantics — canonical Atlas predicate: two write-sets overlap iff
 *   exists path in A such that exists path in B, where path_normalize(A) == path_normalize(B)
 *     OR path_normalize(A) starts with path_normalize(B)/
 *     OR path_normalize(B) starts with path_normalize(A)/
 *
 * Fail-closed: any IO error, malformed ledger, or overlap causes acquire() to return null. The
 * caller MUST treat null as "did not acquire" — there is no silent grant.
 */
final class AtlasAaelParallelLockManager
{
    public function __construct(private readonly string $ledgerPath) {}

    /**
     * @param  list<string>  $writeSet
     */
    public function acquire(string $stepId, array $writeSet): ?LockHandle
    {
        if ($stepId === '' || $writeSet === []) {
            return null;
        }
        $normalized = array_values(array_unique(array_map([$this, 'normalizePath'], array_map('strval', $writeSet))));

        $dir = \dirname($this->ledgerPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return null;
        }
        $fh = @fopen($this->ledgerPath, 'c+');
        if ($fh === false) {
            return null;
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                return null;
            }
            $contents = stream_get_contents($fh);
            $state = is_string($contents) && $contents !== '' ? json_decode($contents, true) : ['locks' => []];
            if (! is_array($state) || ! isset($state['locks']) || ! is_array($state['locks'])) {
                // Malformed ledger ⇒ fail-closed.
                return null;
            }

            foreach ($state['locks'] as $existing) {
                if (! is_array($existing)) {
                    return null;
                }
                $existingSet = array_values(array_map('strval', (array) ($existing['write_set'] ?? [])));
                if ($this->overlaps($normalized, $existingSet)) {
                    return null;
                }
            }

            $handle = new LockHandle(
                lockId: bin2hex(random_bytes(8)),
                stepId: $stepId,
                writeSet: $normalized,
                acquiredAtUnix: time(),
            );
            $state['locks'][] = [
                'lock_id' => $handle->lockId,
                'step_id' => $handle->stepId,
                'write_set' => $handle->writeSet,
                'acquired_at_unix' => $handle->acquiredAtUnix,
            ];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $handle;
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function release(LockHandle $handle): void
    {
        $fh = @fopen($this->ledgerPath, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                return;
            }
            $contents = stream_get_contents($fh);
            $state = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
            if (! is_array($state) || ! is_array($state['locks'] ?? null)) {
                return;
            }
            $state['locks'] = array_values(array_filter(
                $state['locks'],
                static fn (array $row): bool => (string) ($row['lock_id'] ?? '') !== $handle->lockId,
            ));
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        foreach ($a as $pa) {
            $pa = $this->normalizePath($pa);
            foreach ($b as $pb) {
                $pb = $this->normalizePath($pb);
                if ($pa === $pb) {
                    return true;
                }
                if (str_starts_with($pa.'/', $pb.'/') || str_starts_with($pb.'/', $pa.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
