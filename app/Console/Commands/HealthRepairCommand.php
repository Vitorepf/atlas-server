<?php

namespace App\Console\Commands;

use App\Models\HealthSnapshot;
use App\Models\PassiveSignal;
use Illuminate\Console\Command;
use App\Support\YesNo;

class HealthRepairCommand extends Command
{
    protected $signature = 'atlas:health:repair
        {--apply : Apply repair changes. Without this flag the command only reports.}
        {--days=60 : Lookback window for snapshot freshness checks.}';

    protected $description = 'Audit and repair legacy HealthKit data that can pollute health metrics.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $days = max(1, (int) $this->option('days'));
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $legacyHeartRate = PassiveSignal::query()
            ->where('source', 'healthkit')
            ->where('signal_type', 'heart_rate_bpm')
            ->count();

        $legacyVo2Units = PassiveSignal::query()
            ->where('source', 'healthkit')
            ->where('signal_type', 'vo2max')
            ->whereIn('unit', ['mL/min·kg', 'ml/kg/min', 'mL/kg/min'])
            ->count();

        $latestSnapshot = HealthSnapshot::query()
            ->orderByDesc('snapshot_date')
            ->orderByDesc('computed_at')
            ->first();

        $snapshotWindowCount = HealthSnapshot::query()
            ->whereDate('snapshot_date', '>=', now()->subDays($days - 1)->toDateString())
            ->count();

        $this->info('Health repair audit');
        $this->line('apply: '.(YesNo::format($apply)));
        $this->line("legacy heart_rate_bpm active rows: {$legacyHeartRate}");
        $this->line("legacy vo2max unit rows: {$legacyVo2Units}");
        $this->line("snapshots in {$days}d window: {$snapshotWindowCount}");

        if ($latestSnapshot) {
            $this->line("latest snapshot: {$latestSnapshot->snapshot_date?->toDateString()} computed {$latestSnapshot->computed_at?->toIso8601String()}");
        } else {
            $this->warn('latest snapshot: missing');
        }

        if (! $latestSnapshot || ! in_array($latestSnapshot->snapshot_date?->toDateString(), [$today, $yesterday], true)) {
            $this->warn('Health snapshot watchdog: latest snapshot is stale. Open/sync the app to rebuild daily health snapshots.');
        }

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to clean legacy rows.');

            return self::SUCCESS;
        }

        $deletedHeartRate = PassiveSignal::query()
            ->where('source', 'healthkit')
            ->where('signal_type', 'heart_rate_bpm')
            ->delete();

        $normalizedVo2 = PassiveSignal::query()
            ->where('source', 'healthkit')
            ->where('signal_type', 'vo2max')
            ->whereIn('unit', ['mL/min·kg', 'ml/kg/min', 'mL/kg/min'])
            ->update(['unit' => 'ml/(kg*min)']);

        $this->info("soft-deleted legacy heart_rate_bpm rows: {$deletedHeartRate}");
        $this->info("normalized vo2max unit rows: {$normalizedVo2}");

        return self::SUCCESS;
    }
}
