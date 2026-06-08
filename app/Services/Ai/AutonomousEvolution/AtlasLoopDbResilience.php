<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * The transient-DB resilience guard the campaign supervisor wraps its durable writes in.
 *
 * A 24h campaign must not die because Postgres blinked for a few seconds. {@see run}
 * executes a DB op and, on a TRANSIENT (connection-class) failure, reconnects the dead
 * PDO handle and retries with exponential backoff — bounded. After the budget is spent it
 * throws {@see AtlasLoopTransientDbException} so the supervisor can PARK the cycle instead
 * of crashing. A NON-transient error (constraint, syntax, logic) is rethrown immediately,
 * unmasked — resilience is for outages, never for bugs.
 *
 * Reconnect heals the connection: after SQLSTATE 08006 the PDO handle is dead, so a retry
 * on the same handle fails identically — {@see reconnect} via DB::reconnect() is what gives
 * the retry a chance. The reconnector/sleeper are injectable so tests exercise the retry
 * path without a real outage (and without DB::reconnect() wiping an in-memory test DB).
 */
final class AtlasLoopDbResilience
{
    /**
     * Case-insensitive needles that mark a DB error as a transient connection problem.
     * Covers Laravel's lost-connection set PLUS the exact Postgres text the supervisor
     * historically died on ("connection to server ... failed: Connection refused").
     */
    private const TRANSIENT_NEEDLES = [
        'connection refused',
        'connection to server',
        'could not connect',
        'no connection to the server',
        'server closed the connection',
        'terminating connection',
        'the database system is starting up',
        'the database system is shutting down',
        'in recovery',
        'connection timed out',
        'connection reset by peer',
        'gone away',
        'lost connection',
        'broken pipe',
        'sqlstate[08',
        'php_network_getaddresses',
    ];

    private int $maxAttempts = 5;

    private int $baseDelayMs = 500;

    private int $maxDelayMs = 30000;

    /** @var (Closure(int):void)|null test seam: replace the real usleep */
    private ?Closure $sleeper = null;

    /** @var (Closure():void)|null test seam: replace DB::reconnect() (which would wipe :memory:) */
    private ?Closure $reconnector = null;

    /** @var (Closure(string,int):?Throwable)|null test seam: inject a synthetic failure per call */
    private ?Closure $faultInjector = null;

    private int $calls = 0;

    public function setPolicy(int $maxAttempts, int $baseDelayMs, int $maxDelayMs): self
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->baseDelayMs = max(1, $baseDelayMs);
        $this->maxDelayMs = max($this->baseDelayMs, $maxDelayMs);

        return $this;
    }

    public function setSleeperForTesting(Closure $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    public function setReconnectorForTesting(Closure $reconnector): self
    {
        $this->reconnector = $reconnector;

        return $this;
    }

    /** @param  Closure(string,int):?Throwable  $injector  return a Throwable to simulate a failure, null to run for real */
    public function setFaultInjectorForTesting(Closure $injector): self
    {
        $this->faultInjector = $injector;

        return $this;
    }

    /**
     * Run a DB op with bounded retry + reconnect on transient failures.
     *
     * @template T
     *
     * @param  callable():T  $op
     * @return T
     *
     * @throws AtlasLoopTransientDbException when the op stays transiently-failed across the whole budget
     * @throws Throwable a non-transient (logic) error is rethrown immediately
     */
    public function run(callable $op, string $label = 'db'): mixed
    {
        $attempt = 0;
        while (true) {
            $this->calls++;
            try {
                $injected = $this->faultInjector !== null ? ($this->faultInjector)($label, $this->calls) : null;
                if ($injected !== null) {
                    throw $injected;
                }

                return $op();
            } catch (Throwable $e) {
                if (! self::isTransient($e)) {
                    throw $e; // genuine bug — surface it, never mask a logic error as an "outage"
                }
                $attempt++;
                if ($attempt >= $this->maxAttempts) {
                    throw new AtlasLoopTransientDbException(
                        sprintf('transient DB failure on "%s" after %d attempts: %s', $label, $attempt, $e->getMessage()),
                        0,
                        $e,
                    );
                }
                $this->reconnect();
                $this->sleepMs($this->backoffMs($attempt));
            }
        }
    }

    /**
     * Is this a transient connection-class failure (retry-worthy) rather than a logic error?
     * Recognised via SQLSTATE class (08xxx connection_exception incl. 08006; 57Pxx operator
     * intervention; 53300/53400 resource) OR a connection-text needle.
     */
    public static function isTransient(Throwable $e): bool
    {
        $pdo = $e instanceof PDOException ? $e : $e->getPrevious();
        if (! $e instanceof QueryException && ! $e instanceof PDOException && ! ($pdo instanceof PDOException)) {
            return false; // only DB-layer errors are eligible
        }

        $sqlState = '';
        foreach ([$e, $pdo] as $candidate) {
            if ($candidate instanceof Throwable) {
                $code = (string) $candidate->getCode();
                if (preg_match('/^[0-9A-Z]{5}$/', $code) === 1) {
                    $sqlState = $code;
                    break;
                }
            }
        }
        if ($sqlState !== '') {
            if (str_starts_with($sqlState, '08')) {
                return true; // connection_exception family (08006 = connection failure)
            }
            if (in_array($sqlState, ['57P01', '57P02', '57P03', '53300', '53400'], true)) {
                return true; // admin/crash shutdown, cannot_connect_now, too_many_connections
            }
        }

        $message = $e->getMessage().' '.($pdo instanceof Throwable ? $pdo->getMessage() : '');
        foreach (self::TRANSIENT_NEEDLES as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function reconnect(): void
    {
        if ($this->reconnector !== null) {
            ($this->reconnector)();

            return;
        }
        try {
            DB::reconnect();
        } catch (Throwable) {
            // still unreachable — the next attempt re-tries; do not let reconnect itself abort
        }
    }

    private function backoffMs(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** max(0, $attempt - 1));

        return (int) min($this->maxDelayMs, $delay);
    }

    private function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }
        usleep($ms * 1000);
    }
}
