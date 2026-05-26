<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use Illuminate\Console\Command;

class AtlasCrossDomainTopologyCommand extends Command
{
    protected $signature = 'atlas:cross-domain:topology {--json : JSON output}';

    protected $description = 'Atlas Cross-Domain · view mesh topology (allowed edges per privacy class).';

    public function handle(AtlasCrossDomainMeshService $svc): int
    {
        $t = $svc->topology();
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => true, 'action' => 'topology', 'topology' => $t], JSON_PRETTY_PRINT));

            return 0;
        }
        $this->line('[atlas:cross-domain:topology]');
        $this->line('domains='.count($t['domains']).' edges='.count($t['edges_allowed']));
        $this->line('hash='.$t['topology_hash']);

        return 0;
    }
}
