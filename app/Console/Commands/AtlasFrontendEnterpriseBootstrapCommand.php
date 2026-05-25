<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use Illuminate\Console\Command;

class AtlasFrontendEnterpriseBootstrapCommand extends Command
{
    protected $signature = 'atlas:frontend:enterprise-bootstrap
        {action=inspect : inspect or write}
        {--task= : Company frontend/product/design task}
        {--workspace= : Local company/product frontend repository path}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--acceptance : Acceptance criteria exists}
        {--test-plan : Test plan exists}
        {--visual-quality-plan : Visual quality plan exists}
        {--evidence-plan : Evidence plan exists}
        {--senior-design-review : Senior design review is available}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless enterprise bootstrap is ready}';

    protected $description = 'Prepare a company-owned local repo for premium Atlas Frontend execution.';

    public function handle(AtlasFrontendEnterpriseBootstrapService $bootstrap): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect', 'write' => $bootstrap->run([
                'task' => (string) ($this->option('task') ?: ''),
                'workspace' => (string) ($this->option('workspace') ?: base_path()),
                'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
                'write' => $action === 'write',
                'asset_context' => (bool) $this->option('asset-context'),
                'prototype' => (bool) $this->option('prototype'),
                'live' => (bool) $this->option('live'),
                'acceptance_criteria' => (bool) $this->option('acceptance'),
                'test_plan' => (bool) $this->option('test-plan'),
                'visual_quality_plan' => (bool) $this->option('visual-quality-plan'),
                'evidence_plan' => (bool) $this->option('evidence-plan'),
                'senior_design_review' => (bool) $this->option('senior-design-review'),
            ]),
            default => [
                'schema_version' => AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Enterprise Bootstrap: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
