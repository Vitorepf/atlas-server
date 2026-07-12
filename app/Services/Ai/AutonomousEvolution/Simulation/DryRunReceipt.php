<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Simulation;

/** FACT-only result of applying and inspecting a proposed patch in a sandbox. */
final class DryRunReceipt
{
    /** @param list<array<string,mixed>> $changedFiles @param array<string,int> $phpLintExitCodePerFile */
    public function __construct(
        public readonly int $patchApplyExitCode,
        public readonly string $patchApplyOutputTail,
        public readonly array $changedFiles,
        public readonly array $phpLintExitCodePerFile,
        public readonly int $frozenTestExitCode,
        public readonly string $frozenTestStdoutTail,
        public readonly string $runStartedAt,
        public readonly string $runFinishedAt,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'patch_apply_exit_code' => $this->patchApplyExitCode,
            'patch_apply_output_tail' => $this->patchApplyOutputTail,
            'changed_files' => $this->changedFiles,
            'php_lint_exit_code_per_file' => $this->phpLintExitCodePerFile,
            'frozen_test_exit_code' => $this->frozenTestExitCode,
            'frozen_test_stdout_tail' => $this->frozenTestStdoutTail,
            'run_started_at' => $this->runStartedAt,
            'run_finished_at' => $this->runFinishedAt,
        ];
    }
}
