<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;
use Illuminate\Console\Command;

class AtlasAedpdsSelectCommand extends Command
{
    protected $signature = 'atlas:aedpds:select
        {--task= : Task text}
        {--surface=atlas_ai : Surface}
        {--workspace= : Workspace/project}
        {--json : Print JSON}';

    protected $description = 'Selects AEDPDS delivery drivers for a task.';

    public function handle(AtlasExecutionDoctrineRuntimeService $service): int
    {
        $payload = $service->select([
            'task' => (string) ($this->option('task') ?: 'Atlas AEDPDS task'),
            'surface' => (string) $this->option('surface'),
            'workspace' => $this->option('workspace'),
            'code_changes_requested' => true,
        ]);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
