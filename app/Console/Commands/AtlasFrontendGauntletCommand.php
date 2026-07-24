<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendGauntletService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendGauntletCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:gauntlet
        {--task= : Frontend task or user intent}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--workspace= : Local company/product repository path}
        {--frontend-app= : Optional frontend app subdirectory inside the selected repository, e.g. apps/web}
        {--acceptance : Acceptance criteria are present}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile-ready : Ready company profile exists}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--test-plan : Test or verification plan is present}
        {--visual-quality-plan : Visual quality plan is present}
        {--evidence-plan : Expected evidence outputs are declared}
        {--senior-design-review : Senior design review is present}
        {--benchmark-run : Real benchmark/replay run is attached}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless gauntlet is ready}';

    protected $description = 'Run the Atlas Frontend local company repo gauntlet across spec, gate, dossier, inventory and runtime certification.';

    public function handle(AtlasFrontendGauntletService $gauntlet): int
    {
        $payload = $gauntlet->run([
            'task' => (string) ($this->option('task') ?? ''),
            'surface' => (string) ($this->option('surface') ?? 'programming.frontend'),
            'workspace' => (string) ($this->option('workspace') ?? ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?? ''),
            'acceptance_criteria' => (bool) $this->option('acceptance'),
            'asset_context' => (bool) $this->option('asset-context'),
            'company_profile_ready' => (bool) $this->option('company-profile-ready'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
            'test_plan' => (bool) $this->option('test-plan'),
            'visual_quality_plan' => (bool) $this->option('visual-quality-plan'),
            'evidence_plan' => (bool) $this->option('evidence-plan'),
            'senior_design_review' => (bool) $this->option('senior-design-review'),
            'benchmark_run' => (bool) $this->option('benchmark-run'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Gauntlet: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
