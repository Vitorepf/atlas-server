<?php

namespace App\Console\Commands;

use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use Illuminate\Console\Command;

class AtlasIntelligenceFactoryControlPlaneCommand extends Command
{
    protected $signature = 'atlas:intelligence-factory:control-plane
        {--hours=24 : Lookback window}
        {--json : Emit JSON}';

    protected $description = 'Show Atlas Intelligence Factory OS control plane.';

    public function handle(AtlasIntelligenceFactoryRuntimeService $runtime): int
    {
        $payload = $runtime->controlPlane((int) $this->option('hours'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Intelligence Factory', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Open gaps', (string) data_get($payload, 'summary.open_gaps', 0));
            $this->components->twoColumnDetail('Certified capabilities', (string) data_get($payload, 'summary.certified_capabilities', 0));
        }

        return self::SUCCESS;
    }
}
