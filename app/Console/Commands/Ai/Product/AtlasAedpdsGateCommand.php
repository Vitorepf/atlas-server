<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAedpdsGateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aedpds:gate
        {--task= : Task text}
        {--surface=atlas_ai : Surface}
        {--workspace= : Workspace/project}
        {--acceptance=* : Acceptance criteria}
        {--context=* : Context refs or owner docs}
        {--test=* : Required/focused tests}
        {--contract=* : API/schema/contracts}
        {--doc=* : Required docs}
        {--review=* : Review evidence}
        {--evidence=* : Evidence refs}
        {--ux=* : UX expectation or prototype refs}
        {--no-code : Treat as non-code work}
        {--json : Print JSON}
        {--strict : Exit non-zero when blocked}';

    protected $description = 'Runs AEDPDS execution gate for a task.';

    public function handle(AtlasExecutionDoctrineGateService $service): int
    {
        $task = (string) ($this->option('task') ?: 'Atlas AEDPDS task');
        $payload = $service->evaluate([
            'task' => $task,
            'surface' => (string) $this->option('surface'),
            'workspace' => $this->option('workspace'),
            'code_changes_requested' => ! (bool) $this->option('no-code'),
            'acceptance_criteria' => AiStringListNormalizer::trimmedScalarValues($this->option('acceptance')),
            'context_refs' => AiStringListNormalizer::trimmedScalarValues($this->option('context')),
            'tests' => AiStringListNormalizer::trimmedScalarValues($this->option('test')),
            'contracts' => AiStringListNormalizer::trimmedScalarValues($this->option('contract')),
            'docs' => AiStringListNormalizer::trimmedScalarValues($this->option('doc')),
            'review' => AiStringListNormalizer::trimmedScalarValues($this->option('review')),
            'evidence' => AiStringListNormalizer::trimmedScalarValues($this->option('evidence')),
            'ux_expectations' => AiStringListNormalizer::trimmedScalarValues($this->option('ux')),
        ]);
        $this->line($this->encode($payload));

        return (bool) $this->option('strict') && ($payload['status'] ?? null) === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }

}
