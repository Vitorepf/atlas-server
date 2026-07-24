<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorEpisodeOpenCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:episode-open {--objective=} {--workspace=} {--domain=} {--flow=} {--provider=} {--evidence=*} {--json}';

    protected $description = 'Open an AEMOR execution episode.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->openEpisode([
            'objective' => (string) ($this->option('objective') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: base_path()),
            'domain' => $this->option('domain'),
            'flow_id' => $this->option('flow'),
            'provider' => $this->option('provider'),
            'evidence_refs' => (array) $this->option('evidence'),
        ]);

        return $this->emit($payload);
    }

    private function emit(array $payload): int
    {
        $this->jsonLine($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
