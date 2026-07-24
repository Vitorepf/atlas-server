<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorReplayCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:replay {--episode=} {--json}';

    protected $description = 'Emit an AEMOR replay manifest.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->replayManifest((string) ($this->option('episode') ?: ''));
        $this->jsonLine($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
