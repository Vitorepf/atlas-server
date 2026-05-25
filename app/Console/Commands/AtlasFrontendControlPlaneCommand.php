<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendControlPlaneService;
use Illuminate\Console\Command;

class AtlasFrontendControlPlaneCommand extends Command
{
    protected $signature = 'atlas:frontend:control-plane
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
        {--rival-evidence= : Directory containing external rival replay evidence manifests}
        {--bundle= : Static product proof bundle directory}
        {--publication-receipt= : Verified public publication receipt JSON}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless control plane status is ready}';

    protected $description = 'Inspect Atlas Frontend execution readiness, market claim policy, rival replay and public proof status.';

    public function handle(AtlasFrontendControlPlaneService $controlPlane): int
    {
        $payload = $controlPlane->snapshot([
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
            'rival_evidence' => (string) ($this->option('rival-evidence') ?? ''),
            'bundle' => (string) ($this->option('bundle') ?? ''),
            'publication_receipt' => (string) ($this->option('publication-receipt') ?? ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Control Plane: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
