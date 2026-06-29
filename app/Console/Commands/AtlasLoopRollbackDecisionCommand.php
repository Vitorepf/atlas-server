<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AutoRollbackDecider;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2BlastRadiusCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Arms the dormant {@see AtlasLoopV2AutoRollbackDecider} at the operator surface: assembles post-merge signals
 * (--tests-failing / --red-streak / --perf-regression) and a blast-radius result (--blast-tier) and emits the
 * recommended action (none / quarantine / revert) with severity, reason and evidence.
 *
 * ADVISORY only: it never executes a revert/quarantine/git/merge or mutates the queue — the only write is the
 * decider's own audit-journal append.
 */
final class AtlasLoopRollbackDecisionCommand extends Command
{
    protected $signature = 'atlas:loop:rollback-decision {--blast-tier=safe} {--tests-failing=0} {--red-streak=0} {--perf-regression=0} {--json}';

    protected $description = 'Advisory post-merge rollback recommendation (none | quarantine | revert) — never executes it.';

    public function handle(): int
    {
        $path = (string) config('atlas.loop.v2.rollback_journal_path', storage_path('atlas/loop/v2/rollback-decisions.jsonl'));
        File::ensureDirectoryExists(dirname($path));

        $decider = new AtlasLoopV2AutoRollbackDecider(
            $this->getLaravel()->make(AtlasLoopV2BlastRadiusCalculator::class),
            new AtlasLoopV2AuditJournal($path),
        );

        $decision = $decider->decide(
            [
                'tests_failing' => (int) $this->option('tests-failing'),
                'red_main_streak' => (int) $this->option('red-streak'),
                'perf_regression_pct' => (float) $this->option('perf-regression'),
            ],
            ['tier' => (string) $this->option('blast-tier')],
        );

        $this->line((string) json_encode($decision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
