<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergePreFlightGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAutoMergePreFlightGate::check()} at the operator surface: given a base SHA,
 * emits the auto-merge pre-flight verdict — whether main is still at that base (so a merge would be safe) or
 * has moved — as deterministic facts. Read-only: it takes the pre-flight lock, resolves main's head and
 * compares; it NEVER merges. The optional --repo-root defaults to the current repo.
 */
final class AtlasLoopAutoMergePreflightCommand extends Command
{
    protected $signature = 'atlas:loop:auto-merge-preflight {--base=} {--repo-root=} {--json}';

    protected $description = 'Read-only auto-merge pre-flight verdict (is main still at the given base SHA?).';

    public function handle(AtlasLoopAutoMergePreFlightGate $gate): int
    {
        $base = trim((string) $this->option('base'));
        if ($base === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'base_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }

        $this->line((string) json_encode(
            $gate->check($base, $repoRoot),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
