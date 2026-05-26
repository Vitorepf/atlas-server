<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Illuminate\Console\Command;

class AtlasAurgTemporalVerifyCommand extends Command
{
    protected $signature = 'atlas:aurg:temporal:verify {--json : JSON output}';

    protected $description = 'AURG · verify temporal chain integrity (walks ticks, reports break if any).';

    public function handle(AtlasUnifiedRealityGraphTemporalService $svc): int
    {
        $report = $svc->verifyChain();
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $report['chain_intact'], 'action' => 'verify-chain', 'report' => $report], JSON_PRETTY_PRINT));

            return $report['chain_intact'] ? 0 : 3;
        }
        $this->line('[atlas:aurg:temporal:verify] chain_intact='.($report['chain_intact'] ? 'yes' : 'no'));
        $this->line('ticks_walked='.$report['ticks_walked']);
        if (! $report['chain_intact']) {
            $this->line('break_at='.$report['chain_break_at']);
        }

        return $report['chain_intact'] ? 0 : 3;
    }
}
