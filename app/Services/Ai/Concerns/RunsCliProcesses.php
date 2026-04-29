<?php

namespace App\Services\Ai\Concerns;

use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

trait RunsCliProcesses
{
    protected function runProcess(array $command, string $input, int $timeoutSeconds, ?string $cwd = null): AiProviderResult
    {
        $started = hrtime(true);
        $process = new Process($command, $cwd ?: (string) config('atlas.ai.workdir'));
        $process->setInput($input);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            return new AiProviderResult(
                ok: false,
                output: '',
                command: $this->redactCommand($command),
                exitCode: null,
                durationMs: $durationMs,
                stdout: $process->getOutput(),
                stderr: $process->getErrorOutput(),
                errorCode: 'timeout',
                errorMessage: $exception->getMessage(),
            );
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $output = $this->extractOutput($stdout);
        $errorCode = $process->isSuccessful() ? null : $this->classifyCliError($stdout, $stderr);

        if ($process->isSuccessful() && trim($output) === '') {
            $errorCode = 'empty_output';
        }

        return new AiProviderResult(
            ok: $process->isSuccessful() && $errorCode === null,
            output: $output,
            command: $this->redactCommand($command),
            exitCode: $process->getExitCode(),
            durationMs: $durationMs,
            stdout: $stdout,
            stderr: $stderr,
            errorCode: $errorCode,
            errorMessage: $errorCode ? trim($stderr) ?: trim($stdout) ?: $errorCode : null,
        );
    }

    protected function checkBinary(string $provider, string $binary): AiProviderHealthCheck
    {
        $process = new Process([$binary, '--version'], (string) config('atlas.ai.workdir'));
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return new AiProviderHealthCheck(
                provider: $provider,
                status: 'offline',
                message: trim($process->getErrorOutput()) ?: "Command [{$binary} --version] failed.",
                metadata: [
                    'exit_code' => $process->getExitCode(),
                    'stdout' => Str::limit($process->getOutput(), 500, '...'),
                    'stderr' => Str::limit($process->getErrorOutput(), 500, '...'),
                ],
            );
        }

        return new AiProviderHealthCheck(
            provider: $provider,
            status: 'online',
            message: trim($process->getOutput()) ?: "{$binary} available.",
            metadata: [
                'binary' => $binary,
                'stdout' => Str::limit($process->getOutput(), 500, '...'),
            ],
        );
    }

    protected function extractOutput(string $stdout): string
    {
        return trim($stdout);
    }

    protected function classifyCliError(string $stdout, string $stderr): string
    {
        $text = Str::lower($stdout."\n".$stderr);

        if (str_contains($text, 'rate limit') || str_contains($text, 'usage limit') || str_contains($text, 'limite')) {
            return 'rate_limited';
        }

        if (str_contains($text, 'login') || str_contains($text, 'auth') || str_contains($text, 'unauthorized') || str_contains($text, 'not authenticated')) {
            return 'auth_expired';
        }

        return 'cli_error';
    }

    protected function redactCommand(array $command): array
    {
        return array_map(fn (mixed $part): string => (string) $part, $command);
    }
}
