<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioDiffMetricsCalculator;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasEvolutionScenarioDiffMetricsCalculator::diffSize()} at the operator surface: for
 * a workspace, emits the scenario diff size (files + lines across unstaged / staged / untracked) as
 * deterministic facts. Read-only — it only runs `git diff`/`git ls-files` and counts; it never mutates.
 */
final class AtlasLoopScenarioDiffMetricsCommand extends Command
{
    protected $signature = 'atlas:loop:scenario-diff-metrics {--workspace=} {--json}';

    protected $description = 'Read-only scenario diff-size metrics (files + lines) for a workspace.';

    public function handle(AtlasEvolutionScenarioDiffMetricsCalculator $calculator): int
    {
        $workspace = trim((string) $this->option('workspace'));
        if ($workspace === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'workspace_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_dir($workspace)) {
            $this->line((string) json_encode(['status' => 'workspace_not_found', 'workspace' => $workspace], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $metrics = $calculator->diffSize($workspace);

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.scenario_diff_metrics.v1',
            'workspace' => $workspace,
            'files' => (int) $metrics['files'],
            'lines' => (int) $metrics['lines'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
