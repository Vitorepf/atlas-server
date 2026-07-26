<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * Runs the scoped infection invocation produced by {@see MutationTestingAdapter}.
 *
 * The adapter builds the infection command line and delegates execution to
 * a runner so the harness can substitute an in-memory fake
 * (FakeMutationCommandRunner) without spawning a real subprocess. The
 * production binding is the Symfony-process-backed runner; tests inject a
 * fake via the adapter's constructor.
 *
 * This is the E3 analog of
 * App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract.
 * It is intentionally a SEPARATE contract: the infection invocation is a
 * dedicated, scoped subprocess (with its own workspace, timeout, and
 * summary-JSON artifact), not a verification-floor command, and the two
 * contracts evolve independently.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M3 / E3).
 */
interface MutationCommandRunner
{
    /**
     * Run the given infection command in the workspace and return the
     * observed outcome. The runner is responsible for honoring the workspace
     * boundary (refusing to run if the workspace does not exist) and for
     * capturing the infection summary JSON so the adapter can read the real
     * reported MSI (VAL-E3-007: the verdict is a pure function of the real
     * reported MSI, never a self-declared score).
     *
     * The adapter is the sole caller; the command is already scoped to the
     * patch's touched files (VAL-E3-001) so the runner never has to inspect
     * the patch itself.
     */
    public function run(string $command, string $workspace, int $timeoutSeconds): MutationCommandOutcome;
}
