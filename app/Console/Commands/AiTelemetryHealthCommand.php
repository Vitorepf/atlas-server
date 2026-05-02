<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\AiTelemetryHealthService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AiTelemetryHealthCommand extends Command
{
    protected $signature = 'atlas:ai:telemetry:health
        {--hours=24 : Evaluate summaries computed in the last N hours}
        {--recompute : Recompute the same window before evaluating health}
        {--emit : Emit an operational inbox insight when health is warning/critical}
        {--fail-on-critical : Return a non-zero exit code when status is critical}
        {--json : Print machine-readable JSON}';

    protected $description = 'Evaluate Atlas telemetry health and optionally emit an operational insight.';

    public function handle(AiTelemetryHealthService $health, AiTraceMetricAggregator $aggregator): int
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            $this->error('ai_trace_metric_summaries table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $hours = max(1, min(720, (int) $this->option('hours')));
        $since = now()->subHours($hours);
        $recomputed = null;

        if ((bool) $this->option('recompute')) {
            $recomputed = $aggregator->recomputeWindow($since);
        }

        $evaluation = $health->evaluate($since);
        $emission = $health->emitInsight($evaluation, ! (bool) $this->option('emit'));
        $payload = [
            'ok' => true,
            'recomputed' => $recomputed,
            'health' => $evaluation,
            'insight' => $emission,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('status='.$evaluation['status'].' health_score='.($evaluation['health_score'] ?? '-'));
        foreach ((array) ($evaluation['issues'] ?? []) as $issue) {
            $this->line("- {$issue['severity']} {$issue['key']}: {$issue['summary']}");
        }

        return (bool) $this->option('fail-on-critical') && in_array($evaluation['status'] ?? null, ['critical'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
