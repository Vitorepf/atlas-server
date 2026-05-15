<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Code · GitWorkspaceInspector.
 *
 * Captures a read-only snapshot of the operator's workspace via `git`.
 * Used by ObservedSessionService::importResult when the operator did not
 * paste a `diff_excerpt`/`files` payload — Atlas auto-captures honestly so
 * scope guard has real data to evaluate.
 *
 * Hard rules:
 *   - NEVER writes to the workspace (read-only commands only).
 *   - NEVER executes shell strings (always array args; no shell injection).
 *   - Always has a timeout (default 30s).
 *   - When git is unavailable / workspace is not a repo / command fails,
 *     returns a structured "blocker" instead of throwing. The caller decides.
 *
 * Schema: atlas.code.git_workspace_snapshot.v1
 */
final class GitWorkspaceInspector
{
    public const SCHEMA_VERSION = 'atlas.code.git_workspace_snapshot.v1';

    private const DEFAULT_TIMEOUT_SECONDS = 30;
    private const MAX_FILES_LISTED = 500;
    private const MAX_DIFF_EXCERPT_BYTES = 32768;

    public function __construct(private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
    {
    }

    /**
     * @return array{
     *   schema_version: string,
     *   success: bool,
     *   is_git: bool,
     *   workspace_path: string,
     *   workspace_path_exists: bool,
     *   head_sha: ?string,
     *   branch: ?string,
     *   files_changed: array<int, string>,
     *   files_changed_truncated: bool,
     *   diff_excerpt: ?string,
     *   diff_truncated: bool,
     *   diff_hash: ?string,
     *   blocker_reason: ?string,
     *   captured_at: string
     * }
     */
    public function captureSnapshot(string $workspacePath): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'success' => false,
            'is_git' => false,
            'workspace_path' => $workspacePath,
            'workspace_path_exists' => false,
            'head_sha' => null,
            'branch' => null,
            'files_changed' => [],
            'files_changed_truncated' => false,
            'diff_excerpt' => null,
            'diff_truncated' => false,
            'diff_hash' => null,
            'blocker_reason' => null,
            'captured_at' => now()->toJSON(),
        ];

        if ($workspacePath === '' || ! @is_dir($workspacePath)) {
            return array_merge($base, ['blocker_reason' => 'workspace_path_missing_or_unreadable']);
        }
        $base['workspace_path_exists'] = true;

        if (! $this->isGitRepository($workspacePath)) {
            return array_merge($base, ['blocker_reason' => 'not_a_git_repository']);
        }
        $base['is_git'] = true;

        try {
            $headSha = $this->run($workspacePath, ['git', 'rev-parse', 'HEAD']);
            $branch = $this->run($workspacePath, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
            // --porcelain=v1: stable machine-readable status; `--untracked-files=all`
            // so newly-created files by the provider appear honestly.
            $status = $this->run($workspacePath, ['git', 'status', '--porcelain=v1', '--untracked-files=all']);
            // Unified diff vs HEAD + working tree (does NOT include staged-only).
            // We include untracked via `git diff --no-color HEAD` for tracked
            // changes and detect untracked separately from status.
            $diff = $this->run($workspacePath, ['git', 'diff', '--no-color', 'HEAD']);
        } catch (Throwable $e) {
            return array_merge($base, [
                'blocker_reason' => 'git_command_failed:'.substr($e->getMessage(), 0, 200),
            ]);
        }

        $files = $this->parseStatusFiles($status);
        $filesTruncated = false;
        if (count($files) > self::MAX_FILES_LISTED) {
            $files = array_slice($files, 0, self::MAX_FILES_LISTED);
            $filesTruncated = true;
        }

        $diffExcerpt = $diff;
        $diffTruncated = false;
        if (strlen($diffExcerpt) > self::MAX_DIFF_EXCERPT_BYTES) {
            $diffExcerpt = mb_substr($diffExcerpt, 0, self::MAX_DIFF_EXCERPT_BYTES);
            $diffTruncated = true;
        }
        $diffHash = $diffExcerpt !== '' ? hash('sha256', $diff) : null;

        return array_merge($base, [
            'success' => true,
            'head_sha' => trim($headSha) !== '' ? trim($headSha) : null,
            'branch' => trim($branch) !== '' ? trim($branch) : null,
            'files_changed' => $files,
            'files_changed_truncated' => $filesTruncated,
            'diff_excerpt' => $diffExcerpt !== '' ? $diffExcerpt : null,
            'diff_truncated' => $diffTruncated,
            'diff_hash' => $diffHash,
        ]);
    }

    public function isGitRepository(string $workspacePath): bool
    {
        if (@is_dir($workspacePath.'/.git') || @is_file($workspacePath.'/.git')) {
            return true;
        }
        try {
            $out = $this->run($workspacePath, ['git', 'rev-parse', '--is-inside-work-tree']);
            return trim($out) === 'true';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(string $cwd, array $command): string
    {
        $process = new Process($command, $cwd, null, null, $this->timeoutSeconds);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        return $process->getOutput();
    }

    /**
     * Parse `git status --porcelain=v1` output. Each line is:
     *   `XY <path>` or `XY <orig> -> <new>` for renames.
     *
     * @return array<int, string>
     */
    private function parseStatusFiles(string $statusOutput): array
    {
        $files = [];
        foreach (preg_split('/\r?\n/', $statusOutput) ?: [] as $line) {
            if ($line === '' || strlen($line) < 4) {
                continue;
            }
            $rest = substr($line, 3);
            // Handle renames "old -> new"
            if (str_contains($rest, ' -> ')) {
                [$_old, $new] = explode(' -> ', $rest, 2);
                $files[] = trim($new);
            } else {
                $files[] = trim($rest);
            }
        }
        return array_values(array_unique(array_filter($files, static fn (string $f): bool => $f !== '')));
    }
}
