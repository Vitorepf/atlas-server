<?php

declare(strict_types=1);

namespace App\Domain\Quota;

/**
 * Deterministic in-memory lock used by tests. Records the order of
 * acquire/release calls so assertions can prove every critical section
 * was guarded.
 */
final class FakeLock implements LockInterface
{
    /** @var array<string,int> */
    private array $depth = [];

    /** @var list<string> */
    public array $log = [];

    public function acquire(string $key): void
    {
        $current = $this->depth[$key] ?? 0;
        if ($current !== 0) {
            throw new \RuntimeException("lock_already_held:{$key}");
        }
        $this->depth[$key] = 1;
        $this->log[] = "acquire:{$key}";
    }

    public function release(string $key): void
    {
        $current = $this->depth[$key] ?? 0;
        if ($current !== 1) {
            throw new \RuntimeException("lock_not_held:{$key}");
        }
        $this->depth[$key] = 0;
        $this->log[] = "release:{$key}";
    }
}
