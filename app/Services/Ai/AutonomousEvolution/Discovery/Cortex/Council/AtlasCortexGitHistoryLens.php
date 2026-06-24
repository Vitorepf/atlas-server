<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

use Throwable;

/**
 * CORTEX COUNCIL — LENS #3 of 5: GIT-HISTORY. Observes a {@see CortexSubject} (file path) and emits FACTS
 * from git log: commits-touching-file count, last-touched commit sha+timestamp, distinct authors, co-changed
 * files (files appearing in the same commit ≥2 times), and churn (lines added+removed over the last N
 * commits, N from config). NO scoring — pure facts.
 *
 * The subject's facts MUST carry:
 *   - `file_path` : absolute or repo-relative path to the file
 *   - `repo_root` : (optional) absolute path to the git repo; defaults to base_path() / cwd
 *
 * FAILS CLOSED: git missing, path outside repo, or any runner error ⇒ empty facts + disagreement_signal
 * 'git_unavailable' (or the more specific reason). NEVER fabricates a number.
 */
final class AtlasCortexGitHistoryLens implements LensContract
{
    public const CONFIG_WINDOW_KEY = 'atlas.cortex.council.git_history_window';

    public const DEFAULT_WINDOW = 100;

    /** @var null|callable(string $repoRoot, array<int,string> $args):array{exit:int, stdout:string} */
    private $gitRunner;

    /**
     * @param  null|callable(string,array<int,string>):array{exit:int, stdout:string}  $gitRunner
     *         Test seam — production wraps `proc_open` of the git binary. Returns {exit, stdout}.
     */
    public function __construct(?callable $gitRunner = null)
    {
        $this->gitRunner = $gitRunner;
    }

    public function id(): string
    {
        return 'githistory';
    }

    public function name(): string
    {
        return 'Git-History';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        $facts = $subject->facts;
        $filePath = isset($facts['file_path']) ? trim((string) $facts['file_path']) : '';
        $repoRoot = isset($facts['repo_root']) ? rtrim((string) $facts['repo_root'], '/') : $this->defaultRepoRoot();

        if ($filePath === '' || $repoRoot === '') {
            return $this->empty($subject, ['missing_file_or_repo']);
        }
        if (! is_dir($repoRoot.'/.git')) {
            return $this->empty($subject, ['repo_root_not_a_git_dir']);
        }

        $relPath = $this->repoRelative($repoRoot, $filePath);
        if ($relPath === null) {
            return $this->empty($subject, ['path_outside_repo']);
        }

        $window = $this->configuredWindow();
        $disagreement = [];

        // 1) Get the commit SHAs touching this file (window-bounded). `git log -- <file>` correctly returns
        //    commits-that-touched-file ordered newest→oldest. We deliberately drop --follow + --name-only
        //    here because git's --name-only output is filtered to ONLY the matching file when combined with
        //    a path filter — which would lose every co-changed sibling. We fetch the full per-commit file
        //    list in pass 2 via `git show --name-only`.
        $log = $this->runGit($repoRoot, ['log', '--pretty=format:__C__%H%x09%aI%x09%aN', '-n', (string) $window, '--', $relPath]);
        if ($log === null) {
            return $this->empty($subject, ['git_unavailable']);
        }
        $commits = [];
        foreach (preg_split('/\R/', trim($log)) ?: [] as $line) {
            if (! str_starts_with($line, '__C__')) {
                continue;
            }
            $parts = explode("\t", substr($line, strlen('__C__')));
            $commits[] = [
                'sha' => (string) ($parts[0] ?? ''),
                'date' => (string) ($parts[1] ?? ''),
                'author' => (string) ($parts[2] ?? ''),
                'files' => [],
            ];
        }
        if ($commits === []) {
            return $this->empty($subject, ['no_commits_touch_file']);
        }

        // 2) For each commit, fetch its full file list. `git show --name-only --pretty=format:` emits the
        //    full per-commit file list unfiltered — this is what we need for co-changed.
        foreach ($commits as $idx => $c) {
            $show = $this->runGit($repoRoot, ['show', '--name-only', '--pretty=format:', $c['sha']]);
            if ($show === null) {
                $disagreement[] = 'show_unavailable_for:'.$c['sha'];

                continue;
            }
            $files = [];
            foreach (preg_split('/\R/', trim($show)) ?: [] as $f) {
                $f = trim($f);
                if ($f !== '') {
                    $files[] = $f;
                }
            }
            $commits[$idx]['files'] = $files;
        }

        // 2) authors + co-changed files aggregation.
        $authors = [];
        $coChangedCount = [];
        foreach ($commits as $commit) {
            $authors[(string) $commit['author']] = true;
            foreach ((array) $commit['files'] as $f) {
                if ($f === $relPath) {
                    continue;
                }
                $coChangedCount[$f] = ($coChangedCount[$f] ?? 0) + 1;
            }
        }
        $coChanged = [];
        foreach ($coChangedCount as $f => $n) {
            if ($n >= 2) {
                $coChanged[] = ['file' => $f, 'co_touched' => $n];
            }
        }
        usort($coChanged, static function (array $x, array $y): int {
            return [$y['co_touched'], $x['file']] <=> [$x['co_touched'], $y['file']];
        });

        // 3) churn (numstat) over the same window for the file alone.
        $numstat = $this->runGit($repoRoot, ['log', '--follow', '--numstat', '--pretty=format:', '-n', (string) $window, '--', $relPath]);
        $churn = ['added' => 0, 'removed' => 0];
        if ($numstat === null) {
            $disagreement[] = 'numstat_unavailable';
        } else {
            foreach (preg_split('/\R/', trim($numstat)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                if ($parts === false || count($parts) < 2) {
                    continue;
                }
                if (! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                    // Binary file ⇒ git emits "- - <path>"; surface as disagreement.
                    $disagreement[] = 'binary_diff_skipped';

                    continue;
                }
                $churn['added'] += (int) $parts[0];
                $churn['removed'] += (int) $parts[1];
            }
        }

        ksort($authors);
        $factsOut = [
            'commit_count' => count($commits),
            'last_touched_sha' => (string) $commits[0]['sha'],
            'last_touched_at' => (string) $commits[0]['date'],
            'authors' => array_values(array_keys($authors)),
            'co_changed' => $coChanged,
            'churn_added' => $churn['added'],
            'churn_removed' => $churn['removed'],
            'window' => $window,
        ];

        return new LensObservation($this->id(), $subject->id, $factsOut, array_values(array_unique($disagreement)));
    }

    private function empty(CortexSubject $subject, array $disagreement): LensObservation
    {
        return new LensObservation($this->id(), $subject->id, [
            'commit_count' => 0,
            'last_touched_sha' => null,
            'last_touched_at' => null,
            'authors' => [],
            'co_changed' => [],
            'churn_added' => 0,
            'churn_removed' => 0,
            'window' => $this->configuredWindow(),
        ], $disagreement);
    }

    private function configuredWindow(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_WINDOW;
        }
        try {
            return max(1, (int) config(self::CONFIG_WINDOW_KEY, self::DEFAULT_WINDOW));
        } catch (Throwable) {
            return self::DEFAULT_WINDOW;
        }
    }

