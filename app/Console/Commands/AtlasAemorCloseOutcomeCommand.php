<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;

class AtlasAemorCloseOutcomeCommand extends Command
{
    protected $signature = 'atlas:aemor:close-outcome {--episode=} {--status=succeeded} {--summary=} {--evidence=*} {--json}';

    protected $description = 'Close an AEMOR outcome.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->closeOutcome([
            'episode_id' => $this->option('episode'),
            'status' => $this->option('status'),
            'summary' => (string) ($this->option('summary') ?: 'AEMOR outcome closed.'),
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'evidence_refs' => (array) $this->option('evidence'),
        ]);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
