<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AcosMax\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasFlywheelLoopsCommand extends Command
{
    protected $signature = 'atlas:flywheel:loops {--json : Emit JSON}';

    protected $description = 'MULTX-01 read-only flywheel loop assembler.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->multx01FlywheelLoops(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
