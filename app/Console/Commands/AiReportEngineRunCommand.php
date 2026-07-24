<?php

namespace App\Console\Commands;

use App\Models\AiPerformanceReportRun;
use App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\YesNo;

class AiReportEngineRunCommand extends Command
{
    protected $signature = 'atlas:ai:engine:run
        {--date= : Local report date. Defaults to yesterday in the configured timezone}
        {--timezone= : Report timezone. Defaults to atlas.ai_metrics.performance_report_timezone}
        {--type=daily : daily, multi or both}
        {--windows=3,7,15,30 : Comma-separated windows for multi}
        {--engine-version=shadow : shadow or next}
        {--run-mode= : live, shadow or dry_run. Defaults from engine-version}
        {--recompute : Recompute metric summaries for the report and baseline windows first}
        {--emit : Emit the resulting report to mobile inbox}
        {--replay= : Return stored output snapshot for a previous ai_performance_report_runs id}
        {--user=vitor : Inbox user id when --emit is used}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run, replay or inspect the Atlas report engine for an explicit window.';

    public function handle(AiTelemetryPerformanceReportService $reports, AiTraceMetricAggregator $aggregator): int
    {
        $replay = $this->option('replay');
        if (is_string($replay) && trim($replay) !== '') {
            return $this->replay(trim($replay));
        }

        if (! DatabaseTableAvailability::has('ai_trace_metric_summaries')) {
            $this->error('ai_trace_metric_summaries table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $timezone = $this->timezone();
        $date = $this->date($timezone);
        $type = $this->type();
        $windows = $this->windows();
        $engineVersion = $this->engineVersion();
        $runMode = $this->runMode($engineVersion);
        $previousEngineVersion = config('atlas.report.engine_version');
        $previousRunMode = config('atlas.report.engine_run_mode');

        try {
            config()->set('atlas.report.engine_version', $engineVersion);
            config()->set('atlas.report.engine_run_mode', $runMode);

            $recomputed = null;
            if ((bool) $this->option('recompute')) {
                $until = $date->startOfDay()->addDay();
                $lookback = AiTelemetryPerformanceReportCommand::recomputeLookbackDays($type, $windows);
                $recomputed = $aggregator->recomputeWindow($until->subDays($lookback), $until);
            }

            $built = [];
            $emissions = [];

            if (in_array($type, ['daily', 'both'], true)) {
                $daily = $reports->buildDaily($date, $timezone);
                $built[] = $daily;
                if ((bool) $this->option('emit')) {
                    $emissions[] = $reports->emit($daily, $this->userId(), false);
                }
            }

            if (in_array($type, ['multi', 'both'], true)) {
                $multi = $reports->buildMultiWindow($date, $windows, $timezone);
                $built[] = $multi;
                if ((bool) $this->option('emit')) {
                    $emissions[] = $reports->emit($multi, $this->userId(), false);
                }
            }

            $payload = [
                'ok' => true,
                'mode' => 'run',
                'timezone' => $timezone,
                'report_date' => $date->toDateString(),
                'type' => $type,
                'engine_version' => $engineVersion,
                'run_mode' => $runMode,
                'recomputed' => $recomputed,
                'reports' => $built,
                'emissions' => $emissions,
            ];

            return $this->outputPayload($payload);
        } finally {
            config()->set('atlas.report.engine_version', $previousEngineVersion);
            config()->set('atlas.report.engine_run_mode', $previousRunMode);
        }
    }

    private function replay(string $runId): int
    {
        if (! DatabaseTableAvailability::has('ai_performance_report_runs')) {
            $this->error('ai_performance_report_runs table is missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $run = AiPerformanceReportRun::query()->find($runId);
        if (! $run instanceof AiPerformanceReportRun) {
            $this->error('Report run not found.');

            return self::FAILURE;
        }

        $output = (array) ($run->output_snapshot ?? []);
        $hash = $output === [] ? null : $this->hash($output);
        $payload = [
            'ok' => $output !== [],
            'mode' => 'replay',
            'run' => [
                'id' => $run->id,
                'report_date' => $run->report_date?->toDateString(),
                'report_type' => $run->report_type,
                'engine_version' => $run->engine_version,
                'run_mode' => $run->run_mode,
                'status' => $run->status,
                'input_hash' => $run->input_hash,
                'output_hash' => $run->output_hash,
                'output_hash_verified' => $run->output_hash !== null && hash_equals((string) $run->output_hash, (string) $hash),
            ],
            'payload' => $output,
        ];

        return $this->outputPayload($payload);
    }

    private function outputPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->line('ok='.(YesNo::trueFalse($payload['ok'] ?? false)).' mode='.($payload['mode'] ?? '-'));

        return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
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

    private function runMode(string $engineVersion): string
    {
        $value = $this->option('run-mode');
        if (is_string($value) && in_array(trim($value), ['live', 'shadow', 'dry_run'], true)) {
            return trim($value);
        }

        return $engineVersion === 'shadow' ? 'shadow' : 'live';
    }

    private function userId(): string
    {
        $value = $this->option('user');

        return is_string($value) && trim($value) !== '' ? trim($value) : 'vitor';
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $entry): mixed => $this->sortRecursive($entry), $value);
        }

        ksort($value);

        return array_map(fn (mixed $entry): mixed => $this->sortRecursive($entry), $value);
    }
}
