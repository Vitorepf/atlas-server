<?php

namespace App\Services\Ai\Runtime;

use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * PROCESS/SHELL EXECUTION concern, extracted from the god-class
 * {@see AiToolRuntime}.
 *
 * Owns every process-spawning / result-mapping method: runProcess,
 * runShell, runTestShell, runProcessWithInput, processResult (the
 * process-array → ToolResult mapper), writeTestFailureArtifact and the
 * test environment env dict. AiToolRuntime keeps thin delegators so its
 * public execute(ToolInvocation) signature and ToolResult shapes are
 * byte-identical before and after the extraction.
 */
class AiToolProcessRunner
{
    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:array<int,string>}
     */
    public function runProcess(array $command, string $cwd, int $timeout = 60): array
    {
        $started = hrtime(true);
        $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => AtlasSecurity::redactString($process->getOutput()),
            'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => $command,
        ];
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:string}
     */
    public function runShell(string $command, string $cwd, int $timeout = 600): array
    {
        $started = hrtime(true);
        $process = Process::fromShellCommandline($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => AtlasSecurity::redactString($process->getOutput()),
            'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => AtlasSecurity::redactString($command),
        ];
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:string,artifact_path?:string|null}
     */
    public function runTestShell(string $command, string $cwd, int $timeout = 900): array
    {
        $started = hrtime(true);
        $process = Process::fromShellCommandline($command, $cwd, AtlasSecurity::processEnv($this->testEnvironment(), 'tool'));
        $process->setTimeout($timeout);
        $process->run();
        $stdout = AtlasSecurity::redactString($process->getOutput());
        $stderr = AtlasSecurity::redactString($process->getErrorOutput());
        $exitCode = $process->getExitCode() ?? 1;

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => AtlasSecurity::redactString($command),
            'artifact_path' => $exitCode === 0 ? null : $this->writeTestFailureArtifact($command, $cwd, $stdout, $stderr, $exitCode),
        ];
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command:array<int,string>}
     */
    public function runProcessWithInput(array $command, string $input, string $cwd, int $timeout = 60): array
    {
        $started = hrtime(true);
        $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setInput($input);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => AtlasSecurity::redactString($process->getOutput()),
            'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'command' => $command,
        ];
    }

    /**
     * @param  array{exit_code:int,stdout:string,stderr:string,duration_ms:int,command?:array<int,string>|string,artifact_path?:string|null}  $process
     */
    public function processResult(ToolInvocation $invocation, array $process, string $summary): ToolResult
    {
        $ok = $process['exit_code'] === 0;
        $stdout = AtlasSecurity::redactString($process['stdout']);
        $stderr = AtlasSecurity::redactString($process['stderr']);
        $output = trim($stdout) !== '' ? $stdout : $stderr;
        $error = trim($stderr) ?: trim($stdout) ?: 'Process failed.';
        $command = $process['command'] ?? null;

        return new ToolResult(
            ok: $ok,
            invocationId: $invocation->id,
            tool: $invocation->tool,
            summary: $ok ? $summary : 'Ferramenta terminou com erro.',
            output: $output,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $process['exit_code'],
            durationMs: $process['duration_ms'],
            errorCode: $ok ? null : 'tool_process_failed',
            errorMessage: $ok ? null : $error,
            metadata: [
                'command' => AtlasSecurity::redactCommandValue($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
                'artifact_path' => $process['artifact_path'] ?? null,
            ],
        );
    }

    public function writeTestFailureArtifact(string $command, string $cwd, string $stdout, string $stderr, int $exitCode): string
    {
        $dir = storage_path('app/ai/test-runs');
        File::ensureDirectoryExists($dir);

        $path = $dir.'/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.log';
        File::put($path, implode("\n", [
            'Atlas test.run failure artifact',
            'generated_at='.now()->toJSON(),
            'cwd='.AtlasSecurity::redactString($cwd),
            'exit_code='.$exitCode,
            'command='.AtlasSecurity::redactString($command),
            '',
            '--- stdout ---',
            $stdout,
            '',
            '--- stderr ---',
            $stderr,
        ]));

        return $path;
    }

    /**
     * @return array<string,string>
     */
    public function testEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'ATLAS_TOKEN' => 'testing-atlas-token-with-enough-length',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
    }
}
