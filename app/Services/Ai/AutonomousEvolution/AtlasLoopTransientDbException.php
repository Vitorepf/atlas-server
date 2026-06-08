<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use RuntimeException;

/**
 * Raised by {@see AtlasLoopDbResilience} when a DB op stayed in a TRANSIENT failure
 * (connection refused / server gone away / cannot-connect-now) across the whole bounded
 * retry budget. It is the supervisor's signal to PARK the cycle (log + skip + continue),
 * not crash — a brief Postgres blip during a 24h run is a recoverable hiccup. Distinct
 * from a genuine logic error (constraint/syntax), which the guard rethrows untouched so
 * it still surfaces immediately.
 */
final class AtlasLoopTransientDbException extends RuntimeException {}
