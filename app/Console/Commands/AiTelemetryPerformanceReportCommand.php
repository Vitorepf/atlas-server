<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

class AiTelemetryPerformanceReportCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:telemetry:performance-report
        {--date= : Local report date. Defaults to yesterday in the configured timezone}
        {--timezone= : Report timezone. Defaults to atlas.ai_metrics.performance_report_timezone}
        {--type=auto : daily, multi, both or auto}
        {--windows=3,7,15,30 : Comma-separated rolling windows for the multi-window report}
        {--user=vitor : Inbox user id}
        {--emit : Create or update the mobile inbox notification}
        {--dry-run : Build the report without persisting or sending}
        {--recompute : Recompute trace summaries for the required windows first}
        {--json : Print machine-readable JSON}';

    protected $description = 'Build and optionally emit Atlas daily and multi-window performance reports.';

    public function handle(AiTelemetryPerformanceReportService $reports, AiTraceMetricAggregator $aggregator): int
    {
        if (! DatabaseTableAvailability::has('ai_trace_metric_summaries')) {
            $this->error('ai_trace_metric_summaries table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $timezone = $this->timezone();
        $reportDate = $this->reportDate($timezone);
        $type = $this->type($reportDate, $timezone);
        $windows = $this->windows();
        $emit = (bool) $this->option('emit') && ! (bool) $this->option('dry-run');
        $dryRun = (bool) $this->option('dry-run');
        $built = [];
        $emissions = [];
        $recomputed = null;
        $previousRunMode = config('atlas.report.engine_run_mode');

        try {
            if ($dryRun) {
                config()->set('atlas.report.engine_run_mode', 'dry_run');
            }

            if ((bool) $this->option('recompute')) {
                $until = $reportDate->startOfDay()->addDay();
                $since = $until->subDays(self::recomputeLookbackDays($type, $windows));
                $recomputed = $aggregator->recomputeWindow($since, $until);
            }

            if (in_array($type, ['daily', 'both'], true)) {
                $daily = $reports->buildDaily($reportDate, $timezone);
                $built[] = $daily;
                $emissions[] = $reports->emit($daily, $this->userId(), ! $emit);
            }

            if (in_array($type, ['multi', 'both'], true)) {
                $multi = $reports->buildMultiWindow($reportDate, $windows, $timezone);
                $built[] = $multi;
                $emissions[] = $reports->emit($multi, $this->userId(), ! $emit);
            }

            $payload = [
                'ok' => true,
                'timezone' => $timezone,
                'report_date' => $reportDate->toDateString(),
                'type' => $type,
                'recomputed' => $recomputed,
                'reports' => $built,
                'emissions' => $emissions,
            ];

            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));

                return self::SUCCESS;
            }

            foreach ($built as $report) {
                $this->line(($report['title'] ?? 'Relatorio Atlas').' status='.($report['status'] ?? 'unknown'));
                $this->line((string) ($report['summary_text'] ?? $report['executive_summary'] ?? ''));
            }

            return self::SUCCESS;
        } finally {
            if ($dryRun) {
                config()->set('atlas.report.engine_run_mode', $previousRunMode);
            }
        }
    }

    /**
     * Recompute must include the report window and its comparison baseline:
     * daily compares against the previous day; multi compares each rolling window
     * against the immediately previous equivalent window.
     *
     * @param  array<int,int>  $windows
     */
    public static function recomputeLookbackDays(string $type, array $windows): int
    {
        if ($type === 'daily') {
            return 2;
        }

        $maxDays = max($windows ?: [1]);

        return max(2, $maxDays * 2);
    }

    private function timezone(): string
    {
        $value = $this->option('timezone');
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $configured = (string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC'));

        return $configured !== '' ? $configured : 'UTC';
    }

    private function reportDate(string $timezone): CarbonImmutable
    {
        $value = $this->option('date');
        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse(trim($value), $timezone)->startOfDay();
        }

        return CarbonImmutable::now($timezone)->subDay()->startOfDay();
    }

    private function type(CarbonImmutable $reportDate, string $timezone): string
    {
        $type = $this->option('type');
        $type = is_string($type) ? trim($type) : 'auto';
        if (! in_array($type, ['daily', 'multi', 'both', 'auto'], true)) {
            $type = 'auto';
        }

        if ($type !== 'auto') {
            return $type;
        }

        $deliveryDate = $this->hasExplicitDateOption()
            ? $reportDate->addDay()
            : CarbonImmutable::now($timezone);
        $dayOfMonth = $deliveryDate->day;

        return in_array($dayOfMonth, [15, 30], true) ? 'both' : 'daily';
    }

    private function hasExplicitDateOption(): bool
    {
        $value = $this->option('date');

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<int,int>
     */
    private function windows(): array
    {
        $value = $this->option('windows');
        $raw = is_string($value) ? explode(',', $value) : [];
        $windows = collect($raw)
            ->map(fn (string $entry): int => (int) trim($entry))
            ->filter(fn (int $entry): bool => $entry > 0 && $entry <= 365)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $windows === [] ? [3, 7, 15, 30] : $windows;
    }

    private function userId(): string
    {
        $value = $this->option('user');

        return is_string($value) && trim($value) !== '' ? trim($value) : 'vitor';
    }
}
