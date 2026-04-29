<?php

namespace App\Console\Commands;

use App\Services\Digital\RizeApiIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RizeSyncCommand extends Command
{
    protected $signature = 'atlas:rize:sync
        {--from= : Inclusive start datetime for the Rize import window}
        {--to= : Exclusive end datetime for the Rize import window}
        {--days= : Lookback days when --from is omitted}
        {--page-size= : GraphQL page size}
        {--dry-run : Fetch and normalize without storing records}';

    protected $description = 'Import Rize digital activity sessions through the Rize GraphQL API.';

    public function handle(RizeApiIngestor $ingestor): int
    {
        $timezone = (string) config('services.rize.timezone', config('app.timezone', 'UTC'));
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), $timezone)
            : CarbonImmutable::now($timezone);
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), $timezone)
            : $to->subDays($this->lookbackDays())->startOfDay();
        $pageSize = max(1, min(500, (int) ($this->option('page-size') ?: config('services.rize.sync_page_size', 100))));

        $this->info('Importing Rize sessions from '.$from->toIso8601String().' to '.$to->toIso8601String().'.');

        $stats = $ingestor->sync(
            from: $from,
            to: $to,
            pageSize: $pageSize,
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function lookbackDays(): int
    {
        $days = $this->option('days');
        if ($days === null || $days === '') {
            $days = config('services.rize.sync_lookback_days', 2);
        }

        return max(1, min(3650, (int) $days));
    }
}
