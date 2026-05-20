<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;

class AtlasAemorReplayCommand extends Command
{
    protected $signature = 'atlas:aemor:replay {--episode=} {--json}';

    protected $description = 'Emit an AEMOR replay manifest.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->replayManifest((string) ($this->option('episode') ?: ''));
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
