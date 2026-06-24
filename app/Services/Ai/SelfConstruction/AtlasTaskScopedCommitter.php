<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PART 2 · the SHARED-MAIN scoped committer — the safe "resolve" for N AIs working the SAME local main branch.
 *
 * THE MODEL (operator): every AI edits the SAME working tree on `main`. With 5 AIs each touching 2 files, the
 * tree holds 10 changed files at once — but each AI must commit ONLY its OWN files. This is safe BY
 * CONSTRUCTION because the serving stack hands out tasks whose `allowed_files` are pairwise DISJOINT
 * (conflict-free claim, prefix-aware) — so no two AIs ever touch the same file, and each can commit its scope
 * without clobbering or grabbing another AI's uncommitted work.
 *
 * This committer makes that foolproof: given a task's `allowed_files`, it stages and commits EXACTLY those
 * paths (`git commit -- <paths>`, the partial-commit form, so other AIs' staged/unstaged changes are NEVER
 * swept in — the `git add -A` foot-gun is impossible), under a single exclusive flock so concurrent resolves
 * serialize, refusing any path on the pétreo forbidden list. The AI never runs raw git.
 */
final class AtlasTaskScopedCommitter
{
    public const LOCK_REL = '.git/atlas-task-commit.lock';

    public const LOCK_TIMEOUT_SECONDS = 15.0;

    private const LOCK_POLL_MICROSECONDS = 50_000;

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?string $repoRootOverride = null,
    ) {}

    /**
     * Stage + commit EXACTLY $allowedFiles as one scoped commit. Never touches paths outside the scope.
     *
     * @param  list<string>  $allowedFiles
     * @return array<string, mixed>
     */
    public function commitScope(array $allowedFiles, string $taskPacketId, string $clientId, string $objective = ''): array
    {
        $files = $this->normalizeFiles($allowedFiles);
        if ($files === []) {
            return $this->result(false, 'empty_scope', taskPacketId: $taskPacketId);
        }

        // PÉTREO: never commit a forbidden self-target (the loop's own judge/guard/master switch).
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        foreach ($files as $file) {
            if ($guard->isForbiddenSelfTarget($file)) {
                return $this->result(false, 'forbidden_self_target', taskPacketId: $taskPacketId, extra: ['path' => $file]);
            }
        }

        $repo = $this->repoRoot();
        if (! is_dir($repo.'/.git')) {
            return $this->result(false, 'not_a_git_repo', taskPacketId: $taskPacketId);
        }

        return $this->withCommitLock($repo, function () use ($repo, $files, $taskPacketId, $clientId, $objective): array {
            // STATUS-FIRST: `git status` on the scope never errors on a path that does not exist; `git add` of a
            // non-existent pathspec DOES error. So discover which scoped paths actually changed, and act only on
            // those. Empty ⇒ the AI made no edits ⇒ honest no-op (keep the lease).
            // `-uall` lists each untracked FILE (not a collapsed parent dir), so a brand-new file in a brand-new
            // directory is committed as the exact file path, never the whole dir.
            $status = $this->git($repo, array_merge(['status', '--porcelain', '--untracked-files=all', '--'], $files));
            $changed = $this->changedPaths((string) $status['out']);
            if ($changed === []) {
                return $this->result(false, 'nothing_to_commit_in_scope', taskPacketId: $taskPacketId);
            }

            // Stage ONLY the changed scoped paths.
            $add = $this->git($repo, array_merge(['add', '--'], $changed));
            if ($add['code'] !== 0) {
                return $this->result(false, 'git_add_failed', taskPacketId: $taskPacketId, extra: ['stderr' => $add['err']]);
            }

            $message = $this->commitMessage($taskPacketId, $clientId, $objective);
            // Partial commit: `-- <paths>` commits ONLY these paths regardless of what else is staged.
            $commit = $this->git($repo, array_merge(['commit', '-m', $message, '--'], $changed));
            if ($commit['code'] !== 0) {
                return $this->result(false, 'git_commit_failed', taskPacketId: $taskPacketId, extra: ['stderr' => $commit['err']]);
            }

            $sha = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);

            return $this->result(true, 'committed', taskPacketId: $taskPacketId, extra: [
                'commit_sha' => $sha,
                'files_committed' => $files,
                'client_id' => $clientId,
            ]);
        });
    }

    private function commitMessage(string $taskPacketId, string $clientId, string $objective): string
    {
        $summary = $objective !== '' ? $objective : 'resolve task';
        $summary = trim(str_replace(["\n", "\r"], ' ', $summary));
        if (mb_strlen($summary) > 72) {
            $summary = mb_substr($summary, 0, 69).'...';
        }

        return "atlas-task {$taskPacketId}: {$summary}\n\nAtlas-Task: {$taskPacketId}\nResolved-by: {$clientId}";
    }

    /**
     * @param  callable():array<string,mixed>  $callback
     * @return array<string, mixed>
     */
    private function withCommitLock(string $repo, callable $callback): array
    {
        $lockPath = $repo.'/'.self::LOCK_REL;
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return $this->result(false, 'lock_open_failed');
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        try {
            while (true) {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    return $this->result(false, 'commit_lock_contended'); // FAIL-CLOSED — never commit unlocked
                }
                usleep(self::LOCK_POLL_MICROSECONDS);
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{code:int, out:string, err:string}
     */
    private function git(string $repo, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['code' => 1, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }

    /**
     * Parse `git status --porcelain` (scoped by pathspec) into the list of changed file paths. Handles
     * untracked (`??`), added/modified/deleted, and rename (` -> `, keep the new path).
     *
     * @return list<string>
     */
    private function changedPaths(string $porcelain): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $porcelain) ?: [] as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = (string) end($parts);
            }
            $path = trim($path, "\"");
            if ($path !== '') {
                $out[$path] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            $p = ltrim(trim(str_replace('\\', '/', (string) $f)), '/');
            if ($p !== '' && ! str_contains($p, '..')) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }

    private function repoRoot(): string
    {
        return $this->repoRootOverride ?? base_path();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(bool $committed, string $reason, string $taskPacketId = '', array $extra = []): array
    {
        return array_merge([
            'schema' => 'atlas.task_serving.scoped_commit.v1',
            'committed' => $committed,
            'reason' => $reason,
            'task_packet_id' => $taskPacketId,
        ], $extra);
    }
}
