<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraCostEstimator;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopObraCostEstimator::costsForTarget()} at the operator surface: emits the
 * deterministic, telemetry-measured mean cost per provider for a target path's work-class (the budget
 * scheduler's missing cost input).
 *
 * ADVISORY + read-only: it reports the measured estimate and NEVER schedules an obra or mutates the queue/git.
 * Fail-open by construction — a missing telemetry table / no measurements yields an empty cost map, so an obra
 * stays honestly deferred rather than scheduled on a fabricated cost.
 */
final class AtlasLoopObraCostEstimateCommand extends Command
{
    protected $signature = 'atlas:loop:obra-cost-estimate {--target=} {--hours=} {--json}';

    protected $description = 'Read-only measured mean cost-per-provider estimate for a target path (budget scheduler cost source).';

    public function handle(): int
    {
        $target = trim((string) $this->option('target'));
        if ($target === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'obra-cost-estimate requires --target=<path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $hours = $this->option('hours') !== null && trim((string) $this->option('hours')) !== ''
            ? max(1, (int) $this->option('hours'))
            : null;

        $costs = app(AtlasLoopObraCostEstimator::class)->costsForTarget($target, $hours);

        $facts = [
            'schema' => 'atlas.loop.obra_cost_estimate.v1',
            'target' => $target,
            'provider_count' => count($costs),
            'costs' => $costs,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('target: '.$target);
            foreach ($costs as $provider => $usd) {
                $this->line($provider.': '.$usd);
            }
        }

        return self::SUCCESS;
    }
}
