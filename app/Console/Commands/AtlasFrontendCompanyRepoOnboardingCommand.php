<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyRepoOnboardingService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendCompanyRepoOnboardingCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:onboard
        {--task= : Company frontend/product/design task}
        {--workspace= : Local company/product frontend repository path}
        {--frontend-app= : Optional monorepo frontend app scope, e.g. apps/web}
        {--provider=provider_neutral : Provider name for pilot/provider packet}
        {--output= : Proof pilot output directory}
        {--write-docs : Write Atlas Frontend design dossier and blueprint templates when missing}
        {--acceptance : Acceptance criteria exists}
        {--test-plan : Test plan exists}
        {--visual-quality-plan : Visual quality plan exists}
        {--evidence-plan : Evidence plan exists}
        {--senior-design-review : Senior design review is available}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless onboarding is ready for operator execution}';

    protected $description = 'Install and prepare Atlas Frontend operating runtime for a local company frontend repo.';

    public function handle(AtlasFrontendCompanyRepoOnboardingService $onboarding): int
    {
        $payload = $onboarding->run([
            'task' => (string) ($this->option('task') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
            'provider' => (string) ($this->option('provider') ?: 'provider_neutral'),
            'output' => (string) ($this->option('output') ?: ''),
            'write' => true,
            'write_docs' => (bool) $this->option('write-docs'),
            'acceptance_criteria' => (bool) $this->option('acceptance'),
            'test_plan' => (bool) $this->option('test-plan'),
            'visual_quality_plan' => (bool) $this->option('visual-quality-plan'),
            'evidence_plan' => (bool) $this->option('evidence-plan'),
            'senior_design_review' => (bool) $this->option('senior-design-review'),
            'asset_context' => (bool) $this->option('asset-context'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Onboarding: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready_for_operator_execution'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
