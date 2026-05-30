<?php

declare(strict_types=1);

namespace App\Services\Ai\Rsi;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Governed RSI · Part 3 · REAL git-revert port for the meta measured-or-reverted
 * authority.
 *
 * Mirrors the loop's proven AutonomousEvolutionSessionService::revertMergeForUnmetOutcome
 * sequence: check out main, try a mainline `git revert --no-edit -m 1 <hash>`
 * (true merge commit) and fall back to a plain `git revert --no-edit <hash>`
 * (fast-forwarded non-merge commit). NEVER `git reset --hard`. Empty merge hash
 * or a non-dir repo root => honest block (reverted=false). A real `git revert`
 * is NEVER run in a test or proof; tests inject a labelled Fake* port.
 */
final class RealRsiGitRevertPort implements RsiGitRevertPort
{
    public function __construct(
        private readonly float $timeout = 180.0,
    ) {}

    /**
     * @return array{reverted:bool,revert_commit_hash:string,detail:string}
     */
    public function revert(string $repoRoot, string $mergeHash): array
    {
        $hash = trim($mergeHash);
        if ($hash === '') {
            return $this->blocked('no_merge_hash');
        }
        if (! is_dir($repoRoot)) {
            return $this->blocked('repo_root_missing');
        }
        if (! class_exists(Process::class)) {
            return $this->blocked('revert_process_component_missing');
        }

        if (! $this->git($repoRoot, ['checkout', 'main'])['ok']) {
            return $this->blocked('checkout_main_failed');
        }

        // Mainline revert first (true merge commit); fall back to plain revert.
        $revert = $this->git($repoRoot, ['revert', '--no-edit', '-m', '1', $hash]);
        if (! $revert['ok']) {
            $this->git($repoRoot, ['revert', '--abort']);
            $revert = $this->git($repoRoot, ['revert', '--no-edit', $hash]);
        }
        if (! $revert['ok']) {
            $this->git($repoRoot, ['revert', '--abort']);

            return $this->blocked('git_revert_failed');
        }

        $head = $this->git($repoRoot, ['rev-parse', 'HEAD']);

        return [
            'reverted' => true,
            'revert_commit_hash' => trim((string) $head['out']),
            'detail' => 'git_revert_no_edit',
        ];
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $repoRoot, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repoRoot);
        $process->setTimeout($this->timeout);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['ok' => false, 'out' => '', 'err' => $e->getMessage()];
        }

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return array{reverted:false,revert_commit_hash:string,detail:string}
     */
    private function blocked(string $detail): array
    {
        return ['reverted' => false, 'revert_commit_hash' => '', 'detail' => $detail];
    }
}
