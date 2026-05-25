<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use Illuminate\Console\Command;

class AtlasFrontendExecutionRunbookCommand extends Command
{
    protected $signature = 'atlas:frontend:runbook
        {--task= : Frontend task or intent}
        {--workspace= : Local company/product frontend repository path}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--evidence-output= : Evidence output directory}
        {--acceptance : Acceptance criteria exists}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile-ready : Company design profile is ready}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--test-plan : Test plan exists}
        {--visual-quality-plan : Visual quality plan exists}
        {--evidence-plan : Evidence plan exists}
        {--senior-design-review : Senior design review is available}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless runbook is ready}';

    protected $description = 'Compile a repo-specific Atlas Frontend execution runbook.';

    public function handle(AtlasFrontendExecutionRunbookService $runbook): int
    {
        $payload = $runbook->compile([
            'task' => (string) ($this->option('task') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
            'evidence_output' => (string) ($this->option('evidence-output') ?: ''),
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
            $this->line('Atlas Frontend Runbook: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
