<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendOutcomeMemoryCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:outcomes
        {action=summary : summary, record or template}
        {--store= : JSONL outcome memory store path}
        {--status=passed : Outcome status}
        {--task-type=frontend_task : Frontend task type}
        {--surface=programming.frontend : Surface}
        {--workspace= : Workspace ref to hash}
        {--company-profile-ref= : Company profile ref to hash}
        {--driver=* : Selected driver}
        {--gate=* : Gate applied}
        {--failed-gate=* : Gate that failed}
        {--evidence-ref=* : Safe evidence ref}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Record or summarize Atlas Frontend outcome memory.';

    public function handle(AtlasFrontendOutcomeMemoryService $memory): int
    {
        $store = (string) ($this->option('store') ?: '');
        $payload = match ((string) $this->argument('action')) {
            'record' => $memory->record([
                'status' => (string) $this->option('status'),
                'task_type' => (string) $this->option('task-type'),
                'surface' => (string) $this->option('surface'),
                'workspace' => (string) ($this->option('workspace') ?: ''),
                'company_profile_ref' => (string) ($this->option('company-profile-ref') ?: ''),
                'drivers' => (array) $this->option('driver'),
                'gates' => (array) $this->option('gate'),
                'failed_gates' => (array) $this->option('failed-gate'),
                'evidence_refs' => (array) $this->option('evidence-ref'),
            ], $store !== '' ? $store : null),
            'template' => $memory->template(),
            default => $memory->summarize($store !== '' ? $store : null),
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Outcomes: '.($payload['status'] ?? 'unknown'));
        }

        return self::SUCCESS;
    }
}
