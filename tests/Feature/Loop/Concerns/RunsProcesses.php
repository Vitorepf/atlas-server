<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Concerns;

use Symfony\Component\Process\Process;

/**
 * Shared acceptance-command runner for Loop tests: run a subprocess (usually git or php) with a
 * 30s timeout and fail the test with the process output when it exits non-zero. Replaces the
 * byte-identical private runProcess()/runGit() helpers that were cloned across Loop test files.
 */
trait RunsProcesses
{
    /** @param list<string> $argv */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
