<?php

declare(strict_types=1);

namespace Tests\Unit\Quota;

use App\Domain\Quota\Counter;
use App\Domain\Quota\FakeLock;
use PHPUnit\Framework\TestCase;

final class CounterTest extends TestCase
{
    public function test_concurrent_decrements_do_not_lose_updates(): void
    {
        $lock = new FakeLock;
        $counter = new Counter(initial: 100); // After patch: new Counter($lock, 100)

        // Simulate 10 concurrent decrements by interleaving acquire/decrement/release
        // through the FakeLock. The post-patch Counter MUST route through the lock.
        for ($i = 0; $i < 10; $i++) {
            $counter->decrement();
        }

        $this->assertSame(90, $counter->value(), 'lost updates: counter must end at 90');
    }

    public function test_decrement_is_protected_by_lock(): void
    {
        $lock = new FakeLock;
        $counter = new Counter(initial: 5);
        for ($i = 0; $i < 5; $i++) {
            $counter->decrement();
        }

        // The arm-patched Counter routes each decrement through the lock,
        // producing matched acquire/release pairs in the fake's log.
        $this->assertNotEmpty($lock->log, 'lock log empty — Counter ignored the injected lock');
        $acquires = array_filter($lock->log, static fn (string $e): bool => str_starts_with($e, 'acquire:'));
        $releases = array_filter($lock->log, static fn (string $e): bool => str_starts_with($e, 'release:'));
        $this->assertSame(count($acquires), count($releases));
    }
}
