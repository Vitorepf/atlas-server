<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper around Symfony Process so Codex/Claude CLI executors can be
 * fake-d in tests without touching real binaries.
 *
 * Production: instantiates a real Process with a hard timeout, an explicit
 * cwd and no env leakage. The runner NEVER receives secrets via args; the
 * provider CLI is expected to use its own ambient auth.
 *
 * Tests: swap a fake implementation (see VoxFakeProcessRunner).
 */
class VoxProcessRunner
{
    /**
     * @param  list<string>  $command  Full argv array (binary + args).
     * @param  string|null  $stdin    Optional stdin payload (e.g. compiled prompt).
     * @param  int  $timeoutSeconds   Hard ceiling. Process is killed on overflow.
     * @return array{
     *     ok: bool,
     *     exit_code: int,
     *     stdout: string,
     *     stderr: string,
     *     duration_ms: int,
     *     timed_out: bool,
     * }
     */
    public function run(
        array $command,
        string $cwd,
        ?string $stdin = null,
        int $timeoutSeconds = 60,
    ): array {
        $process = new Process($command, $cwd, env: null, input: $stdin, timeout: $timeoutSeconds);
        $start = (int) (microtime(true) * 1000);
        try {
            $process->run();
            $duration = max(0, (int) (microtime(true) * 1000) - $start);

            return [
                'ok' => $process->isSuccessful(),
                'exit_code' => (int) $process->getExitCode(),
                'stdout' => (string) $process->getOutput(),
                'stderr' => (string) $process->getErrorOutput(),
                'duration_ms' => $duration,
                'timed_out' => false,
            ];
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $duration = max(0, (int) (microtime(true) * 1000) - $start);

            return [
                'ok' => false,
                'exit_code' => -1,
                'stdout' => (string) $process->getOutput(),
                'stderr' => 'process timed out after '.$timeoutSeconds.'s: '.$e->getMessage(),
                'duration_ms' => $duration,
                'timed_out' => true,
            ];
        } catch (\Throwable $e) {
            $duration = max(0, (int) (microtime(true) * 1000) - $start);

            return [
                'ok' => false,
                'exit_code' => -1,
                'stdout' => '',
                'stderr' => 'process error: '.$e->getMessage(),
                'duration_ms' => $duration,
                'timed_out' => false,
            ];
        }
    }
}
