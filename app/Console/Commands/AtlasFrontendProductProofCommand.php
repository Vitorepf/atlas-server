<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use Illuminate\Console\Command;

class AtlasFrontendProductProofCommand extends Command
{
    protected $signature = 'atlas:frontend:proof
        {action=catalog : catalog, build or pilot}
        {--task= : Frontend task or product intent for pilot proof}
        {--workspace= : Local company frontend repository path for pilot proof}
        {--provider=provider_neutral : Provider name for pilot proof packet}
        {--output= : Output directory for build action}
        {--frontend-app= : Optional monorepo frontend app scope for build or pilot action, e.g. apps/web}
        {--acceptance : Acceptance criteria are present}
        {--test-plan : Test plan is present}
        {--visual-quality-plan : Visual quality plan is present}
        {--evidence-plan : Evidence plan is present}
        {--senior-design-review : Senior design review is required/present}
        {--json : Emit canonical JSON payload}
        {--strict : Fail when the selected proof action is blocked or partial}';

    protected $description = 'Emit Atlas Frontend product proof demo catalog or build/pilot proof dossier.';

    public function handle(AtlasFrontendProductProofRuntimeService $proof): int
    {
        $payload = match ((string) $this->argument('action')) {
            'catalog' => $proof->catalog(),
            'build' => $proof->buildStaticBundle((string) ($this->option('output') ?: ''), (string) ($this->option('frontend-app') ?: '')),
            'pilot' => $proof->pilotDossier([
                'task' => (string) ($this->option('task') ?: ''),
                'workspace' => (string) ($this->option('workspace') ?: ''),
                'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
                'provider' => (string) ($this->option('provider') ?: 'provider_neutral'),
                'output' => (string) ($this->option('output') ?: ''),
                'acceptance_criteria' => (bool) $this->option('acceptance'),
                'test_plan' => (bool) $this->option('test-plan'),
                'visual_quality_plan' => (bool) $this->option('visual-quality-plan'),
                'evidence_plan' => (bool) $this->option('evidence-plan'),
                'senior_design_review' => (bool) $this->option('senior-design-review'),
            ]),
            default => [
                'schema_version' => AtlasFrontendProductProofRuntimeService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Product Proof: '.$payload['status']);
        }

        if (($payload['status'] ?? null) === 'failed') {
            return self::FAILURE;
        }

        if ((bool) $this->option('strict') && in_array(($payload['status'] ?? null), ['blocked', 'partial'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
