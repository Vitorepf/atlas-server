<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Illuminate\Console\Command;

class AtlasSelfConstructionListProposalsCommand extends Command
{
    protected $signature = 'atlas:self-construction:list-proposals {--json : JSON output}';

    protected $description = 'Atlas Self-Construction · list subsystem builder proposals and approval receipts.';

    public function handle(AtlasSelfConstructionSubsystemBuilderService $service): int
    {
        $proposals = $service->listProposals();
        $approvals = $service->listApprovals();

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'action' => 'list-proposals',
                'proposal_count' => count($proposals),
                'approval_count' => count($approvals),
                'proposals' => $proposals,
                'approvals' => $approvals,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->line('[atlas:self-construction:list-proposals]');
        $this->line('proposal_count='.count($proposals));
        $this->line('approval_count='.count($approvals));

        return 0;
    }
}
