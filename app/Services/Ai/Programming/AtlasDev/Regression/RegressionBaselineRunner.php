<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationCommandRunner;

/**
 * E5 -- Runner contract for the regression baseline capture.
 *
 * The {@see RegressionBaselineService} delegates each baseline command
 * execution to a runner so the harness can substitute an in-memory fake
 * without spawning a real subprocess. The production binding reuses the
 * verification command runner (the baseline runs the SAME kind of test
 * commands the gate runs post-patch); tests inject a fake.
 *
 * This is the E5 analog of
 * {@see MutationCommandRunner}:
 * a SEPARATE, dedicated contract so the baseline capture evolves
 * independently and the fake is scoped to E5 tests.
 */
interface RegressionBaselineRunner
{
    /**
     * Run the given command in the workspace and return the observed
     * outcome. The runner MUST honor the workspace boundary.
     */
    public function run(string $command, string $workspace): VerificationCommandResult;
}
