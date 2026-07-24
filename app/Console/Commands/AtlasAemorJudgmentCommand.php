<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorJudgmentCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:judgment
        {--episode= : AEMOR episode id}
        {--json : Emit JSON}';

    protected $description = 'Run AEMOR Judgment & Learning Guard for an episode.';

    public function handle(AtlasAemorJudgmentService $judgment): int
    {
        $payload = $judgment->judge((string) ($this->option('episode') ?: ''));

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('AEMOR judgment', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Judgment hash', (string) ($payload['judgment_hash'] ?? 'missing'));
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
