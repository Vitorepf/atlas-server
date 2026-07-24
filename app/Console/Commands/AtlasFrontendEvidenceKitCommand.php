<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendEvidenceKitCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:evidence-kit
        {action=prepare : prepare}
        {--task= : Frontend task or intent}
        {--workspace= : Local company/product frontend repository path}
        {--frontend-app= : Optional frontend app subdirectory inside the selected repository, e.g. apps/web}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--output= : Output directory for evidence collection kit}
        {--acceptance : Acceptance criteria exists}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile-ready : Company design profile is ready}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless kit is ready}';

    protected $description = 'Prepare the Atlas Frontend evidence collection kit for a real company frontend run.';

    public function handle(AtlasFrontendEvidenceKitService $kit): int
    {
        $payload = match ((string) $this->argument('action')) {
            'prepare' => $kit->prepare([
                'task' => (string) ($this->option('task') ?: ''),
                'workspace' => (string) ($this->option('workspace') ?: ''),
                'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
                'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
                'output' => (string) ($this->option('output') ?: ''),
                'acceptance_criteria' => (bool) $this->option('acceptance'),
                'asset_context' => (bool) $this->option('asset-context'),
                'company_profile_ready' => (bool) $this->option('company-profile-ready'),
                'prototype' => (bool) $this->option('prototype'),
                'live' => (bool) $this->option('live'),
            ]),
            default => [
                'schema_version' => AtlasFrontendEvidenceKitService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Evidence Kit: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
