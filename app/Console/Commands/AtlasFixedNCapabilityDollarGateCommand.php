<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\FixedNCapabilityDollarSeriesGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AtlasFixedNCapabilityDollarGateCommand extends Command
{
    protected $signature = 'atlas:compounding:fixed-n-capability-dollar-gate
        {--fixture=live : live, mature, short-window, flat-trend or missing-cost}
        {--series= : Override fixed-N capability-per-dollar JSONL path}
        {--date= : Snapshot date YYYY-MM-DD}
        {--write-snapshot : Append or replace today in the fixed-N series}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless the fixed-N capability-per-dollar gate certifies}
        {--json : Print canonical JSON}';

    protected $description = 'L6-13: fixed-N capability-per-dollar series gate with measured cost and positive trend.';

    public function handle(FixedNCapabilityDollarSeriesGateService $gate): int
    {
        $series = trim((string) ($this->option('series') ?: ''));
        $date = trim((string) ($this->option('date') ?: ''));

        $payload = $gate->evaluate(array_filter([
            'fixture' => trim((string) $this->option('fixture')),
            'series_path' => $series !== '' ? $series : null,
            'date' => $date !== '' ? $date : null,
            'write_snapshot' => (bool) $this->option('write-snapshot'),
        ], static fn (mixed $value): bool => $value !== null));

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            File::ensureDirectoryExists(dirname($receiptPath));
            File::put($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['receipt_path'] = $receiptPath;
        }

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Fixed-N capability / dollar', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) ($payload['certified'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Window', (string) data_get($payload, 'assessment.calendar_span_days', 0).' days');
        $this->components->twoColumnDetail('Measured cost days', (string) data_get($payload, 'assessment.measured_cost_day_count', 0));
        $this->components->twoColumnDetail('Trend', (string) data_get($payload, 'assessment.trend_direction', 'missing'));

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.compounding.fixed_n_capability_dollar_gate.receipt_path', storage_path('app/atlas/evidence/fixed-n-capability-dollar-gate.json'))
            : '';
    }
}
