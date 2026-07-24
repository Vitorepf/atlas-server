<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorCloseOutcomeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:close-outcome {--episode=} {--status=succeeded} {--summary=} {--evidence=*} {--metrics-json=} {--json}';

    protected $description = 'Close an AEMOR outcome.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $metricsJson = (string) ($this->option('metrics-json') ?: '');
        $metrics = [];
        if ($metricsJson !== '') {
            $decoded = json_decode($metricsJson, true);
            if (! is_array($decoded)) {
                $this->error('Invalid --metrics-json payload; expected a JSON object.');

                return self::FAILURE;
            }
            $metrics = $decoded;
        }

        $payload = $runtime->closeOutcome([
            'episode_id' => $this->option('episode'),
            'status' => $this->option('status'),
            'summary' => (string) ($this->option('summary') ?: 'AEMOR outcome closed.'),
            'metrics' => $metrics,
            'evidence_refs' => (array) $this->option('evidence'),
        ]);
        $this->jsonLine($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
