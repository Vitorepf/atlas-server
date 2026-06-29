<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryEventProducer;
use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryStarvationDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopTelemetryStarvationDetector} at the operator surface: feeds recent task
 * lifecycle events from {@see AtlasLoopTelemetryEventProducer} into the detector and emits the starvation verdict
 * (starved bool + window + counts) as JSON — so the loop can SEE when workers are claiming tasks but nothing is
 * being served (a stall) instead of grinding blind. Read-only: no queue mutation, no provider, no process spawn.
 */
final class AtlasLoopTelemetryStarvationCommand extends Command
{
    protected $signature = 'atlas:loop:telemetry-starvation {--window=60} {--json}';

    protected $description = 'Read-only pipeline-starvation verdict (claims without serves) over recent task lifecycle.';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));
        $app = $this->getLaravel();

        $events = $app->make(AtlasLoopTelemetryEventProducer::class)->events($window);
        $verdict = $app->make(AtlasLoopTelemetryStarvationDetector::class)->detect($events, gmdate(DATE_ATOM), $window);

        $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
