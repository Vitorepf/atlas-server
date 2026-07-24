<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendPrivateBenchmarkProofPlanCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:private-benchmark-plan
        {--rival-evidence= : Directory containing external rival replay evidence manifests}
        {--bundle= : Static product proof bundle directory}
        {--publication-receipt= : Optional publication receipt JSON for audit only}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless the private benchmark proof is ready}';

    protected $description = 'Compile the private Atlas Frontend competitive benchmark proof and improvement plan without public superiority claims.';

    public function handle(AtlasFrontendPrivateBenchmarkProofPlanService $proofPlan): int
    {
        $payload = $proofPlan->plan([
            'rival_evidence' => (string) ($this->option('rival-evidence') ?? ''),
            'bundle' => (string) ($this->option('bundle') ?? ''),
            'publication_receipt' => (string) ($this->option('publication-receipt') ?? ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Private Benchmark Plan: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'private_benchmark_ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
