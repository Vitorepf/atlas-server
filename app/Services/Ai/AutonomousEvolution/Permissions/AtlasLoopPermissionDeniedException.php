<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Permissions;

use RuntimeException;

/**
 * Thrown by AtlasLoopPermissionLevelEnforcer when a phase attempts an operation whose required
 * authority level exceeds the level currently granted by the registry (or by the master switch
 * fail-closed strictest-level shim).
 */
final class AtlasLoopPermissionDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $phase,
        public readonly string $attemptedLevel,
        public readonly string $maxAllowedLevel,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'atlas_loop_permission_denied: phase=%s attempted=%s max_allowed=%s reason=%s',
            $phase,
            $attemptedLevel,
            $maxAllowedLevel,
            $reason,
        ));
    }
}
