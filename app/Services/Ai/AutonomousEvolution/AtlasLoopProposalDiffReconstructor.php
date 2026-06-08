<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;

/**
 * Reconstructs the loop candidate content from the proposal diff format.
 *
 * Unified-loop proposals are produced inside tiny task workspaces where the target
 * file is named target.php or target.md. The persisted diff therefore patches that
 * synthetic filename, not the real repo path. This service keeps that format in one
 * place so the orchestrator and the independent verifier do not drift.
 */
final class AtlasLoopProposalDiffReconstructor
{
    /**
     * @return array{ok:bool, content:?string, reason:?string}
     */
    public function reconstruct(string $original, string $diff, string $filename): array
    {
        if (trim($diff) === '') {
            return ['ok' => false, 'content' => null, 'reason' => 'empty_diff'];
        }

        $dir = sys_get_temp_dir().'/atlas-apply-'.bin2hex(random_bytes(5));
        if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return ['ok' => false, 'content' => null, 'reason' => 'scratch_mkdir_failed'];
        }

        try {
            file_put_contents($dir.'/'.$filename, $original);
            if (! $this->git($dir, ['init', '-q'])) {
                return ['ok' => false, 'content' => null, 'reason' => 'scratch_git_init_failed'];
            }
            if (! $this->git($dir, ['add', '-A'])) {
                return ['ok' => false, 'content' => null, 'reason' => 'scratch_git_add_failed'];
            }
            if (! $this->git($dir, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas', 'commit', '-q', '-m', 'base', '--no-gpg-sign'])) {
                return ['ok' => false, 'content' => null, 'reason' => 'scratch_git_commit_failed'];
            }

            file_put_contents($dir.'/atlas.patch', $diff);
            if (! $this->git($dir, ['apply', '--whitespace=nowarn', 'atlas.patch'])) {
                return ['ok' => false, 'content' => null, 'reason' => 'does_not_apply_clean'];
            }

            $out = @file_get_contents($dir.'/'.$filename);
            if ($out === false) {
                return ['ok' => false, 'content' => null, 'reason' => 'reconstructed_file_missing'];
            }

            return ['ok' => true, 'content' => $out, 'reason' => null];
        } finally {
            (new Process(['rm', '-rf', $dir]))->run();
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): bool
    {
        $process = new Process(array_merge(['git'], $argv), $cwd, null, null, 60.0);
        $process->run();

        return $process->isSuccessful();
    }
}
