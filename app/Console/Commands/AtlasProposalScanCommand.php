<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\AutoImprovementProposalScanner;
use Illuminate\Console\Command;

class AtlasProposalScanCommand extends Command
{
    protected $signature = 'atlas:proposal:scan
        {--workspace= : Workspace/repo que sera analisado}
        {--emit : Cria proposals no Inbox; sem isso roda dry-run}
        {--limit=3 : Maximo de proposals por execucao}
        {--json : Mantem saida em JSON para automacao}';

    protected $description = 'Varre repo/workspace e transforma achados de auto-improvement em proposals seguras no Mobile Inbox.';

    public function handle(AutoImprovementProposalScanner $scanner): int
    {
        $workspace = (string) ($this->option('workspace')
            ?: config('atlas.mobile.proposal_scan.workspace')
            ?: config('atlas.ai.workdir')
            ?: base_path());
        $limit = max(1, (int) $this->option('limit'));
        $emit = (bool) $this->option('emit');

        $result = $scanner->scan($workspace, $emit, $limit);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
