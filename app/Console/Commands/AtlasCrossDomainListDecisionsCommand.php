<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use Illuminate\Console\Command;

class AtlasCrossDomainListDecisionsCommand extends Command
{
    protected $signature = 'atlas:cross-domain:list-decisions {--limit=100} {--json : JSON output}';

    protected $description = 'Atlas Cross-Domain · list recent ARPTL veto decisions (read-only).';

    public function handle(AtlasCrossDomainMeshService $svc): int
    {
        $list = $svc->listDecisions((int) ($this->option('limit') ?? 100));
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => true, 'action' => 'list-decisions', 'count' => count($list), 'decisions' => $list], JSON_PRETTY_PRINT));

            return 0;
        }
        $this->line('[atlas:cross-domain:list-decisions] count='.count($list));

        return 0;
    }
}
