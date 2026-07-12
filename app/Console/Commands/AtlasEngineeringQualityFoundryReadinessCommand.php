<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryReadinessManifest;
use Illuminate\Console\Command;

final class AtlasEngineeringQualityFoundryReadinessCommand extends Command
{
    protected $signature = 'atlas:engineering:quality-foundry-readiness
        {--json : Machine-readable JSON}';

    protected $description = 'Read-only checklist manifest for the Atlas Quality Foundry master plan.';

    public function handle(QualityFoundryReadinessManifest $manifest): int
    {
        $payload = $manifest->build();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('[atlas:engineering:quality-foundry-readiness] status='.$payload['status']);
        $this->line('  open_items='.$payload['summary']['open_items']);
        $this->line('  completion_allowed='.($payload['completion_allowed'] ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
