<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Support\AtlasSecurity;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Applies provider-produced unified diffs to the workspace after ScopeGuard
 * has approved the changed files and before VerificationGate runs.
 */
final class PatchApplier
{
    public function apply(DiffParseResult $diffResult, string $workspace, int $timeoutSeconds = 30): PatchApplyResult
    {
        if (! $diffResult->hasPatch()) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'no_patch',
            );
        }

        if (! is_dir($workspace)) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_FAILED,
                exitCode: 127,
                durationMs: 0,
                stdout: '',
                stderr: "PatchApplier: workspace '{$workspace}' does not exist.",
                reason: 'workspace_missing',
            );
        }

        $diff = (string) $diffResult->diff;
        if ($diff !== '' && ! str_ends_with($diff, "\n")) {
            $diff .= "\n";
        }

        $started = hrtime(true);
        $result = $this->runGitApply(
            args: ['git', 'apply', '--whitespace=nowarn', '--recount', '-'],
            workspace: $workspace,
            diff: $diff,
            timeoutSeconds: $timeoutSeconds,
        );

        if ($result['exit_code'] !== 0) {
            $fallback = $this->runGitApply(
                args: ['git', 'apply', '-p0', '--whitespace=nowarn', '--recount', '-'],
                workspace: $workspace,
                diff: $diff,
                timeoutSeconds: $timeoutSeconds,
            );

            if ($fallback['exit_code'] === 0) {
                $result = $fallback;
            } else {
                $result = [
                    'exit_code' => $result['exit_code'],
                    'stdout' => $result['stdout']."\n[p0 fallback stdout]\n".$fallback['stdout'],
                    'stderr' => $result['stderr']."\n[p0 fallback stderr]\n".$fallback['stderr'],
                ];
            }
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        return new PatchApplyResult(
            status: $result['exit_code'] === 0 ? PatchApplyResult::STATUS_APPLIED : PatchApplyResult::STATUS_FAILED,
            exitCode: $result['exit_code'],
            durationMs: $durationMs,
            stdout: AtlasSecurity::redactString($result['stdout']),
            stderr: AtlasSecurity::redactString($result['stderr']),
            reason: $result['exit_code'] === 0 ? null : 'git_apply_failed',
        );
    }

    /**
     * @param  list<string>  $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runGitApply(array $args, string $workspace, string $diff, int $timeoutSeconds): array
    {
        $process = new Process(
            $args,
            $workspace,
            null,
            null,
            (float) max(1, $timeoutSeconds),
        );
        $process->setInput($diff);

        try {
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'stdout' => (string) $process->getOutput(),
                'stderr' => (string) $process->getErrorOutput(),
            ];
        } catch (ProcessTimedOutException $e) {
            try {
                $process->stop(2);
            } catch (Throwable) {
            }

            return [
                'exit_code' => 124,
                'stdout' => (string) $process->getOutput(),
                'stderr' => $e->getMessage()."\n".(string) $process->getErrorOutput(),
            ];
        } catch (Throwable $e) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => $e->getMessage(),
            ];
        }
    }
}
