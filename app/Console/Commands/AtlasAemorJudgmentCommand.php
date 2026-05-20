<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use Illuminate\Console\Command;

class AtlasAemorJudgmentCommand extends Command
{
    protected $signature = 'atlas:aemor:judgment
        {--episode= : AEMOR episode id}
        {--json : Emit JSON}';

    protected $description = 'Run AEMOR Judgment & Learning Guard for an episode.';

    public function handle(AtlasAemorJudgmentService $judgment): int
    {
        $payload = $judgment->judge((string) ($this->option('episode') ?: ''));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('AEMOR judgment', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Judgment hash', (string) ($payload['judgment_hash'] ?? 'missing'));
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
