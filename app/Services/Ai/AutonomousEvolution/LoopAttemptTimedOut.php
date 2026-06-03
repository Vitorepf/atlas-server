<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use RuntimeException;

/**
 * Thrown by {@see TimeBoundedLoopExecutionDriver} when a single execution attempt
 * overruns its hard per-attempt deadline. Caught internally and converted to a
 * timed_out result so the loop advances instead of wedging.
 */
final class LoopAttemptTimedOut extends RuntimeException {}
