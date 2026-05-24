<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use Illuminate\Console\Command;

class AtlasAedpdsGateCommand extends Command
{
    protected $signature = 'atlas:aedpds:gate
        {--task= : Task text}
        {--surface=atlas_ai : Surface}
        {--workspace= : Workspace/project}
        {--json : Print JSON}
        {--strict : Exit non-zero when blocked}';

    protected $description = 'Runs AEDPDS execution gate for a task.';

    public function handle(AtlasExecutionDoctrineGateService $service): int
    {
        $task = (string) ($this->option('task') ?: 'Atlas AEDPDS task');
        $lower = mb_strtolower($task);
        $payload = $service->evaluate([
            'task' => $task,
            'surface' => (string) $this->option('surface'),
            'workspace' => $this->option('workspace'),
            'code_changes_requested' => true,
            'acceptance_criteria' => ['expected behavior is specified'],
            'context_refs' => ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md'],
            'tests' => ['focused verification command'],
            'contracts' => str_contains($lower, 'api') || str_contains($lower, 'endpoint') ? ['api contract'] : [],
            'docs' => ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md'],
            'review' => ['risk review placeholder for CLI gate'],
            'evidence' => ['aedpds gate output'],
            'ux_expectations' => str_contains($lower, 'tela') || str_contains($lower, 'ui') ? ['ux expectation'] : [],
        ]);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return (bool) $this->option('strict') && ($payload['status'] ?? null) === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
