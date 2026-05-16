<?php

declare(strict_types=1);

namespace App\Domain\Quota;

interface LockInterface
{
    /** Acquire the lock or block until available. */
    public function acquire(string $key): void;

    public function release(string $key): void;
}
