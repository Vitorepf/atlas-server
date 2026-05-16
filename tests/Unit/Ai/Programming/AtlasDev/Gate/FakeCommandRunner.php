<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandRunner;

/**
 * In-memory runner for VerificationGate tests.
 *
 * Records every command/workspace/timeout call and returns a pre-queued
 * VerificationCommandResult. Honours UnsafeCommandPolicy: if a queued result
 * is missing we synthesise a rejection instead of running anything real.
 */
final class FakeCommandRunner implements VerificationCommandRunner
{
    /** @var list<array{command:string,workspace:string,timeout:int}> */
    public array $calls = [];

    /** @var list<VerificationCommandResult> */
    private array $queue = [];

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
                exitCode: 1,
                stdout: '',
                stderr: 'fake runner had no queued result',
                durationMs: 0,
            );
        }

        return array_shift($this->queue);
    }
}
