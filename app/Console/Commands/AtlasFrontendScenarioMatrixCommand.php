<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendScenarioMatrixService;
use Illuminate\Console\Command;

class AtlasFrontendScenarioMatrixCommand extends Command
{
    protected $signature = 'atlas:frontend:scenarios
        {--task= : Frontend task or user intent}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--workspace= : Local company/product frontend repository path}
        {--frontend-app= : Optional frontend app subdirectory inside the selected repository, e.g. apps/web}
        {--acceptance : Acceptance criteria are present}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile-ready : Ready company profile exists}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless scenario matrix is ready}';

    protected $description = 'Compile the Atlas Frontend route x viewport x state visual verification scenario matrix.';

    public function handle(AtlasFrontendScenarioMatrixService $matrix): int
    {
        $payload = $matrix->compile([
            'task' => (string) ($this->option('task') ?? ''),
            'surface' => (string) ($this->option('surface') ?? 'programming.frontend'),
            'workspace' => (string) ($this->option('workspace') ?? ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?? ''),
            'acceptance_criteria' => (bool) $this->option('acceptance'),
            'asset_context' => (bool) $this->option('asset-context'),
            'company_profile_ready' => (bool) $this->option('company-profile-ready'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Scenario Matrix: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
