<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorObserveCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:observe {--episode=} {--event-type=manual_observation} {--stage=} {--status=observed} {--summary=} {--evidence=*} {--json}';

    protected $description = 'Append an AEMOR execution event.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->observe([
            'episode_id' => $this->option('episode'),
            'event_type' => $this->option('event-type'),
            'stage' => $this->option('stage'),
            'status' => $this->option('status'),
            'payload' => ['summary' => $this->option('summary')],
            'evidence_refs' => (array) $this->option('evidence'),
        ]);
        $this->jsonLine($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
