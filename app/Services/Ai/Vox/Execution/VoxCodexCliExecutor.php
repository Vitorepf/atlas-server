<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;

/**
 * V3 codex_cli executor. Shells out to a locally-installed Codex CLI that
 * is expected to be already authenticated (the Kernel never receives or
 * forwards API keys — Lei 0.75 / "sem provider direto").
 *
 * Configuration via Laravel config + env:
 *   - config('atlas.vox.executors.codex_cli.binary') (env ATLAS_VOX_CODEX_CLI_BIN)
 *   - config('atlas.vox.executors.codex_cli.cwd')    (env ATLAS_VOX_CODEX_CLI_CWD)
 *   - config('atlas.vox.executors.codex_cli.timeout_seconds') (default 60)
 *
 * When the binary is not configured OR not executable, the executor
 * returns an honest `blocked` outcome with reason_code
 * `codex_cli_unavailable` — never invents a stdout. The Desktop surfaces
 * the unavailable state in the overlay.
 *
 * Defense in depth: the compiled prompt is fed via stdin, NOT via argv,
 * so a hostile transcript cannot inject shell substitutions even if the
 * binary mis-handles its own quoting. The hard-veto list is re-checked
 * here against the compiled prompt as a last line of defense.
 */
final class VoxCodexCliExecutor implements VoxExecutor
{
    public function __construct(
        private readonly VoxActionOutcomeService $outcomes,
        private readonly VoxProcessRunner $runner,
    ) {}

    public function id(): string
    {
        return VoxSchema::EXECUTOR_CODEX_CLI;
    }

    public function isAvailable(): bool
    {
        return $this->resolvedBinary() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'codex_cli_unavailable';
    }

    public function dispatch(array $intentPacket, array $receipt, array $context): array
    {
        $binary = $this->resolvedBinary();
        if ($binary === null) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'codex_cli_unavailable',
                message: 'Codex CLI binary não configurado (ATLAS_VOX_CODEX_CLI_BIN ausente ou inválido).',
            );
        }

        $prompt = (string) ($intentPacket['compiled_prompt'] ?? '');
        if ($prompt === '') {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'codex_cli_empty_prompt',
                message: 'compiled_prompt vazio; Kernel recusa invocar Codex CLI sem conteúdo.',
            );
        }

        $violation = VoxHardVetoList::firstViolation($prompt);
        if ($violation !== null) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'hard_veto_inside_executor_'.$violation,
                message: "codex_cli recusou prompt com hard veto '{$violation}'.",
            );
        }

        $cwd = $this->resolvedCwd();
        $timeout = $this->resolvedTimeout();

        $result = $this->runner->run(
            command: [$binary, '--quiet'],
            cwd: $cwd,
            stdin: $prompt,
            timeoutSeconds: $timeout,
        );

        if (! $result['ok']) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: $result['timed_out'] ? 'codex_cli_timeout' : 'codex_cli_nonzero_exit',
                message: 'Codex CLI exit '.$result['exit_code'].' — '.$this->trimStderr($result['stderr']),
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
            executorVersion: 'codex-cli@local',
            stdout: $result['stdout'],
            stderr: $result['stderr'],
            exitCode: $result['exit_code'],
            durationMs: $result['duration_ms'],
            workingDirectory: $cwd,
        );
    }

    private function resolvedBinary(): ?string
    {
        $binary = config('atlas.vox.executors.codex_cli.binary');
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
        $cwd = config('atlas.vox.executors.codex_cli.cwd');
        if (is_string($cwd) && $cwd !== '' && is_dir($cwd)) {
            return $cwd;
        }

        // Conservative fallback: base_path() of the Laravel app. Codex CLI
        // typically wants a code workspace — operator can override via env.
        return base_path();
    }

    private function resolvedTimeout(): int
    {
        $raw = config('atlas.vox.executors.codex_cli.timeout_seconds');

        return is_int($raw) && $raw > 0 ? $raw : 60;
    }

    private function trimStderr(string $stderr): string
    {
        $trimmed = trim($stderr);

        return mb_substr($trimmed, 0, 240);
    }
}
