<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorDistillCommand extends Command
{
    use EmitsCanonicalJson;

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
        $this->jsonLine($payload);

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
