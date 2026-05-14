<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Symfony\Component\Process\Process;

/**
 * Workspace Hygiene Service v1.
 *
 * Single source of truth for "is the Atlas Rivals workspace clean enough to
 * be trusted as Rivals evidence?". Detects tracked Python bytecode (the root
 * cause of dirty-after-run for this repo), exports the env vars that prevent
 * future generation, and lets callers diff a workspace before vs. after a
 * subprocess run so the Rivals evidence pack can record an honest
 * after_clean_check.
 *
 * Schema namespace: atlas.programming.workspace_hygiene.v1
 */
class WorkspaceHygieneService
{
    public const SCHEMA_VERSION = 'atlas.programming.workspace_hygiene.v1';

    /** @var list<string> Patterns passed to `git ls-files` to flag tracked Python bytecode. */
    public const PYTHON_BYTECODE_PATTERNS = [
        '*.pyc',
        '*.pyo',
        '*__pycache__*',
    ];

    /**
     * Return whether $workspace is a git worktree, and the list of tracked
     * files matching {@see PYTHON_BYTECODE_PATTERNS}. Tracked Python bytecode
     * is a hard blocker for Rivals because every Python run mutates those
     * bytes and shows up as `git status` dirty — the workspace can never be
     * proved clean while these stay tracked.
     *
     * @return array{
     *   is_git: bool,
     *   tracked_count: int,
     *   tracked_sample: list<string>,
     *   tracked_truncated: bool,
     *   resolution_command: string,
     * }
     */
    public function trackedPythonBytecode(string $workspace): array
    {
        if (! $this->isGitWorktree($workspace)) {
            return [
                'is_git' => false,
                'tracked_count' => 0,
                'tracked_sample' => [],
                'tracked_truncated' => false,
                'resolution_command' => '',
            ];
        }

        $cmd = array_merge(['git', 'ls-files', '--'], self::PYTHON_BYTECODE_PATTERNS);
        $process = new Process($cmd, $workspace);
        $process->setTimeout(15);
        $process->run();

        $files = collect(explode("\n", trim((string) $process->getOutput())))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->values();

        return [
            'is_git' => true,
            'tracked_count' => $files->count(),
            'tracked_sample' => $files->take(20)->all(),
            'tracked_truncated' => $files->count() > 20,
            'resolution_command' => sprintf(
                "git -C %s rm --cached -r 'runtimes/python/**/__pycache__' '*.pyc' '*.pyo' && git -C %s commit -m 'chore: untrack python bytecode'",
                escapeshellarg($workspace),
                escapeshellarg($workspace),
            ),
        ];
    }

    /**
     * Snapshot the worktree state for "before run" / "after run" comparison.
     * Returns a deterministic sha256 over the porcelain `git status` output
     * plus the HEAD revision so callers can detect any mutation produced by
     * a subprocess inside the workspace.
     *
     * @return array{
     *   is_git: bool,
     *   head_sha: string|null,
     *   status_hash: string,
     *   dirty_count: int,
     *   dirty_files_sample: list<string>,
     *   dirty_files_truncated: bool,
     *   clean: bool,
     * }
     */
    public function snapshot(string $workspace): array
    {
        if (! $this->isGitWorktree($workspace)) {
            return [
                'is_git' => false,
                'head_sha' => null,
                'status_hash' => hash('sha256', 'not_git_workspace'),
                'dirty_count' => 0,
                'dirty_files_sample' => [],
                'dirty_files_truncated' => false,
                'clean' => false,
            ];
        }

        $statusProcess = new Process(['git', 'status', '--porcelain'], $workspace);
        $statusProcess->setTimeout(10);
        $statusProcess->run();
        $statusOutput = (string) $statusProcess->getOutput();

        $dirtyLines = collect(explode("\n", trim($statusOutput)))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(static function (string $line): string {
                $path = preg_replace('/^..\s*/', '', $line);

                return trim(is_string($path) && $path !== '' ? $path : $line);
            })
            ->values();

        $head = new Process(['git', 'rev-parse', 'HEAD'], $workspace);
        $head->setTimeout(5);
        $head->run();
        $headSha = $head->isSuccessful() ? trim($head->getOutput()) : null;
        if ($headSha === '') {
            $headSha = null;
        }

        return [
            'is_git' => true,
            'head_sha' => $headSha,
            'status_hash' => hash('sha256', $statusOutput),
            'dirty_count' => $dirtyLines->count(),
            'dirty_files_sample' => $dirtyLines->take(20)->all(),
            'dirty_files_truncated' => $dirtyLines->count() > 20,
            'clean' => $statusProcess->isSuccessful() && $dirtyLines->isEmpty(),
        ];
    }

    /**
     * Compare a before-snapshot against a fresh snapshot of $workspace and
     * report what changed. Used by AtlasRivalsEvidencePackService to populate
     * `workspace.after_clean_check` so a run that fouls the worktree (even
     * silently — e.g. regenerating a tracked .pyc) cannot be admitted as a
     * valid Rivals battery.
     *
     * @param  array<string,mixed>  $beforeSnapshot
     * @return array{
     *   ran: true,
     *   clean: bool,
     *   hash_before: string,
     *   hash_after: string,
     *   dirty_files: list<string>,
     *   dirty_files_truncated: bool,
     *   head_changed: bool,
     * }
     */
    public function dirtyAfterRun(array $beforeSnapshot, string $workspace): array
    {
        $after = $this->snapshot($workspace);
        $hashBefore = (string) ($beforeSnapshot['status_hash'] ?? '');
        $hashAfter = (string) $after['status_hash'];
        $headBefore = $beforeSnapshot['head_sha'] ?? null;
        $headAfter = $after['head_sha'];

        return [
            'ran' => true,
            'clean' => $after['clean'] && $hashBefore === $hashAfter && $headBefore === $headAfter,
            'hash_before' => $hashBefore,
            'hash_after' => $hashAfter,
            'dirty_files' => (array) $after['dirty_files_sample'],
            'dirty_files_truncated' => (bool) $after['dirty_files_truncated'],
            'head_changed' => $headBefore !== $headAfter,
        ];
    }

    /**
     * Env block forced onto every test/quality subprocess so Python runs do
     * not regenerate tracked .pyc files (the historical root cause of
     * dirty-after-run in this repo). PYTHONPYCACHEPREFIX redirects any
     * unavoidable bytecode to a temp dir outside the worktree.
     *
     * @return array<string,string>
     */
    public function forceBytecodeDisabledEnv(): array
    {
        return [
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONPYCACHEPREFIX' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-rivals-pycache',
        ];
    }

    private function isGitWorktree(string $workspace): bool
    {
        if (! is_dir($workspace)) {
            return false;
        }

        $inside = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $workspace);
        $inside->setTimeout(5);
        $inside->run();

        return $inside->isSuccessful() && trim($inside->getOutput()) === 'true';
    }
}
