<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductReleaseGateService;
use Illuminate\Console\Command;

class AtlasProductReleaseGateCommand extends Command
{
    protected $signature = 'atlas:product-delivery:release-gate
        {request? : Human request to evaluate}
        {--workspace=atlas-server : Workspace slug/path}
        {--route= : Optional route override}
        {--provider-patch : Treat as provider/subagent patch candidate}
        {--operator-approved : Simulate explicit operator approval for risk gate}
        {--evidence-ready : Include standard ready evidence for release-gate smoke checks}
        {--json : Emit JSON}
        {--strict : Exit non-zero unless release candidate is allowed}';

    protected $description = 'Decides whether AEDPDS can create a release candidate from Control Plane, Risk, Replay, Fitness, Receipts, and Certification.';

    public function handle(AtlasProductReleaseGateService $service): int
    {
        $payload = $service->decide([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product release gate evaluation'),
            'workspace' => (string) $this->option('workspace'),
            'route' => $this->option('route'),
            'provider_patch' => (bool) $this->option('provider-patch'),
            'operator_approved' => (bool) $this->option('operator-approved'),
            'evidence' => $this->option('evidence-ready') ? [
                'tests' => ['focused tests passed', 'contract tests passed', 'security regression tests passed'],
                'security' => ['abuse cases reviewed'],
                'acceptance_mapping' => ['tests mapped to acceptance criteria'],
                'outcome' => ['outcome memory candidate recorded'],
            ] : [],
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Product Release Gate', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('route', (string) data_get($payload, 'signals.route'));
            $this->components->twoColumnDetail('release', $payload['release_candidate_allowed'] ? 'allowed' : 'blocked');
            $this->components->twoColumnDetail('hash', (string) $payload['release_gate_hash']);
        }

        return (bool) $this->option('strict') && ($payload['release_candidate_allowed'] ?? false) !== true
            ? self::FAILURE
            : self::SUCCESS;
    }
}
