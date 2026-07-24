<?php

namespace App\Console\Commands;

use App\Services\Ai\Caching\AtlasCostCalibrationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Report the percentile distribution of observed provider pre-cost so the operator
 * sets the cost hard-gate from data, not a guess (the Calibrate stage of the
 * staged-rollout ratchet: observe -> calibrate -> enforce).
 */
class AtlasCostCalibrateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:cost-calibrate
        {--log= : Telemetry JSONL path (default: storage/app/atlas-cost-telemetry.jsonl)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Calibrate the provider cost ceiling from observed telemetry (percentiles + suggested hard-gate).';

    public function handle(AtlasCostCalibrationService $calibration): int
    {
        $log = (string) ($this->option('log') ?: storage_path('app/atlas-cost-telemetry.jsonl'));
        $report = $calibration->calibrate($log);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        if (! $report['available']) {
            $this->warn('No cost telemetry at '.$log.' — enable atlas.ai.cost_sentinel.enabled and run some LIVE swarm dispatches first.');

            return self::SUCCESS;
        }

        $this->info('Cost calibration — '.$report['samples'].' samples ('.$report['records'].' records):');
        $this->table(['metric', 'units'], [
            ['p50', (string) $report['p50']],
            ['p90', (string) $report['p90']],
            ['p99', (string) $report['p99']],
            ['max', (string) $report['max']],
            ['soft_warn_rate', (string) $report['soft_warn_rate']],
            ['suggested hard_gate (p99)', (string) $report['suggested_hard_gate_units']],
        ]);
        $this->line('Set `atlas.ai.cost_sentinel.hard_gate_units` to the suggested value (or your chosen percentile) to flip from observe to enforce.');

        return self::SUCCESS;
    }
}
