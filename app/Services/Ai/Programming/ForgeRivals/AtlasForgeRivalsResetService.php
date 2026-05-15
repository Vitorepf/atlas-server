<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use Symfony\Component\Process\Process;

/**
 * Atlas Forge Rivals · Reset.
 *
 * Removes a SPECIFIC run directory under
 *   /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/
 * via `git worktree remove --force` (releases git's bookkeeping) followed by
 * a recursive rmdir of the run base. Never touches the source repo.
 *
 * The reset is path-confined: the resolver rejects any run_id that doesn't
 * canonicalize under the runs root, so this service is incapable of
 * deleting an arbitrary path.
 */
final class AtlasForgeRivalsResetService
{
    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array{run_id?:string,reviewer?:string,reason?:string,repo_root?:string,workspace?:string}  $input
     * @return array<string,mixed>
     */
    public function reset(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals reset --run-id=<id> --reason=<text> --json',
            ];
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['reason_required'],
                'next_command' => 'php artisan atlas:forge:rivals reset --run-id='.$runId.' --reason=<text> --json',
            ];
        }

        $paths = $this->paths->paths($runId);
        $runsRoot = $this->paths->rootDirectory();
        if (! str_starts_with($paths['base'], $runsRoot.'/')) {
            return [
                'status' => 'blocked',
                'blockers' => ['path_escape_blocked'],
                'next_command' => '',
            ];
        }
        if (! is_dir($paths['base'])) {
            return [
                'status' => 'ok',
                'run_id' => $paths['run_id'],
                'reason' => $reason,
                'removed_paths' => [],
                'note' => 'No run dir present — nothing to remove.',
            ];
        }

        $repoRoot = (string) ($input['repo_root'] ?? $input['workspace'] ?? (function_exists('base_path') ? base_path() : getcwd()));
        $repoRoot = rtrim($repoRoot, '/');

        $removed = [];
        // Detach git worktrees (if they were registered)
        foreach (['atlas', 'rival'] as $arm) {
            if (is_dir($paths[$arm].'/.git') || is_file($paths[$arm].'/.git')) {
                $proc = new Process(['git', '-C', $repoRoot, 'worktree', 'remove', '--force', $paths[$arm]]);
                $proc->setTimeout(60);
                $proc->run();
                $removed[] = $arm.':'.($proc->isSuccessful() ? 'detached' : ('detach_failed:'.trim((string) $proc->getErrorOutput())));
            }
        }

        // rm -rf path-confined
        $this->recursiveRemove($paths['base']);
        $removed[] = $paths['base'].':removed';

        // Prune git worktree bookkeeping
        $prune = new Process(['git', '-C', $repoRoot, 'worktree', 'prune']);
        $prune->setTimeout(30);
        $prune->run();

        return [
            'status' => 'ok',
            'run_id' => $paths['run_id'],
            'reviewer' => trim((string) ($input['reviewer'] ?? '')),
            'reason' => $reason,
            'removed_paths' => $removed,
            'next_command' => 'php artisan atlas:forge:rivals doctor --json',
        ];
    }

    private function recursiveRemove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveRemove($path);
            }
        }
        @rmdir($dir);
    }
}
