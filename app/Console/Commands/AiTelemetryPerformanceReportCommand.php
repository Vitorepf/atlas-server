<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AiTelemetryPerformanceReportCommand extends Command
{
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

    protected $description = 'Build and optionally emit Atlas AI daily and multi-window performance reports.';

    public function handle(AiTelemetryPerformanceReportService $reports, AiTraceMetricAggregator $aggregator): int
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            $this->error('ai_trace_metric_summaries table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $timezone = $this->timezone();
        $reportDate = $this->reportDate($timezone);
        $type = $this->type();
        $windows = $this->windows();
        $emit = (bool) $this->option('emit') && ! (bool) $this->option('dry-run');
        $built = [];
        $emissions = [];
        $recomputed = null;

        if ((bool) $this->option('recompute')) {
            $maxDays = max($windows ?: [1]);
            $since = $reportDate->startOfDay()->addDay()->subDays($type === 'daily' ? 1 : $maxDays);
            $until = $reportDate->startOfDay()->addDay();
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
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($built as $report) {
            $this->line(($report['title'] ?? 'Relatorio Atlas AI').' status='.($report['status'] ?? 'unknown'));
            $this->line((string) ($report['summary_text'] ?? $report['executive_summary'] ?? ''));
        }

        return self::SUCCESS;
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

    private function type(): string
    {
        $type = $this->option('type');
        $type = is_string($type) ? trim($type) : 'auto';
        if (! in_array($type, ['daily', 'multi', 'both', 'auto'], true)) {
            $type = 'auto';
        }

        if ($type !== 'auto') {
            return $type;
        }

        $dayOfMonth = CarbonImmutable::now($this->timezone())->day;

        return in_array($dayOfMonth, [15, 30], true) ? 'both' : 'daily';
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
