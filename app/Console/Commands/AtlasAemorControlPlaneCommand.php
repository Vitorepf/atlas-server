<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorControlPlaneCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:control-plane {--hours=24} {--json}';

    protected $description = 'Emit AEMOR control-plane JSON.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->controlPlane((int) $this->option('hours'));
        $this->jsonLine($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
