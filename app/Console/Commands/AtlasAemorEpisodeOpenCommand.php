<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;

class AtlasAemorEpisodeOpenCommand extends Command
{
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
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
