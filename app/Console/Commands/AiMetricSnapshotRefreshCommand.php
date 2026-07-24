<?php

namespace App\Console\Commands;

use App\Services\Ai\Telemetry\AiMetricDailySnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AiMetricSnapshotRefreshCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:metrics:snapshot-refresh
        {--date= : Local end date to refresh. Defaults to yesterday in the configured timezone}
        {--days=1 : Number of local days to refresh ending at --date}
        {--timezone= : Snapshot timezone. Defaults to atlas.ai_metrics.performance_report_timezone}
        {--json : Print machine-readable JSON}';

    protected $description = 'Materialize daily Atlas metric snapshots used by the statistical report engine.';

    public function handle(AiMetricDailySnapshotService $snapshots): int
    {
        $timezone = $this->timezone();
        $date = $this->date($timezone);
        $days = $this->days();
        $result = $snapshots->refreshRange($date, $days, $timezone);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        foreach ((array) ($result['results'] ?? []) as $day) {
            $status = (bool) ($day['ok'] ?? false) ? 'ok' : 'failed';
            $this->line(sprintf(
                '%s status=%s traces=%d snapshots=%d aggregator=%s',
                (string) ($day['snapshot_date'] ?? '-'),
                $status,
                (int) ($day['n_traces'] ?? 0),
                (int) ($day['snapshots'] ?? 0),
                (string) ($day['aggregator_version'] ?? '-'),
            ));
        }

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
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

    private function days(): int
    {
        return max(1, min(365, (int) $this->option('days')));
    }
}
