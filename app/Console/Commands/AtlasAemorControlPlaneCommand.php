<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;

class AtlasAemorControlPlaneCommand extends Command
{
    protected $signature = 'atlas:aemor:control-plane {--hours=24} {--json}';

    protected $description = 'Emit AEMOR control-plane JSON.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->controlPlane((int) $this->option('hours'));
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
