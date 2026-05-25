<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use Illuminate\Console\Command;

class AtlasFrontendExecutionGateCommand extends Command
{
    protected $signature = 'atlas:frontend:gate
        {--task= : Frontend task or user intent}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--workspace= : Workspace root}
        {--task-spec-hash= : Optional canonical atlas:frontend:spec hash to verify}
        {--company-profile= : Company design profile JSON path}
        {--acceptance : Acceptance criteria are present}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile-ready : Ready company profile exists without attaching file}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--test-plan : Test or verification plan is present}
        {--visual-quality-plan : Visual quality plan is present}
        {--evidence-plan : Expected evidence outputs are declared}
        {--senior-design-review : Senior design review is present for broad work}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero when blocked}';

    protected $description = 'Run the Atlas Frontend pre-execution gate.';

    public function handle(AtlasFrontendExecutionGateService $gate): int
    {
        $payload = $gate->evaluate([
            'task' => (string) ($this->option('task') ?? ''),
            'surface' => (string) ($this->option('surface') ?? 'programming.frontend'),
            'workspace' => (string) ($this->option('workspace') ?? ''),
            'task_spec_hash' => (string) ($this->option('task-spec-hash') ?? ''),
            'company_profile' => (string) ($this->option('company-profile') ?? ''),
            'acceptance_criteria' => (bool) $this->option('acceptance'),
            'asset_context' => (bool) $this->option('asset-context'),
            'company_profile_ready' => (bool) $this->option('company-profile-ready'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
            'test_plan' => (bool) $this->option('test-plan'),
            'visual_quality_plan' => (bool) $this->option('visual-quality-plan'),
            'evidence_plan' => (bool) $this->option('evidence-plan'),
            'senior_design_review' => (bool) $this->option('senior-design-review'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Gate: '.($payload['status'] ?? 'unknown'));
            $this->line('Execution: '.(($payload['execution_allowed'] ?? false) ? 'allowed' : 'blocked'));
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
