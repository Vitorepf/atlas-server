<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\AiMetricDailySnapshotService;
use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AiReportEngineBackfillCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:engine:backfill
        {--from= : First local report date, inclusive}
        {--to= : Last local report date, inclusive. Defaults to yesterday}
        {--timezone= : Report timezone. Defaults to atlas.ai_metrics.performance_report_timezone}
        {--type=daily : daily, multi or both}
        {--windows=3,7,15,30 : Comma-separated windows for multi}
        {--engine-version=shadow : shadow or next}
        {--run-mode=shadow : shadow, live or dry_run}
        {--refresh-snapshots : Refresh daily snapshots for the backfill range first}
        {--recompute : Recompute metric summaries for each report and baseline window first}
        {--limit=90 : Safety cap for number of dates processed}
        {--json : Print machine-readable JSON}';

    protected $description = 'Backfill Atlas report engine runs over a local date range.';

    public function handle(
        AiTelemetryPerformanceReportService $reports,
        AiTraceMetricAggregator $aggregator,
        AiMetricDailySnapshotService $snapshots,
    ): int {
        $timezone = $this->timezone();
        $to = $this->dateOption('to', $timezone) ?? CarbonImmutable::now($timezone)->subDay()->startOfDay();
        $from = $this->dateOption('from', $timezone) ?? $to;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $limit = max(1, min(365, (int) $this->option('limit')));
        $dates = [];
        for ($date = $from; $date->lte($to) && count($dates) < $limit; $date = $date->addDay()) {
            $dates[] = $date;
        }

        $type = $this->type();
        $windows = $this->windows();
        $engineVersion = $this->engineVersion();
        $runMode = $this->runMode();
        $previousEngineVersion = config('atlas.report.engine_version');
        $previousRunMode = config('atlas.report.engine_run_mode');

        try {
            config()->set('atlas.report.engine_version', $engineVersion);
            config()->set('atlas.report.engine_run_mode', $runMode);

            $snapshotResult = null;
            if ((bool) $this->option('refresh-snapshots')) {
                $snapshotDays = $from->diffInDays($to) + 1;
                $snapshotResult = $snapshots->refreshRange($to, $snapshotDays, $timezone);
            }

            $results = [];
            foreach ($dates as $date) {
                $recomputed = null;
                if ((bool) $this->option('recompute')) {
                    $until = $date->startOfDay()->addDay();
                    $lookback = AiTelemetryPerformanceReportCommand::recomputeLookbackDays($type, $windows);
                    $recomputed = $aggregator->recomputeWindow($until->subDays($lookback), $until);
                }

                $built = [];
                if (in_array($type, ['daily', 'both'], true)) {
                    $built[] = $reports->buildDaily($date, $timezone);
                }
                if (in_array($type, ['multi', 'both'], true)) {
                    $built[] = $reports->buildMultiWindow($date, $windows, $timezone);
                }

                $results[] = [
                    'date' => $date->toDateString(),
                    'recomputed' => $recomputed,
                    'reports' => collect($built)->map(fn (array $report): array => [
                        'report_type' => $report['report_type'] ?? null,
                        'schema_version' => $report['schema_version'] ?? null,
                        'engine_run_id' => data_get($report, 'engine._meta.run_id'),
                        'engine_version' => data_get($report, 'engine._meta.engine_version'),
                        'status' => $report['status'] ?? null,
                    ])->all(),
                ];
            }

            $payload = [
                'ok' => true,
                'timezone' => $timezone,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'processed_dates' => count($dates),
                'truncated_by_limit' => $from->diffInDays($to) + 1 > count($dates),
                'type' => $type,
                'engine_version' => $engineVersion,
                'run_mode' => $runMode,
                'snapshot_refresh' => $snapshotResult,
                'results' => $results,
            ];

            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));

                return self::SUCCESS;
            }

            $this->line("processed_dates={$payload['processed_dates']} from={$payload['from']} to={$payload['to']}");

            return self::SUCCESS;
        } finally {
            config()->set('atlas.report.engine_version', $previousEngineVersion);
            config()->set('atlas.report.engine_run_mode', $previousRunMode);
        }
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

    private function dateOption(string $name, string $timezone): ?CarbonImmutable
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse(trim($value), $timezone)->startOfDay();
    }

    private function type(): string
    {
        $type = $this->option('type');
        $type = is_string($type) ? trim($type) : 'daily';

        return in_array($type, ['daily', 'multi', 'both'], true) ? $type : 'daily';
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

    private function engineVersion(): string
    {
        $value = $this->option('engine-version');
        $value = is_string($value) ? trim($value) : 'shadow';

        return in_array($value, ['shadow', 'next'], true) ? $value : 'shadow';
    }

    private function runMode(): string
    {
        $value = $this->option('run-mode');
        $value = is_string($value) ? trim($value) : 'shadow';

        return in_array($value, ['live', 'shadow', 'dry_run'], true) ? $value : 'shadow';
    }
}
