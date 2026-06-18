<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;

/**
 * E5 -- Production runner that delegates baseline command execution to the
 * verification command runner.
 *
 * The baseline capture runs the SAME kind of test commands the verification
 * gate runs post-patch (the scoped suite). In production this adapter wraps
 * the resolved {@see VerificationCommandRunner} so the baseline reuses the
 * existing Symfony-process-backed execution path (workspace boundary,
 * timeout, UnsafeCommandPolicy). Tests inject a fake
 * {@see RegressionBaselineRunner} directly and never touch this adapter.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M4 / E5,
 * e5-regression-baseline feature).
 */
final class VerificationRegressionBaselineRunner implements RegressionBaselineRunner
{
    public function __construct(
        private readonly VerificationCommandRunner $runner,
        private readonly int $timeoutSeconds = 300,
    ) {}

    public function run(string $command, string $workspace): VerificationCommandResult
    {
        return $this->runner->run($command, $workspace, $this->timeoutSeconds);
    }
}
