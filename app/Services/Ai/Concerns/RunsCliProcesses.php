<?php

namespace App\Services\Ai\Concerns;

use App\Models\AiJob;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

trait RunsCliProcesses
{
    protected function runProcess(array $command, string $input, int $timeoutSeconds, ?string $cwd = null): AiProviderResult
    {
        return $this->runProcessStreaming($command, $input, $timeoutSeconds, $cwd);
    }

    protected function runProcessStreaming(array $command, string $input, int $timeoutSeconds, ?string $cwd = null, ?callable $onEvent = null): AiProviderResult
    {
        $started = hrtime(true);
        $command = $this->resolveCommandBinary($command);
        $process = new Process($command, $cwd ?: (string) config('atlas.ai.workdir'), AtlasSecurity::processEnv(profile: 'provider'));
        $process->setInput($input);
        $process->setTimeout($timeoutSeconds);
        $stdout = '';
        $stderr = '';

        try {
            $this->emitStreamEvent($onEvent, 'lifecycle', 'process_started', '', [
                'command' => $this->redactCommand($command),
                'cwd' => $process->getWorkingDirectory(),
                'timeout_seconds' => $timeoutSeconds,
            ]);

            $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr, $onEvent): void {
                $buffer = AtlasSecurity::redactString($buffer);
                if ($type === Process::ERR) {
                    $stderr .= $buffer;
                    $this->emitStreamEvent($onEvent, 'stderr', 'stderr', $buffer, [
                        'bytes' => strlen($buffer),
                    ], 'stderr');

                    return;
                }

                $stdout .= $buffer;
                $this->emitStreamEvent($onEvent, 'stdout', 'stdout', $buffer, [
                    'bytes' => strlen($buffer),
                ], 'stdout');

                foreach ($this->streamOutputEvents($buffer) as $event) {
                    $this->emitStreamEvent(
                        $onEvent,
                        is_string($event['type'] ?? null) ? $event['type'] : 'token',
                        is_string($event['name'] ?? null) ? $event['name'] : 'token',
                        is_string($event['content'] ?? null) ? $event['content'] : '',
                        is_array($event['metadata'] ?? null) ? $event['metadata'] : [],
                        is_string($event['channel'] ?? null) ? $event['channel'] : 'assistant',
                    );
                }
            });
        } catch (ProcessTimedOutException $exception) {
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $stdout = $stdout ?: AtlasSecurity::redactString($process->getOutput());
            $stderr = $stderr ?: AtlasSecurity::redactString($process->getErrorOutput());

            $this->emitStreamEvent($onEvent, 'error', 'timeout', $exception->getMessage(), [
                'command' => $this->redactCommand($command),
                'duration_ms' => $durationMs,
            ]);

            return new AiProviderResult(
                ok: false,
                output: '',
                command: $this->redactCommand($command),
                exitCode: null,
                durationMs: $durationMs,
                stdout: $stdout,
                stderr: $stderr,
                errorCode: 'timeout',
                errorMessage: AtlasSecurity::redactString($exception->getMessage()),
            );
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $stdout = $stdout ?: AtlasSecurity::redactString($process->getOutput());
        $stderr = $stderr ?: AtlasSecurity::redactString($process->getErrorOutput());
        $output = $this->extractOutput($stdout);
        $errorCode = $process->isSuccessful() ? null : $this->classifyCliError($stdout, $stderr);

        if ($process->isSuccessful() && trim($output) === '') {
            $errorCode = 'empty_output';
        }

        $this->emitStreamEvent($onEvent, $errorCode ? 'error' : 'response', $errorCode ?: 'response', $errorCode ? (trim($stderr) ?: trim($stdout) ?: $errorCode) : $output, [
            'command' => $this->redactCommand($command),
            'exit_code' => $process->getExitCode(),
            'duration_ms' => $durationMs,
        ]);

        $this->emitStreamEvent($onEvent, 'lifecycle', 'process_finished', '', [
            'exit_code' => $process->getExitCode(),
            'duration_ms' => $durationMs,
            'successful' => $process->isSuccessful(),
            'error_code' => $errorCode,
        ]);

        return new AiProviderResult(
            ok: $process->isSuccessful() && $errorCode === null,
            output: $output,
            command: $this->redactCommand($command),
            exitCode: $process->getExitCode(),
            durationMs: $durationMs,
            stdout: $stdout,
            stderr: $stderr,
            errorCode: $errorCode,
            errorMessage: $errorCode ? AtlasSecurity::redactString(trim($stderr) ?: trim($stdout) ?: $errorCode) : null,
        );
    }

    protected function checkBinary(string $provider, string $binary): AiProviderHealthCheck
    {
        $resolved = $this->resolveCliBinary($binary);
        if ($resolved === null) {
            return new AiProviderHealthCheck(
                provider: $provider,
                status: 'offline',
                message: "Binary [{$binary}] not found. Rode atlas bootstrap para diagnosticar e configurar o caminho.",
                metadata: [
                    'configured_binary' => $binary,
                    'searched_dirs' => $this->extraCliBinarySearchDirs(),
                ],
            );
        }

        $process = new Process([$resolved, '--version'], (string) config('atlas.ai.workdir'), AtlasSecurity::processEnv(profile: 'provider'));
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = AtlasSecurity::redactString($process->getErrorOutput());
            $stdout = AtlasSecurity::redactString($process->getOutput());

            return new AiProviderHealthCheck(
                provider: $provider,
                status: 'offline',
                message: trim($stderr) ?: 'Command ['.AtlasSecurity::commandLineForDisplay([$resolved, '--version']).'] failed.',
                metadata: [
                    'configured_binary' => $binary,
                    'binary' => $resolved,
                    'exit_code' => $process->getExitCode(),
                    'stdout' => Str::limit($stdout, 500, '...'),
                    'stderr' => Str::limit($stderr, 500, '...'),
                ],
            );
        }

        $stdout = AtlasSecurity::redactString($process->getOutput());

        return new AiProviderHealthCheck(
            provider: $provider,
            status: 'online',
            message: trim($stdout) ?: "{$resolved} available.",
            metadata: [
                'configured_binary' => $binary,
                'binary' => $resolved,
                'stdout' => Str::limit($stdout, 500, '...'),
            ],
        );
    }

    protected function extractOutput(string $stdout): string
    {
        return trim($stdout);
    }

    /**
     * @return array<int,array{type?:string,name?:string,content?:string,metadata?:array<string,mixed>,channel?:string}>
     */
    protected function streamOutputEvents(string $chunk): array
    {
        return trim($chunk) === ''
            ? []
            : [[
                'type' => 'token',
                'name' => 'stdout_chunk',
                'content' => $chunk,
                'metadata' => ['parser' => 'stdout_chunk'],
                'channel' => 'assistant',
            ]];
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
        return AtlasSecurity::redactCommand($command);
    }

    /**
     * @param  array<int, mixed>  $command
     * @return array<int, mixed>
     */
    protected function resolveCommandBinary(array $command): array
    {
        if ($command === []) {
            return $command;
        }

        $binary = (string) $command[0];
        $resolved = $this->resolveCliBinary($binary);
        if ($resolved) {
            $command[0] = $resolved;
        }

        return $command;
    }

    protected function resolveCliBinary(string $binary): ?string
    {
        $expanded = $this->expandHomePath($binary);
        if (str_contains($expanded, '/') || str_contains($expanded, '\\')) {
            return is_executable($expanded) ? realpath($expanded) ?: $expanded : null;
        }

        $finder = new ExecutableFinder;
        $found = $finder->find($binary, null, $this->extraCliBinarySearchDirs());

        return is_string($found) && $found !== '' ? $found : null;
    }

    /**
     * @return array<int, string>
     */
    protected function extraCliBinarySearchDirs(): array
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        return array_values(array_filter(array_unique([
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            $home !== '' ? $home.'/.local/bin' : null,
            $home !== '' ? $home.'/.npm-global/bin' : null,
            $home !== '' ? $home.'/Library/pnpm' : null,
        ])));
    }

    protected function expandHomePath(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        if ($home === '') {
            return $path;
        }

        return $home.substr($path, 1);
    }

    protected function workdirForJob(AiJob $job): string
    {
        $workspace = data_get($job->payload, 'tool_permissions.workspace')
            ?: data_get($job->payload, 'workspace')
            ?: config('atlas.ai.workdir');

        if (is_string($workspace) && $workspace !== '') {
            $resolved = realpath($workspace);
            if ($resolved && is_dir($resolved)) {
                return $resolved;
            }
        }

        return (string) config('atlas.ai.workdir');
    }

    private function emitStreamEvent(?callable $onEvent, string $type, string $name, string $content = '', array $metadata = [], ?string $channel = null): void
    {
        if (! $onEvent) {
            return;
        }

        $onEvent([
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'channel' => $channel,
            'metadata' => $metadata,
            'occurred_at' => now()->toJSON(),
        ]);
    }
}
