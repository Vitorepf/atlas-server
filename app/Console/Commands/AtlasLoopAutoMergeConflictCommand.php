<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeConflictDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAutoMergeConflictDetector::detect()} at the operator surface: runs a 3-way
 * merge-tree probe of a branch against a main SHA and emits the conflict report (clean, paths_in_conflict,
 * overlapping_hunks, reason) as deterministic facts. Read-only — it probes, never merges.
 */
final class AtlasLoopAutoMergeConflictCommand extends Command
{
    protected $signature = 'atlas:loop:auto-merge-conflict {--main=} {--branch=} {--json}';

    protected $description = 'Read-only auto-merge conflict probe (would a branch merge cleanly into main?).';

    public function handle(AtlasLoopAutoMergeConflictDetector $detector): int
    {
        $main = trim((string) $this->option('main'));
        $branch = trim((string) $this->option('branch'));
        if ($main === '' || $branch === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'auto-merge-conflict requires --main and --branch',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $report = $detector->detect(base_path(), $main, $branch);
        $this->line((string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
