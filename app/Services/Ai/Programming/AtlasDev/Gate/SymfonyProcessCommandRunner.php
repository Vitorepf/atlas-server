<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production implementation of AtlasDevVerificationCommandRunnerContract.
 *
 * Boundaries:
 *   - workspace must be an existing directory; otherwise we refuse to run
 *     (we never silently fall back to base_path());
 *   - UnsafeCommandPolicy rejects dangerous shell tokens before exec;
 *   - timeout is honoured via Symfony Process; on timeout, the result is
 *     marked timedOut=true and stdout/stderr capture whatever was buffered.
 */
final class SymfonyProcessCommandRunner implements AtlasDevVerificationCommandRunnerContract
{
    public function run(string $command, string $workspace, int $timeoutSeconds): VerificationCommandResult
    {
        $reason = UnsafeCommandPolicy::reasonIfUnsafe($command);
        if ($reason !== null) {
            return new VerificationCommandResult(
                command: $command,
                exitCode: 126,
                stdout: '',
                stderr: 'VerificationCommandRunner rejected command: '.$reason,
                durationMs: 0,
                rejectedReason: $reason,
            );
        }

        if (! is_dir($workspace)) {
            return new VerificationCommandResult(
                command: $command,
                exitCode: 127,
                stdout: '',
                stderr: "VerificationCommandRunner: workspace '{$workspace}' does not exist.",
                durationMs: 0,
                rejectedReason: 'workspace_missing',
            );
        }

        $process = Process::fromShellCommandline(
            command: $command,
            cwd: $workspace,
            env: $this->processEnv(),
            input: null,
            timeout: max(1, $timeoutSeconds),
        );
        $start = hrtime(true);
        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new VerificationCommandResult(
            command: $command,
            exitCode: $process->getExitCode() ?? ($timedOut ? 124 : 1),
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationMs: $durationMs,
            timedOut: $timedOut,
        );
    }

    /**
     * @return array<string, string>
     */
    private function processEnv(): array
    {
        $env = [];
        foreach (['HOME', 'PATH', 'USER', 'LOGNAME'] as $key) {
            $value = getenv($key);
            if (! is_string($value) || trim($value) === '') {
                $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
            }
            if (is_string($value) && trim($value) !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
