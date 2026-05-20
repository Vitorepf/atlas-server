<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Illuminate\Console\Command;

class AtlasAemorMemoryAuditCommand extends Command
{
    protected $signature = 'atlas:aemor:memory-audit {--json}';

    protected $description = 'Audit AEMOR memory candidates.';

    public function handle(AtlasAemorRuntimeService $runtime): int
    {
        $payload = $runtime->memoryAudit();
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
