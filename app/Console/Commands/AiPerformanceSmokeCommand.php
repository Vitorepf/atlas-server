<?php

namespace App\Console\Commands;

use App\Models\AiPerformanceRecommendation;
use App\Services\Ai\Telemetry\AiMetricDailySnapshotService;
use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;
use App\Support\YesNo;

class AiPerformanceSmokeCommand extends Command
{
    protected $signature = 'atlas:ai:performance:smoke
        {--date= : Local report date. Defaults to yesterday in the configured timezone}
        {--timezone= : Report timezone. Defaults to atlas.ai_metrics.performance_report_timezone}
        {--windows=3,7,15,30 : Comma-separated multi-window sizes}
        {--snapshot-days=2 : Number of snapshot days to refresh before building reports}
        {--skip-refresh : Do not refresh daily metric snapshots}
        {--strict : Return failure when warnings are present}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run a safe Atlas performance pipeline smoke check without emitting reports or mutating engine state.';

    public function handle(
        AiMetricDailySnapshotService $snapshots,
        AiTelemetryPerformanceReportService $reports,
    ): int {
        $timezone = $this->timezone();
        $date = $this->date($timezone);
        $windows = $this->windows();
        $strict = (bool) $this->option('strict');
        $warnings = [];
        $failures = [];

        try {
            $requiredTables = $this->requiredTables();
            $tables = collect($requiredTables)
                ->mapWithKeys(fn (string $table): array => [$table => DatabaseTableAvailability::has($table)])
                ->all();
        } catch (Throwable $e) {
            return $this->outputPayload([
                'ok' => false,
                'strict' => $strict,
                'report_date' => $date->toDateString(),
                'timezone' => $timezone,
                'checks' => [
                    'tables' => [],
                    'config' => $this->configChecks(),
                ],
                'warnings' => [],
                'failures' => ['database_unavailable:'.class_basename($e)],
                'error' => substr($e->getMessage(), 0, 500),
            ], self::FAILURE);
        }

        foreach ($tables as $table => $exists) {
            if (! $exists) {
                $failures[] = "missing_table:{$table}";
            }
        }

        if ($failures !== []) {
            return $this->outputPayload([
                'ok' => false,
                'strict' => $strict,
                'report_date' => $date->toDateString(),
                'timezone' => $timezone,
                'checks' => [
                    'tables' => $tables,
                    'config' => $this->configChecks(),
                ],
                'warnings' => $warnings,
                'failures' => $failures,
            ], self::FAILURE);
        }

        $snapshotResult = null;
        if (! (bool) $this->option('skip-refresh')) {
            try {
                $snapshotResult = $snapshots->refreshRange($date, $this->snapshotDays(), $timezone);
            } catch (Throwable $e) {
                $snapshotResult = [
                    'ok' => false,
                    'reason' => 'snapshot_refresh_exception:'.class_basename($e),
                    'error' => substr($e->getMessage(), 0, 500),
                ];
            }

            if (! (bool) ($snapshotResult['ok'] ?? false)) {
                $failures[] = 'snapshot_refresh_failed';
            }

            $traceCount = collect((array) ($snapshotResult['results'] ?? []))->sum(fn (array $day): int => (int) ($day['n_traces'] ?? 0));
            if ($traceCount === 0) {
                $warnings[] = 'snapshot_refresh_zero_traces';
            }
        }

        $writeCountsBefore = $this->engineWriteCounts();
        $previousEngineVersion = config('atlas.report.engine_version');
        $previousRunMode = config('atlas.report.engine_run_mode');
        config()->set('atlas.report.engine_version', 'next');
        config()->set('atlas.report.engine_run_mode', 'dry_run');

        try {
            $daily = $reports->buildDaily($date, $timezone);
            $multi = $reports->buildMultiWindow($date, $windows, $timezone);
        } catch (Throwable $e) {
            $daily = [];
            $multi = [];
            $failures[] = 'report_build_exception:'.class_basename($e);
            $warnings[] = substr($e->getMessage(), 0, 240);
        } finally {
            config()->set('atlas.report.engine_version', $previousEngineVersion);
            config()->set('atlas.report.engine_run_mode', $previousRunMode);
        }

        $writeCountsAfter = $this->engineWriteCounts();
        $unexpectedWrites = $this->unexpectedWrites($writeCountsBefore, $writeCountsAfter);
        foreach ($unexpectedWrites as $table => $delta) {
            $failures[] = "dry_run_wrote:{$table}:{$delta}";
        }

        $dailySummary = $this->reportSummary($daily);
        $multiSummary = $this->reportSummary($multi);
        foreach (['daily' => $dailySummary, 'multi' => $multiSummary] as $kind => $summary) {
            if (! (bool) data_get($summary, 'engine.present')) {
                $failures[] = "{$kind}_engine_payload_missing";
            }

            $missingFrozen = (array) data_get($summary, 'engine.missing_frozen_keys', []);
            foreach ($missingFrozen as $key) {
                $failures[] = "{$kind}_engine_missing_key:{$key}";
            }

            if ((int) ($summary['traces'] ?? 0) === 0) {
                $warnings[] = "{$kind}_zero_traces";
            }
        }

        $dueRecommendations = DatabaseTableAvailability::has('ai_performance_recommendations')
            ? AiPerformanceRecommendation::query()
                ->where('state', 'applied')
                ->whereNotNull('measurement_due_at')
                ->where('measurement_due_at', '<=', now())
                ->count()
            : null;

        $payload = [
            'ok' => $failures === [] && (! $strict || $warnings === []),
            'strict' => $strict,
            'report_date' => $date->toDateString(),
            'timezone' => $timezone,
            'windows' => $windows,
            'checks' => [
                'tables' => $tables,
                'config' => $this->configChecks(),
                'engine_write_counts_before' => $writeCountsBefore,
                'engine_write_counts_after' => $writeCountsAfter,
                'due_recommendations' => $dueRecommendations,
            ],
            'snapshot_refresh' => $snapshotResult,
            'reports' => [
                'daily' => $dailySummary,
                'multi' => $multiSummary,
            ],
            'warnings' => array_values(array_unique($warnings)),
            'failures' => $failures,
        ];

        return $this->outputPayload($payload, (bool) $payload['ok'] ? self::SUCCESS : self::FAILURE);
    }

