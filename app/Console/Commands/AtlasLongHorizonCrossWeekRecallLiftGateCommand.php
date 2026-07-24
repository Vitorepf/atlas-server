<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\LongHorizonCrossWeekRecallLiftGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Support\YesNo;

final class AtlasLongHorizonCrossWeekRecallLiftGateCommand extends Command
{
    protected $signature = 'atlas:long-horizon:cross-week-recall-lift-gate
        {--scope-type= : Long-horizon scope type}
        {--scope-id= : Long-horizon scope id}
        {--min-recall-age-days= : Minimum real calendar age (days) of recalled memory}
        {--recent-window-days= : Window (days) that counts as a new task}
        {--min-calendar-span-days= : Minimum span between oldest and newest pack}
        {--min-recall-events= : Minimum cross-week recall events}
        {--min-new-tasks= : Minimum new tasks measured}
        {--min-recall-lift= : Minimum certification-rate lift of the recall arm}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless cross-week recall lift is proven}
        {--json : Print canonical JSON}';

    protected $description = 'L6-12 keystone: prove cross-week continuity with a measured old-memory recall lift on real elapsed time.';

    public function handle(LongHorizonCrossWeekRecallLiftGateService $gate): int
    {
        $payload = $gate->evaluate(array_filter([
            'scope_type' => $this->nonEmptyOption('scope-type'),
            'scope_id' => $this->nonEmptyOption('scope-id'),
            'min_recall_age_days' => $this->intOption('min-recall-age-days'),
            'recent_window_days' => $this->intOption('recent-window-days'),
            'min_calendar_span_days' => $this->intOption('min-calendar-span-days'),
            'min_recall_events' => $this->intOption('min-recall-events'),
            'min_new_tasks' => $this->intOption('min-new-tasks'),
            'min_recall_lift' => $this->floatOption('min-recall-lift'),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

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

        $this->components->twoColumnDetail('Cross-week recall lift', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) YesNo::format($payload['certified'] ?? false));
        $this->components->twoColumnDetail('Calendar span (days)', (string) data_get($payload, 'measurement.calendar_span_days', 'n/a'));
        $this->components->twoColumnDetail('Max recall age (days)', (string) data_get($payload, 'measurement.max_recall_age_days', 'n/a'));
        $this->components->twoColumnDetail('Recall lift', (string) (data_get($payload, 'measurement.ab.recall_lift') ?? 'n/a'));
        $blockers = (array) ($payload['blockers'] ?? []);
        $this->components->twoColumnDetail('Blockers', $blockers === [] ? 'none' : implode(',', $blockers));

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config(
                'atlas.long_horizon.cross_week_recall_lift_gate.receipt_path',
                storage_path('app/atlas/evidence/long-horizon-cross-week-recall-lift-gate.json'),
            )
            : '';
    }

    private function nonEmptyOption(string $key): ?string
    {
        $value = trim((string) ($this->option($key) ?: ''));

        return $value !== '' ? $value : null;
    }

    private function intOption(string $key): ?int
    {
        $value = trim((string) ($this->option($key) ?: ''));

        return $value !== '' ? (int) $value : null;
    }

    private function floatOption(string $key): ?float
    {
        $value = trim((string) ($this->option($key) ?: ''));

        return $value !== '' ? (float) $value : null;
    }
}
