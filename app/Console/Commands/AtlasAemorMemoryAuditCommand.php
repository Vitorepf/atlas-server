<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAemorMemoryAuditCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aemor:memory-audit {--json}';

    protected $description = 'Audit AEMOR memory candidates.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->memoryAudit();
        $this->jsonLine($payload);

        return self::SUCCESS;
    }
}
