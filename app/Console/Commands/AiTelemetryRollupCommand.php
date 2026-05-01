<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AiTelemetryRollupCommand extends Command
{
    protected $signature = 'atlas:ai:telemetry:rollup
        {--hours=24 : Recompute traces created in the last N hours}
        {--trace= : Recompute a single trace id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Recompute Atlas AI trace metric summaries and print the current telemetry scorecard.';

    public function handle(AiTraceMetricAggregator $aggregator, AiTelemetryScorecardService $scorecards): int
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            $this->error('ai_trace_metric_summaries table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $traceId = $this->option('trace');
        $hours = max(1, min(720, (int) $this->option('hours')));
        $since = now()->subHours($hours);
        $recomputed = 0;

        if (is_string($traceId) && trim($traceId) !== '') {
            $aggregator->recomputeTrace(trim($traceId));
            $recomputed = 1;
        } else {
            $recomputed = $aggregator->recomputeWindow($since);
        }

        $payload = [
            'ok' => true,
            'recomputed' => $recomputed,
            'scorecard' => $scorecards->build($since),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $totals = (array) data_get($payload, 'scorecard.totals', []);
        $this->info("Atlas AI telemetry rollup recomputed {$recomputed} trace(s).");
        $this->line('quality avg='.($totals['final_quality_avg'] ?? '-').' efficiency avg='.($totals['final_efficiency_avg'] ?? '-'));
        $this->line('first-pass='.($totals['first_pass_success_rate'] ?? '-').' remediation='.($totals['needed_remediation_rate'] ?? '-'));
        $this->line('latency avg ms='.($totals['total_latency_avg_ms'] ?? '-').' unknown cost='.($totals['unknown_cost_count'] ?? '-'));

        return self::SUCCESS;
    }
}
