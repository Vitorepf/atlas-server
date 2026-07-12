<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryLiveManifestService;
use Illuminate\Console\Command;

final class AtlasEngineeringQualityFoundryManifestsCommand extends Command
{
    protected $signature = 'atlas:engineering:quality-foundry-manifests
        {--json : Machine-readable JSON}';

    protected $description = 'Execute mode-scoped evidence tests and emit read-only Quality Foundry live manifests.';

    public function handle(QualityFoundryLiveManifestService $service): int
    {
        $payload = $service->build();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('[atlas:engineering:quality-foundry-manifests] status='.$payload['status']);
        $this->line('  ready_modes='.$payload['summary']['ready_modes'].'/'.$payload['summary']['required_modes']);
        $this->line('  completion_allowed='.($payload['completion_allowed'] ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
