<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;

/**
 * Stateless diff-metrics collaborator extracted from {@see AtlasEvolutionScenarioExplorer}: pure
 * git-diff sizing (files + lines across unstaged/staged/untracked) and "is smaller" comparison.
 * Behavior byte-identical to the original in-explorer implementation at lines ~954-1050.
 */
final class AtlasEvolutionScenarioDiffMetricsCalculator
{
    /**
     * @return array{files: int, lines: int}
     */
    public function diffSize(string $workspace): array
    {
        $stat = new Process(['git', 'diff', '--numstat', '--no-ext-diff'], $workspace, null, null, 30.0);
        $stat->run();
        $unstaged = $this->numstatSize($stat);

        // STAGED edits too — `git add` removes a file from the unstaged numstat, so a provider that
        // stages its work would otherwise register as a zero-diff (a real win silently dropped).
        $cachedStat = new Process(['git', 'diff', '--cached', '--numstat', '--no-ext-diff'], $workspace, null, null, 30.0);
        $cachedStat->run();
        $staged = $this->numstatSize($cachedStat);

        // include untracked additions in the file and line count
        $others = new Process(['git', 'ls-files', '--others', '--exclude-standard'], $workspace, null, null, 30.0);
        $others->run();
        $untracked = $this->untrackedSize($workspace, $others);

        return [
            'files' => $unstaged['files'] + $staged['files'] + $untracked['files'],
            'lines' => $unstaged['lines'] + $staged['lines'] + $untracked['lines'],
        ];
    }

    /**
     * @return array{files: int, lines: int}
     */
    public function numstatSize(Process $stat): array
    {
        $files = 0;
        $lines = 0;
        foreach (preg_split('/\R/', trim((string) $stat->getOutput())) ?: [] as $row) {
            if (preg_match('/^(\d+|-)\s+(\d+|-)\s+/', $row, $m) === 1) {
                $files++;
                $lines += (is_numeric($m[1]) ? (int) $m[1] : 0) + (is_numeric($m[2]) ? (int) $m[2] : 0);
            }
        }

        return ['files' => $files, 'lines' => $lines];
    }

    /**
     * @return array{files: int, lines: int}
     */
    public function untrackedSize(string $workspace, Process $others): array
    {
        $files = 0;
        $lines = 0;
        foreach (preg_split('/\R/', trim((string) $others->getOutput())) ?: [] as $row) {
            if (trim($row) !== '') {
                $files++;
                $contents = (string) file_get_contents($workspace.'/'.trim($row));
                if ($contents !== '') {
                    $lines += substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
                }
            }
        }

        return ['files' => $files, 'lines' => $lines];
    }

    /**
     * @param  array{files:int,lines:int}  $a
     * @param  array{files:int,lines:int}  $b
     */
    public function isSmallerDiff(array $a, array $b): bool
    {
        if ($a['files'] !== $b['files']) {
            return $a['files'] < $b['files'];
        }

        return $a['lines'] < $b['lines'];
    }
}
