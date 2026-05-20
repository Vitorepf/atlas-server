<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;

class AtlasAemorDistillCommand extends Command
{
    protected $signature = 'atlas:aemor:distill {--episode=} {--outcome=} {--claim=} {--evidence=*} {--json}';

    protected $description = 'Distill an AEMOR outcome into learning candidates.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->distill([
            'episode_id' => $this->option('episode'),
            'outcome_id' => $this->option('outcome'),
            'claim' => (string) ($this->option('claim') ?: 'AEMOR learning signal.'),
            'evidence_refs' => (array) $this->option('evidence'),
        ]);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
