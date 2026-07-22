<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasBrainPredictedImpactCommand extends Command
{
    protected $signature = 'atlas:brain:predicted-impact {--json : Emit JSON}';

    protected $description = 'MULTN17-04 predicted-impact calibration freeze reader.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->multn1704PredictedImpact(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
