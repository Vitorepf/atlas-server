<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

use Throwable;

/**
 * The FACT-shape this detector emits — never a verdict, never a number. The auto-merge service consults
 * {@see $clean} as a fail-closed go/no-go and surfaces {@see $pathsInConflict} + {@see $overlappingHunks} as
 * evidence; the operator (or a downstream resolver) decides what to do next. Co-located with the detector.
 */
final class AtlasLoopAutoMergeConflictReport
{
    /**
     * @param  list<string>  $pathsInConflict
     * @param  list<array{path:string,start:int,end:int}>  $overlappingHunks
     */
    public function __construct(
        public readonly bool $clean,
        public readonly array $pathsInConflict,
        public readonly array $overlappingHunks,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @return array{clean:bool, paths_in_conflict:list<string>, overlapping_hunks:list<array{path:string,start:int,end:int}>, reason:?string}
     */
    public function toArray(): array
    {
        return [
            'clean' => $this->clean,
            'paths_in_conflict' => $this->pathsInConflict,
            'overlapping_hunks' => $this->overlappingHunks,
            'reason' => $this->reason,
        ];
    }

    public static function clean(): self
    {
        return new self(true, [], []);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<array{path:string,start:int,end:int}>  $hunks
     */
    public static function dirty(array $paths, array $hunks, ?string $reason = null): self
    {
        return new self(false, $paths, $hunks, $reason);
    }
}

/**
 * AUTO-MERGE CONFLICT DETECTOR — performs a real 3-way merge-tree probe of a proposal branch against current
 * main HEAD and reports FACTs only: which paths would conflict and which hunks overlap. The detector emits no
 * scalar score, applies no file-count gate, attempts no auto-resolution. The auto-merge service is
 * wired to refuse the merge when {@see AtlasLoopAutoMergeConflictReport::$clean} is false — that is the only
 * decision drawn from this report.
 *
 * PURE / TESTABLE: the actual `git merge-tree` invocation is a closure injected at construction time. The
 * default runner shells out to git; tests inject a fake runner returning structured probe output. The detector
 * itself only consumes the runner's structured result and shapes the report.
 */
final class AtlasLoopAutoMergeConflictDetector
{
    /** @var callable(string $repoRoot, string $mainSha, string $branchRef): array{conflicted_files:list<string>, conflicted_hunks:list<array{path:string,start:int,end:int}>, runner_error:?string} */
    private $mergeTreeProbe;

    /**
     * @param  null|callable(string,string,string):array{conflicted_files:list<string>, conflicted_hunks:list<array{path:string,start:int,end:int}>, runner_error:?string}  $mergeTreeProbe
     */
    public function __construct(?callable $mergeTreeProbe = null)
    {
        $this->mergeTreeProbe = $mergeTreeProbe ?? self::defaultProbe();
    }

    /**
     * Run the probe and emit a fact-shaped report. Any runner failure is surfaced as a NON-clean report with
     * the runner_error as the reason — fail-closed so the auto-merge service refuses on probe failure rather
     * than guessing.
     */
    public function detect(string $repoRoot, string $mainSha, string $branchRef): AtlasLoopAutoMergeConflictReport
    {
        try {
            $result = ($this->mergeTreeProbe)($repoRoot, $mainSha, $branchRef);
        } catch (Throwable $e) {
            return AtlasLoopAutoMergeConflictReport::dirty([], [], 'probe_threw:'.$e->getMessage());
        }

        if (! is_array($result)) {
            return AtlasLoopAutoMergeConflictReport::dirty([], [], 'probe_returned_non_array');
        }

        $runnerError = isset($result['runner_error']) ? (string) $result['runner_error'] : null;
        if ($runnerError !== null && $runnerError !== '') {
            return AtlasLoopAutoMergeConflictReport::dirty([], [], 'probe_runner_error:'.$runnerError);
        }

        $paths = $this->normalisePaths($result['conflicted_files'] ?? []);
        $hunks = $this->normaliseHunks($result['conflicted_hunks'] ?? []);

        if ($paths === [] && $hunks === []) {
            return AtlasLoopAutoMergeConflictReport::clean();
        }

        return AtlasLoopAutoMergeConflictReport::dirty($paths, $hunks);
    }

    /**
     * @param  mixed  $paths
     * @return list<string>
     */
    private function normalisePaths(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }
        $out = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path !== '' && ! in_array($path, $out, true)) {
                $out[] = $path;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * @param  mixed  $hunks
     * @return list<array{path:string,start:int,end:int}>
     */
    private function normaliseHunks(mixed $hunks): array
    {
        if (! is_array($hunks)) {
            return [];
        }
        $out = [];
        foreach ($hunks as $hunk) {
            if (! is_array($hunk)) {
                continue;
            }
            $path = trim((string) ($hunk['path'] ?? ''));
            $start = (int) ($hunk['start'] ?? 0);
            $end = (int) ($hunk['end'] ?? 0);
            if ($path === '') {
                continue;
            }
            $out[] = ['path' => $path, 'start' => $start, 'end' => $end];
        }
        usort($out, static fn (array $x, array $y): int => [$x['path'], $x['start']] <=> [$y['path'], $y['start']]);

        return $out;
    }

    /**
     * Production probe: `git merge-tree --write-tree --name-only $mainSha $branchRef` returns the new tree on
     * line 1 and any conflicted paths on subsequent lines. We capture stdout, treat a non-zero exit as a
     * conflict signal (git returns 1 on conflicts), and parse the path list. Hunk ranges are not extracted
     * by --name-only; the detector reports paths only, with empty hunks — the operator inspects the file.
     *
     * @return callable(string,string,string):array{conflicted_files:list<string>, conflicted_hunks:list<array{path:string,start:int,end:int}>, runner_error:?string}
     */
    private static function defaultProbe(): callable
    {
        return static function (string $repoRoot, string $mainSha, string $branchRef): array {
            $repoRoot = rtrim($repoRoot, '/');
            if ($repoRoot === '' || ! is_dir($repoRoot.'/.git')) {
                return ['conflicted_files' => [], 'conflicted_hunks' => [], 'runner_error' => 'repo_root_not_a_git_dir'];
            }

            $cmd = sprintf(
                'cd %s && git merge-tree --write-tree --name-only %s %s 2>&1; echo "__EXIT__$?"',
                escapeshellarg($repoRoot),
                escapeshellarg($mainSha),
                escapeshellarg($branchRef),
            );
            $output = (string) shell_exec($cmd);
            $lines = preg_split('/\R/', trim($output)) ?: [];
            $exit = 0;
            $kept = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, '__EXIT__')) {
                    $exit = (int) substr($line, strlen('__EXIT__'));

                    continue;
                }
                $kept[] = $line;
            }

            if ($exit !== 0 && $exit !== 1) {
                return ['conflicted_files' => [], 'conflicted_hunks' => [], 'runner_error' => 'git_merge_tree_exit:'.$exit];
            }

            // Line 1 is the resulting tree's SHA; subsequent lines are the conflicting paths (when exit=1).
            $paths = [];
            if ($exit === 1 && count($kept) > 1) {
                $paths = array_values(array_filter(array_slice($kept, 1), static fn (string $l): bool => trim($l) !== ''));
            }

            return ['conflicted_files' => $paths, 'conflicted_hunks' => [], 'runner_error' => null];
        };
    }
}
