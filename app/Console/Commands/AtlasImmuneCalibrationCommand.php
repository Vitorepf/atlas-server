<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\ImmuneCalibrationService;
use Illuminate\Console\Command;

final class AtlasImmuneCalibrationCommand extends Command
{
    protected $signature = 'atlas:immune:calibration
        {--days=90 : Lookback window for immune_verdict_ledger rows}
        {--json : Emit machine-readable JSON}';

    protected $description = 'MAXI-03 read-only immune gate calibration report with FP/FN bands.';

    public function handle(ImmuneCalibrationService $calibration): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $payload = $calibration->report($days);
        $encoded = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ((bool) $this->option('json')) {
            $this->line($encoded);

            return self::SUCCESS;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }
}
