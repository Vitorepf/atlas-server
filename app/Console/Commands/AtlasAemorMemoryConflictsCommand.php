<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorMemoryConflictsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:memory-conflicts
        {--scope-type= : Scope type}
        {--scope-id= : Scope id}
        {--json : Emit JSON}';

    protected $description = 'Audit AEMOR memory candidate conflicts.';

    public function handle(AtlasAemorJudgmentService $judgment): int
    {
        $payload = $judgment->memoryConflicts(
            is_string($this->option('scope-type')) ? (string) $this->option('scope-type') : null,
            is_string($this->option('scope-id')) ? (string) $this->option('scope-id') : null,
        );

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('AEMOR memory conflicts', (string) ($payload['status'] ?? 'unknown'));
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
