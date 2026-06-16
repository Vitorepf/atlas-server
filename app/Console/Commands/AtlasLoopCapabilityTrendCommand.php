<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCapabilityTrendService;
use Illuminate\Console\Command;

/**
 * ACDE lever D1 — surface the per-delivery capability trend (the only instrument that answers "is the curve
 * bending"). SELF-trend over the loop's OWN merged-to-main deliveries — never a head-to-head (Rivals is dead).
 */
class AtlasLoopCapabilityTrendCommand extends Command
{
    protected $signature = 'atlas:loop:capability-trend
        {--window=24 : hours per bucket}
        {--buckets=7 : number of rolling buckets (oldest..newest)}
        {--json : canonical JSON output}';

    protected $description = 'Report-only: the per-delivery clean-delivery-rate trend + slope (self-trend, never a head-to-head).';

    public function handle(AtlasLoopCapabilityTrendService $service): int
    {
        $trend = $service->trend((int) $this->option('window'), (int) $this->option('buckets'));

        if ($this->option('json')) {
            $this->line((string) json_encode($trend, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if (($trend['enabled'] ?? false) !== true) {
            $this->warn('capability_trend is OFF (set ATLAS_LOOP_CAPABILITY_TREND_ENABLED=true to arm).');

            return self::SUCCESS;
        }

        $this->info('Per-delivery capability trend (SELF-trend over merged deliveries — NOT a head-to-head)');
        foreach ((array) $trend['buckets'] as $b) {
            $this->line('bucket '.$b['index'].' (oldest..newest): clean '.$b['clean'].'/'.$b['total'].'  rate '.$b['rate'].'  wilson_lb '.$b['wilson_lower']);
        }
        $this->line('');
        $this->{($trend['bending'] ?? false) ? 'info' : 'warn'}('slope = '.$trend['slope'].'  =>  '.(($trend['bending'] ?? false) ? 'BENDING UP' : 'flat / down').'  ('.$trend['samples'].' deliveries)');

        return self::SUCCESS;
    }
}
