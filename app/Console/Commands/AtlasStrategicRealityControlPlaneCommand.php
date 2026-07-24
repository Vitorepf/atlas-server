<?php

namespace App\Console\Commands;

use App\Services\Ai\StrategicReality\AtlasStrategicRealityRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasStrategicRealityControlPlaneCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:strategic-reality:control-plane
        {--hours=24 : Lookback window}
        {--json : Emit JSON}';

    protected $description = 'Show ASRE aggregate control plane. Read-only and sanitized.';

    public function handle(AtlasStrategicRealityRuntimeService $runtime): int
    {
        $payload = $runtime->controlPlane((int) $this->option('hours'));

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('ASRE control plane', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Decision count', (string) data_get($payload, 'summary.strategic_decisions_total', 0));
            $this->components->twoColumnDetail('Critical risks', (string) data_get($payload, 'summary.critical_risks', 0));
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
