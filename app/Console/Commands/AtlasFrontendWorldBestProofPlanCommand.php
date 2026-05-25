<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendWorldBestProofPlanService;
use Illuminate\Console\Command;

class AtlasFrontendWorldBestProofPlanCommand extends Command
{
    protected $signature = 'atlas:frontend:world-best-plan
        {--rival-evidence= : Directory containing external rival replay evidence manifests}
        {--bundle= : Static product proof bundle directory}
        {--publication-receipt= : Verified public publication receipt JSON}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless world-best proof is ready}';

    protected $description = 'Compile the executable Atlas Frontend world-best proof plan from rival replay and public distribution evidence.';

    public function handle(AtlasFrontendWorldBestProofPlanService $proofPlan): int
    {
        $payload = $proofPlan->plan([
            'rival_evidence' => (string) ($this->option('rival-evidence') ?? ''),
            'bundle' => (string) ($this->option('bundle') ?? ''),
            'publication_receipt' => (string) ($this->option('publication-receipt') ?? ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend World Best Proof Plan: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
