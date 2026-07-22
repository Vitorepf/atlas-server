<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasMissionE2eRateCommand extends Command
{
    protected $signature = 'atlas:mission:e2e
        {--days= : Optional lookback window in days}
        {--json : Emit JSON}';

    protected $description = 'TETO-02 mission end-to-end rate reader.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $days = $this->option('days');
        $payload = $service->teto02MissionE2e(is_numeric($days) ? (int) $days : null);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
