<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasAiCounterfactualLiftCommand extends Command
{
    protected $signature = 'atlas:ai:counterfactual-lift {--json : Emit JSON}';

    protected $description = 'MULTJ-03 paired counterfactual lift read-only series.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->multj03CounterfactualLift(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