    private function outputPayload(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->line('ok='.(YesNo::trueFalse($payload['ok'] ?? false)).' date='.($payload['report_date'] ?? '-').' timezone='.($payload['timezone'] ?? '-'));
        foreach ((array) ($payload['warnings'] ?? []) as $warning) {
            $this->warn('warning='.$warning);
        }
        foreach ((array) ($payload['failures'] ?? []) as $failure) {
            $this->error('failure='.$failure);
        }

        return $exitCode;
    }

    /**
     * @return array<int,string>
     */
    private function requiredTables(): array
    {
        return [
            'ai_traces',
            'ai_trace_metric_summaries',
            'ai_tool_events',
            'ai_metric_daily_snapshots',
            'ai_performance_report_runs',
            'ai_data_confidence_audit',
            'ai_report_findings',
            'ai_performance_recommendations',
            'ai_inbox_items',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function configChecks(): array
    {
        return [
            'snapshot_refresh_enabled' => (bool) config('atlas.ai_metrics.snapshot_refresh_enabled', true),
            'recommendation_measure_enabled' => (bool) config('atlas.report.recommendation_measure_enabled', true),
            'performance_report_enabled' => (bool) config('atlas.ai_metrics.performance_report_enabled', true),
            'performance_report_emit' => (bool) config('atlas.ai_metrics.performance_report_emit', true),
            'performance_report_time' => (string) config('atlas.ai_metrics.performance_report_time', ''),
            'performance_report_grace_minutes' => (int) config('atlas.ai_metrics.performance_report_grace_minutes', 90),
            'performance_report_timezone' => (string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC')),
            'engine_version_configured' => (string) config('atlas.report.engine_version', 'legacy'),
            'engine_run_mode_configured' => (string) config('atlas.report.engine_run_mode', ''),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function engineWriteCounts(): array
    {
        $tables = [
            'ai_performance_report_runs',
            'ai_data_confidence_audit',
            'ai_report_findings',
            'ai_performance_recommendations',
            'ai_inbox_items',
        ];

        return collect($tables)
            ->mapWithKeys(fn (string $table): array => [$table => $this->safeTableCount($table)])
            ->all();
    }

    private function safeTableCount(string $table): int
    {
        try {
            return DatabaseTableAvailability::has($table) ? (int) DB::table($table)->count() : 0;
        } catch (Throwable) {
            return -1;
        }
    }

    /**
     * @param  array<string,int>  $before
     * @param  array<string,int>  $after
     * @return array<string,int>
     */
    private function unexpectedWrites(array $before, array $after): array
    {
        $unexpected = [];
        foreach ($after as $table => $count) {
            $delta = $count - (int) ($before[$table] ?? 0);
            if ($delta !== 0) {
                $unexpected[$table] = $delta;
            }
        }

        return $unexpected;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function reportSummary(array $report): array
    {
        $engine = is_array($report['engine'] ?? null) ? $report['engine'] : null;
        $requiredFrozen = ['decision', 'highlights', 'next_actions', 'risks', 'validation', 'full_text'];

        return [
            'report_type' => $report['report_type'] ?? null,
            'schema_version' => $report['schema_version'] ?? null,
            'status' => $report['status'] ?? null,
            'traces' => (int) data_get($report, 'summary.traces', 0),
            'health_score' => data_get($report, 'health_score'),
            'engine' => [
                'present' => $engine !== null,
                'schema_version' => data_get($engine, 'validation.schema_version'),
                'decision' => data_get($engine, 'decision'),
                'findings_count' => count((array) data_get($engine, 'findings', [])),
                'recommendations_created_count' => (int) data_get($engine, 'recommendations.created_count', 0),
                'missing_frozen_keys' => $engine === null
                    ? $requiredFrozen
                    : array_values(array_filter($requiredFrozen, fn (string $key): bool => ! array_key_exists($key, $engine))),
            ],
        ];
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

    private function date(string $timezone): CarbonImmutable
    {
        $value = $this->option('date');
        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse(trim($value), $timezone)->startOfDay();
        }

        return CarbonImmutable::now($timezone)->subDay()->startOfDay();
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

    private function snapshotDays(): int
    {
        return max(1, min(365, (int) $this->option('snapshot-days')));
    }
}
