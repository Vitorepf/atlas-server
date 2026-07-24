<?php

namespace App\Console\Commands;

use App\Services\Semantic\CurationProposalService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class SemanticProposeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:semantic:propose {--since= : Inclusive captured_at datetime}';

    protected $description = 'Create semantic memory curation proposals from recent captures.';

    public function handle(CurationProposalService $service): int
    {
        $stats = $service->scanRecentCaptures($this->option('since') ? (string) $this->option('since') : null);

        $this->line($this->encode($stats));

        return self::SUCCESS;
    }
}
