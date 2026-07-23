<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

/**
 * Foundry AP-E · REAL git-revert port for the materializer's Outcome\GitRevertPort
 * contract (revert(mergeHash, verifyCmd)).
 *
 * AP-E INVIOLABLE RULE (I5): on non-improvement the materializer reverts via
 * `git revert --no-edit` (NEVER `git reset --hard`). With no real merged finding
 * the worktree does NOT resolve and this BLOCKS honestly (reverted=false,
 * revert_commit_hash='') — it NEVER runs a real `git revert`. The merge-finding
 * worktree is sourced from FOUNDRY_AFEF_WORKTREE; absent/non-dir => honest block.
 *
 * `git reset --hard` is structurally impossible here: the revert command is a
 * fixed `git revert --no-edit <hash>`. Empty merge hash => honest block.
 */
final class RealGitRevertPort implements GitRevertPort
{
    public function __construct(
        private readonly float $timeout = 30.0,
    ) {}

    /**
     * @return array{reverted:bool,revert_commit_hash:string,verify_passed:bool,detail:string}
     */
    public function revert(string $mergeHash, string $verifyCmd): array
    {
        $hash = trim($mergeHash);
        if ($hash === '') {
            return $this->blocked('no_real_merged_finding');
        }

        // No real merged AFEF-origin finding => no resolvable worktree => honest block.
        $worktree = (string) (getenv('FOUNDRY_AFEF_WORKTREE') ?: '');
        if ($worktree === '' || ! is_dir($worktree)) {
            return $this->blocked('no_real_merged_finding');
        }

        if (! class_exists(\Symfony\Component\Process\Process::class)) {
            return $this->blocked('revert_process_component_missing');
        }

        // NEVER reset --hard. Only git revert --no-edit.
        $process = new \Symfony\Component\Process\Process(['git', 'revert', '--no-edit', $hash], $worktree);
        $process->setTimeout($this->timeout);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return $this->blocked('revert_process_failed:'.$e->getMessage());
        }

        if (! $process->isSuccessful()) {
            return [
                'reverted' => false,
                'revert_commit_hash' => '',
                'verify_passed' => false,
                'detail' => 'git_revert_failed',
            ];
        }

        $revertHash = $this->headHash($worktree);
        $verifyPassed = $this->runVerify($worktree, trim($verifyCmd));

        return [
            'reverted' => true,
            'revert_commit_hash' => $revertHash,
            'verify_passed' => $verifyPassed,
            'detail' => 'git_revert_no_edit',
        ];
    }

    private function headHash(string $worktree): string
    {
        $p = new \Symfony\Component\Process\Process(['git', 'rev-parse', 'HEAD'], $worktree);
        $p->setTimeout(10.0);
        try {
            $p->run();
        } catch (\Throwable) {
            return '';
        }

        return $p->isSuccessful() ? trim($p->getOutput()) : '';
    }

    private function runVerify(string $worktree, string $verifyCmd): bool
    {
        if ($verifyCmd === '') {
            return false;
        }
        $p = \Symfony\Component\Process\Process::fromShellCommandline($verifyCmd, $worktree);
        $p->setTimeout($this->timeout);
        try {
            $p->run();
        } catch (\Throwable) {
            return false;
        }

        return $p->isSuccessful();
    }

    /**
     * @return array{reverted:false,revert_commit_hash:string,verify_passed:false,detail:string}
     */
    private function blocked(string $detail): array
    {
        return ['reverted' => false, 'revert_commit_hash' => '', 'verify_passed' => false, 'detail' => $detail];
    }
}
