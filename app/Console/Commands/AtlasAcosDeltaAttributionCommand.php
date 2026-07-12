<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AcosMax\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasAcosDeltaAttributionCommand extends Command
{
    protected $signature = 'atlas:acos:delta-attribution {--json : Emit JSON}';

    protected $description = 'MAXL-06 report-only attributed delta reader.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->maxl06DeltaAttribution(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
