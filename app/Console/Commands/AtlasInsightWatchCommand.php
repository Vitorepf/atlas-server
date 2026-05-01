<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\InsightWatcherService;
use Illuminate\Console\Command;

class AtlasInsightWatchCommand extends Command
{
    protected $signature = 'atlas:insight:watch
        {--dry-run : Calcula candidatos sem criar inbox item}
        {--json : Mantem saida em JSON para automacao}';

    protected $description = 'Roda watchers de saude, foco digital e metricas operacionais para emitir insights contextuais no Mobile Inbox.';

    public function handle(InsightWatcherService $watcher): int
    {
        $result = $watcher->run((bool) $this->option('dry-run'));

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
