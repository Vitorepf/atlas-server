<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Provider-independent execution seam retained by the live compatibility chain.
 *
 * The ACDE loop is not a live autonomous motor. This contract remains only
 * because active adapters and the service container still depend on the seam;
 * callers must not treat its presence as authorization to schedule atlas:loop.
 */
interface LoopExecutionDriver
{
    /**
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     * @return array<string,mixed>
     */
    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array;
}
