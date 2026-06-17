<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;

/**
 * In-memory runner for VerificationGate tests.
 *
 * Records every command/workspace/timeout call and returns a pre-queued
 * VerificationCommandResult. Honours UnsafeCommandPolicy: if a queued result
 * is missing we synthesise a rejection instead of running anything real.
 *
 * With M1 floor, the gate may run additional commands (impacted tests, php -l,
 * pint) that existing tests didn't anticipate. Set $defaultSuccess = true
 * to have unlisted commands pass instead of fail.
 */
final class FakeCommandRunner implements VerificationCommandRunner
{
    /** @var list<array{command:string,workspace:string,timeout:int}> */
    public array $calls = [];

    /** @var list<VerificationCommandResult> */
    private array $queue = [];

    /**
     * If true, commands without a queued result succeed instead of failing.
     * With M1 floor, this is the default since many tests don't queue all
     * floor commands (php -l, pint, impacted tests).
     *
     * Set to false explicitly if your test needs to verify that unlisted
     * commands cause failures.
     */
    public bool $defaultSuccess = true;

    public function queue(VerificationCommandResult $result): void
    {
        $this->queue[] = $result;
    }

    public function run(string $command, string $workspace, int $timeoutSeconds): VerificationCommandResult
    {
        $this->calls[] = [
            'command' => $command,
            'workspace' => $workspace,
            'timeout' => $timeoutSeconds,
        ];

        $rejected = UnsafeCommandPolicy::reasonIfUnsafe($command);
        if ($rejected !== null) {
            return new VerificationCommandResult(
                command: $command,
                exitCode: 126,
                stdout: '',
                stderr: 'rejected by fake runner: '.$rejected,
                durationMs: 0,
                rejectedReason: $rejected,
            );
        }

        if ($this->queue === []) {
            return new VerificationCommandResult(
                command: $command,
                exitCode: $this->defaultSuccess ? 0 : 1,
                stdout: $this->defaultSuccess ? 'ok (default success)' : '',
                stderr: $this->defaultSuccess ? '' : 'fake runner had no queued result',
                durationMs: 0,
            );
        }

        return array_shift($this->queue);
    }
}
