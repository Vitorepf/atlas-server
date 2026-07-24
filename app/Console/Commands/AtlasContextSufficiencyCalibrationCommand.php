<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\SufficiencyCalibrationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * MAXC-06 — Read-only ex-post calibration of the sufficiency sensor.
 *
 * Prove that `not_enough_context` predicts lower utility — or kill the block.
 */
final class AtlasContextSufficiencyCalibrationCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:sufficiency-calibration
        {--days=14 : Window in days over which to join delivered pack ledger × ARFL measured events}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Report used-rate/utility split by declared sufficiency (calibrate or open a gap issue).';

    public function handle(SufficiencyCalibrationService $service): int
    {
        $windowDays = max(1, (int) $this->option('days'));
        $report = $service->report($windowDays);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->line(sprintf('schema=%s window=%dd', $report['schema'], $report['window_days']));
        $this->line(sprintf('status=%s reason=%s', $report['status'], $report['status_reason']));
        $this->line(sprintf(
            'measured=%d/%d (denominator_min=%d)',
            (int) $report['measured_count'],
            (int) $report['total_event_count'],
            (int) $report['denominator_min']
        ));
        $this->table(
            ['bucket', 'n', 'mean_post_execution_utility', 'used_rate'],
            [
                [
                    'declared_insufficient',
                    (int) $report['declared_insufficient']['n'],
                    (string) $report['declared_insufficient']['mean_post_execution_utility'],
                    (string) $report['declared_insufficient']['used_rate'],
                ],
                [
                    'declared_covered',
                    (int) $report['declared_covered']['n'],
                    (string) $report['declared_covered']['mean_post_execution_utility'],
                    (string) $report['declared_covered']['used_rate'],
                ],
            ]
        );
        $this->line('death_criterion: '.$report['death_criterion']);

        return self::SUCCESS;
    }
}
