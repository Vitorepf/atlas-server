<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphIndexLock;
use Closure;
use Tests\TestCase;

/**
 * AP-815 · W-10 — proves per-workspace index locking with an injected, cross-process
 * fake lock backend (no cache driver / DB needed).
 */
final class CodeGraphIndexLockTest extends TestCase
{
    /** Shared "backend" simulating cross-process lock state. */
    private object $store;

    private Closure $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new class
        {
            /** @var array<string,bool> */
            public array $held = [];
        };

        $store = $this->store;
        $this->factory = static function (string $name, int $ttl) use ($store): object {
            return new class($store, $name)
            {
                public function __construct(private object $store, private string $name) {}

                public function get(): bool
                {
                    if (! empty($this->store->held[$this->name])) {
                        return false; // already held by someone (another process)
                    }
                    $this->store->held[$this->name] = true;

                    return true;
                }

                public function release(): void
                {
                    unset($this->store->held[$this->name]);
                }
            };
        };
    }

    public function test_a_second_process_cannot_acquire_while_held(): void
    {
        $p1 = new CodeGraphIndexLock($this->factory);
        $p2 = new CodeGraphIndexLock($this->factory);

        $this->assertTrue($p1->acquire('atlas-server'), 'first acquirer wins');
        $this->assertFalse($p2->acquire('atlas-server'), 'a concurrent run is blocked');

        $p1->release('atlas-server');
        $this->assertTrue($p2->acquire('atlas-server'), 'released lock can be re-acquired');
    }

    public function test_different_workspaces_do_not_block_each_other(): void
    {
        $lock = new CodeGraphIndexLock($this->factory);

        $this->assertTrue($lock->acquire('atlas-server'));
        $this->assertTrue($lock->acquire('blackink'), 'distinct workspaces lock independently');
        $this->assertTrue($lock->isHeld('atlas-server'));
        $this->assertTrue($lock->isHeld('blackink'));
    }

    public function test_with_lock_runs_fn_then_releases(): void
    {
        $p1 = new CodeGraphIndexLock($this->factory);
        $ran = false;

        $out = $p1->withLock('atlas-server', function () use (&$ran) {
            $ran = true;

            return 'indexed';
        });

        $this->assertTrue($ran);
        $this->assertTrue($out['acquired']);
        $this->assertSame('indexed', $out['result']);
        $this->assertFalse($p1->isHeld('atlas-server'), 'lock released after the block');

        // A fresh process can now take it.
        $p2 = new CodeGraphIndexLock($this->factory);
        $this->assertTrue($p2->acquire('atlas-server'));
    }

    public function test_fn_does_not_run_when_lock_unavailable(): void
    {
        $p1 = new CodeGraphIndexLock($this->factory);
        $p2 = new CodeGraphIndexLock($this->factory);
        $this->assertTrue($p1->acquire('atlas-server'));

        $ran = false;
        $out = $p2->withLock('atlas-server', function () use (&$ran) {
            $ran = true;

            return 'should-not-happen';
        });

        $this->assertFalse($ran, 'fn must NOT run without the lock');
        $this->assertFalse($out['acquired']);
        $this->assertNull($out['result']);
    }
}