    private function defaultRepoRoot(): string
    {
        if (function_exists('base_path')) {
            try {
                return rtrim((string) base_path(), '/');
            } catch (Throwable) {
            }
        }

        return rtrim((string) getcwd(), '/');
    }

    private function repoRelative(string $repoRoot, string $path): ?string
    {
        $repoRoot = rtrim($repoRoot, '/').'/';
        if (str_starts_with($path, '/')) {
            if (! str_starts_with($path, $repoRoot)) {
                return null;
            }

            return substr($path, strlen($repoRoot));
        }

        // already relative
        return $path;
    }

    /**
     * @param  list<string>  $args
     */
    private function runGit(string $repoRoot, array $args): ?string
    {
        $runner = $this->gitRunner;
        if (is_callable($runner)) {
            try {
                $result = $runner($repoRoot, $args);
            } catch (Throwable) {
                return null;
            }
            if (! is_array($result) || ! array_key_exists('exit', $result)) {
                return null;
            }

            return (int) $result['exit'] === 0 ? (string) ($result['stdout'] ?? '') : null;
        }

        $cmd = 'cd '.escapeshellarg($repoRoot).' && git '.implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null; echo "__EXIT__$?"';
        $output = (string) shell_exec($cmd);
        $lines = preg_split('/\R/', $output) ?: [];
        $exit = 0;
        $kept = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '__EXIT__')) {
                $exit = (int) substr($line, strlen('__EXIT__'));

                continue;
            }
            $kept[] = $line;
        }

        return $exit === 0 ? implode("\n", $kept) : null;
    }

    /**
     * Parse the output of `git log --follow --name-only --pretty=format:__C__%H%x09%aI%x09%aN`.
     *
     * @return array{commits:list<array{sha:string,date:string,author:string,files:list<string>}>, rename_detected:bool}
     */
    private function parseLog(string $log): array
    {
        $commits = [];
        $renameDetected = false;
        $current = null;
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (str_starts_with($line, '__C__')) {
                if ($current !== null) {
                    $commits[] = $current;
                }
                $parts = explode("\t", substr($line, strlen('__C__')));
                $current = [
                    'sha' => (string) ($parts[0] ?? ''),
                    'date' => (string) ($parts[1] ?? ''),
                    'author' => (string) ($parts[2] ?? ''),
                    'files' => [],
                ];

                continue;
            }
            if ($current === null) {
                continue;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // `git log --follow --name-only` may emit a renamed-from path on a previous line of the same
            // commit — we don't distinguish, so we just flag any commit whose file_path differs from the
            // requested rel path. Simpler heuristic: more than one file from the same commit attributed to
            // this single-file query means follow stitched in a rename.
            $current['files'][] = $line;
            // (rename_detected stays false here — without --follow, multiple files per commit are just
            //  ordinary co-changes; we would need a separate --follow probe to confirm a real rename)
        }
        if ($current !== null) {
            $commits[] = $current;
        }

        return ['commits' => $commits, 'rename_detected' => $renameDetected];
    }
}
