<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use Illuminate\Console\Command;

class AtlasFrontendWorldBestProofPlanCommand extends Command
{
    protected $signature = 'atlas:frontend:world-best-plan
        {--rival-evidence= : Directory containing external rival replay evidence manifests}
        {--bundle= : Static product proof bundle directory}
        {--publication-receipt= : Optional publication receipt JSON for audit only}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless the private benchmark proof is ready}';

    protected $description = 'Legacy alias for atlas:frontend:private-benchmark-plan; public superiority claims stay disabled.';

    public function handle(AtlasFrontendPrivateBenchmarkProofPlanService $proofPlan): int
    {
        $payload = $proofPlan->plan([
            'rival_evidence' => (string) ($this->option('rival-evidence') ?? ''),
            'bundle' => (string) ($this->option('bundle') ?? ''),
            'publication_receipt' => (string) ($this->option('publication-receipt') ?? ''),
        ]);
        $payload['legacy_alias'] = [
            'command' => 'atlas:frontend:world-best-plan',
            'canonical_command' => 'atlas:frontend:private-benchmark-plan',
            'status' => 'legacy_compatibility_only_not_operator_default',
            'public_superiority_claims_disabled' => true,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Private Benchmark Plan legacy alias: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'private_benchmark_ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
