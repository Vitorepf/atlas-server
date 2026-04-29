<?php

namespace App\Console\Commands;

use App\Services\Semantic\CurationProposalService;
use Illuminate\Console\Command;

class SemanticProposeCommand extends Command
{
    protected $signature = 'atlas:semantic:propose {--since= : Inclusive captured_at datetime}';

    protected $description = 'Create semantic memory curation proposals from recent captures.';

    public function handle(CurationProposalService $service): int
    {
        $stats = $service->scanRecentCaptures($this->option('since') ? (string) $this->option('since') : null);

        $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
