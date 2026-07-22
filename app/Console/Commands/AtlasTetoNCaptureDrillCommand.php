<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AtlasNCaptureDrillService;
use Illuminate\Console\Command;

final class AtlasTetoNCaptureDrillCommand extends Command
{
    protected $signature = 'atlas:teto:n-capture-drill
        {--days= : Reader window in days (defaults to drill cadence 180)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'TETO-01 N-Capture Drill reader: publish the three drill times + denominators per installed non-routed engine.';

    public function handle(AtlasNCaptureDrillService $service): int
    {
        $daysOpt = $this->option('days');
        $days = is_numeric($daysOpt) ? (int) $daysOpt : null;

        $payload = $service->report($days);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '[atlas:teto:n-capture-drill] %s drills_in_window=%d admitted=%d refused=%d window_days=%d',
            (string) ($payload['status'] ?? 'unknown'),
            (int) data_get($payload, 'aggregate.drills_in_window', 0),
            (int) data_get($payload, 'aggregate.admitted_count', 0),
            (int) data_get($payload, 'aggregate.refused_count', 0),
            (int) ($payload['window_days'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
