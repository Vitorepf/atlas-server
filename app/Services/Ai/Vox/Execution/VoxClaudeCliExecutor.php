<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;

/**
 * V3 claude_cli executor · sibling of VoxCodexCliExecutor.
 *
 * Same contract: locally-installed, already-authenticated CLI. The Kernel
 * forwards the compiled_prompt via stdin and captures stdout/stderr. No
 * API keys flow through the Kernel.
 *
 * Configuration via Laravel config + env:
 *   - config('atlas.vox.executors.claude_cli.binary')
 *   - config('atlas.vox.executors.claude_cli.cwd')
 *   - config('atlas.vox.executors.claude_cli.timeout_seconds')
 */
final class VoxClaudeCliExecutor implements VoxExecutor
{
    public function __construct(
        private readonly VoxActionOutcomeService $outcomes,
        private readonly VoxProcessRunner $runner,
    ) {}

    public function id(): string
    {
        return VoxSchema::EXECUTOR_CLAUDE_CLI;
    }

    public function isAvailable(): bool
    {
        return $this->resolvedBinary() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'claude_cli_unavailable';
    }

    public function dispatch(array $intentPacket, array $receipt, array $context): array
    {
        $binary = $this->resolvedBinary();
        if ($binary === null) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'claude_cli_unavailable',
                message: 'Claude CLI binary não configurado (ATLAS_VOX_CLAUDE_CLI_BIN ausente ou inválido).',
            );
        }

        $prompt = (string) ($intentPacket['compiled_prompt'] ?? '');
        if ($prompt === '') {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'claude_cli_empty_prompt',
                message: 'compiled_prompt vazio; Kernel recusa invocar Claude CLI sem conteúdo.',
            );
        }

        $violation = VoxHardVetoList::firstViolation($prompt);
        if ($violation !== null) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'hard_veto_inside_executor_'.$violation,
                message: "claude_cli recusou prompt com hard veto '{$violation}'.",
            );
        }

        $cwd = $this->resolvedCwd();
        $timeout = $this->resolvedTimeout();

        $result = $this->runner->run(
            command: [$binary, '--print'],
            cwd: $cwd,
            stdin: $prompt,
            timeoutSeconds: $timeout,
        );

        if (! $result['ok']) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: $result['timed_out'] ? 'claude_cli_timeout' : 'claude_cli_nonzero_exit',
                message: 'Claude CLI exit '.$result['exit_code'].' — '.$this->trimStderr($result['stderr']),
                metadataExtras: [
                    'exit_code' => $result['exit_code'],
                    'duration_ms' => $result['duration_ms'],
                    'working_directory' => $cwd,
                ],
            );
        }

        return $this->outcomes->providerCliCompleted(
            intentPacket: $intentPacket,
            receipt: $receipt,
            executor: $this->id(),
            executorVersion: 'claude-cli@local',
            stdout: $result['stdout'],
            stderr: $result['stderr'],
            exitCode: $result['exit_code'],
            durationMs: $result['duration_ms'],
            workingDirectory: $cwd,
        );
    }

    private function resolvedBinary(): ?string
    {
        $binary = config('atlas.vox.executors.claude_cli.binary');
        if (! is_string($binary) || $binary === '') {
            return null;
        }
        if (! is_executable($binary) && ! is_file($binary)) {
            return null;
        }

        return $binary;
    }

    private function resolvedCwd(): string
    {
        $cwd = config('atlas.vox.executors.claude_cli.cwd');
        if (is_string($cwd) && $cwd !== '' && is_dir($cwd)) {
            return $cwd;
        }

        return base_path();
    }

    private function resolvedTimeout(): int
    {
        $raw = config('atlas.vox.executors.claude_cli.timeout_seconds');

        return is_int($raw) && $raw > 0 ? $raw : 60;
    }

    private function trimStderr(string $stderr): string
    {
        return mb_substr(trim($stderr), 0, 240);
    }
}
