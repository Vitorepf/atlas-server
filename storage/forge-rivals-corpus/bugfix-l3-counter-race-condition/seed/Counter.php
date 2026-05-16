<?php

declare(strict_types=1);

namespace App\Domain\Quota;

final class Counter
{
    private int $value;

    public function __construct(int $initial)
    {
        $this->value = $initial;
    }

    /**
     * BUG: read-modify-write without locking. Two concurrent callers
     * can both read the same value and both write value-1, losing one
     * decrement. The arm must inject a LockInterface and run the
     * read-modify-write inside an acquire/release window.
     */
    public function decrement(): int
    {
        $current = $this->value;
        $next = $current - 1;
        $this->value = $next;

        return $next;
    }

    public function value(): int
    {
        return $this->value;
    }
}
