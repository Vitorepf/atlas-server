<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

/**
 * Narrow contract for executing validation commands. VerificationGate depends
 * on this interface so tests can inject a deterministic fake runner that
 * returns canned (exit, stdout, stderr, duration_ms) without touching the
 * shell.
 *
 * Production wiring uses SymfonyProcessCommandRunner. Both implementations
 * MUST reject commands flagged dangerous via UnsafeCommandPolicy.
 */
interface VerificationCommandRunner
{
    public function run(string $command, string $workspace, int $timeoutSeconds): VerificationCommandResult;
}
