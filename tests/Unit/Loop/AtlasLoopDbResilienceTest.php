<?php

declare(strict_types=1);

namespace Tests\Unit\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDbResilience;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTransientDbException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit-proves the transient-DB guard the campaign supervisor leans on to ride out a Postgres
 * blip: a connection-class failure is reconnected + retried with exponential backoff and
 * survives; a genuine logic error (constraint) is rethrown UNMASKED with no retry; and a
 * sustained transient failure converts to AtlasLoopTransientDbException after a bounded budget.
 */
final class AtlasLoopDbResilienceTest extends TestCase
{
    /** A guard with the real connection/sleep replaced — a unit test must never touch a DB or block. */
    private function guard(int $attempts = 5, int $baseMs = 10, int $maxMs = 100): AtlasLoopDbResilience
    {
        return (new AtlasLoopDbResilience)
            ->setPolicy($attempts, $baseMs, $maxMs)
            ->setReconnectorForTesting(fn (): null => null)
            ->setSleeperForTesting(fn (int $ms): null => null);
    }

    /** The exact Postgres failure the supervisor historically died on. */
    private function transient(): QueryException
    {
        return new QueryException(
            'pgsql',
            'update atlas_loop_tasks set status = ?',
            ['pending'],
            new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433 failed: Connection refused'),
        );
    }

    public function test_transient_failure_is_retried_then_succeeds(): void
    {
        $reconnects = 0;
        $sleeps = [];
        $guard = $this->guard()
            ->setReconnectorForTesting(function () use (&$reconnects): void {
                $reconnects++;
            })
            ->setSleeperForTesting(function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            });

        $calls = 0;
        $value = $guard->run(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->transient();
            }

            return 'ok';
        }, 'reclaim');

        $this->assertSame('ok', $value);
        $this->assertSame(3, $calls);      // 2 transient throws + 1 success
        $this->assertSame(2, $reconnects); // reconnected before each retry — heals the dead PDO handle
        $this->assertCount(2, $sleeps);    // backed off before each retry
    }

    public function test_non_transient_error_is_rethrown_immediately_unmasked(): void
    {
        $reconnects = 0;
        $guard = $this->guard()->setReconnectorForTesting(function () use (&$reconnects): void {
            $reconnects++;
        });

        $logicError = new QueryException('pgsql', 'insert into atlas_loop_proposals ...', [], new PDOException('SQLSTATE[23505] duplicate key value violates unique constraint'));
        $calls = 0;
        try {
            $guard->run(function () use (&$calls, $logicError): void {
                $calls++;
                throw $logicError;
            }, 'certify');
            $this->fail('expected the logic error to propagate');
        } catch (QueryException $e) {
            $this->assertSame($logicError, $e); // the exact same exception, not masked as an "outage"
        }
        $this->assertSame(1, $calls);     // a bug is never retried
        $this->assertSame(0, $reconnects);
    }

    public function test_sustained_transient_failure_becomes_transient_marker_after_budget(): void
    {
        $guard = $this->guard(attempts: 3);

        $calls = 0;
        try {
            $guard->run(function () use (&$calls): void {
                $calls++;
                throw $this->transient();
            }, 'heartbeat');
            $this->fail('expected AtlasLoopTransientDbException after the retry budget');
        } catch (AtlasLoopTransientDbException $e) {
            $this->assertInstanceOf(QueryException::class, $e->getPrevious());
            $this->assertStringContainsString('heartbeat', $e->getMessage());
        }
        $this->assertSame(3, $calls); // exactly the bounded attempts, then give up
    }

    public function test_backoff_is_exponential_and_capped(): void
    {
        $sleeps = [];
        $guard = $this->guard(attempts: 6, baseMs: 100, maxMs: 400)
            ->setSleeperForTesting(function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            });

        try {
            $guard->run(function (): void {
                throw $this->transient();
            }, 'claim');
        } catch (AtlasLoopTransientDbException) {
            // expected after the budget
        }

        // 5 backoffs before the 6th attempt gives up: 100, 200, then capped at 400.
        $this->assertSame([100, 200, 400, 400, 400], $sleeps);
    }

    public function test_is_transient_classifies_connection_errors_but_not_logic_or_non_db(): void
    {
        $cannotConnect = new class('the database system is in recovery') extends PDOException
        {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '57P03'; // cannot_connect_now (SQLSTATE-code path)
            }
        };

        $this->assertTrue(AtlasLoopDbResilience::isTransient($this->transient()));                 // 08006 via message
        $this->assertTrue(AtlasLoopDbResilience::isTransient($cannotConnect));                      // 57P03 via code
        $this->assertTrue(AtlasLoopDbResilience::isTransient(new PDOException('server closed the connection unexpectedly')));
        $this->assertFalse(AtlasLoopDbResilience::isTransient(new QueryException('pgsql', 'x', [], new PDOException('SQLSTATE[23505] duplicate key'))));
        $this->assertFalse(AtlasLoopDbResilience::isTransient(new RuntimeException('provider call timed out'))); // not a DB error
        $this->assertFalse(AtlasLoopDbResilience::isTransient(new InvalidArgumentException('bad input')));
    }
}
