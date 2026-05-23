<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductDeliveryDoctrineFitnessService;
use App\Services\Ai\Product\AtlasProductDeliveryEvidenceReplayLabService;
use App\Services\Ai\Product\AtlasProductDeliveryRiskGovernorService;
use Illuminate\Console\Command;

class AtlasProductDeliveryRiskGovernorCommand extends Command
{
    protected $signature = 'atlas:product-delivery:risk-govern
        {request? : Human request to govern}
        {--workspace= : Workspace slug}
        {--route= : Optional route override}
        {--provider-patch : Treat as provider/subagent patch candidate}
        {--operator-approved : Simulate explicit operator approval}
        {--json : Print JSON}
        {--strict : Exit non-zero unless risk governance allows the current phase}';

    protected $description = 'Evaluates AEDPDS delivery risk, approvals, gates, and autonomy budget without providers or writes.';

    public function handle(
        AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime,
        AtlasProductDeliveryRiskGovernorService $riskGovernor,
        AtlasProductDeliveryEvidenceReplayLabService $replayLab,
        AtlasProductDeliveryDoctrineFitnessService $doctrineFitness,
    ): int {
        $delivery = $deliveryRuntime->plan([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery request'),
            'workspace' => $this->option('workspace'),
            'route' => $this->option('route'),
        ]);
        $replay = $replayLab->replay([
            'workspace' => $this->option('workspace') ?: 'atlas-server',
        ]);
        $fitness = $doctrineFitness->evaluate();

        $report = $riskGovernor->evaluate(
            delivery: $delivery,
            proof: $delivery['proof_preview'] ?? [],
            simulation: $delivery['product_twin_simulation'] ?? [],
            options: [
                'provider_patch' => (bool) $this->option('provider-patch'),
                'operator_approved' => (bool) $this->option('operator-approved'),
                'replay_report' => $replay,
                'doctrine_fitness' => $fitness,
            ],
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Product Delivery Risk Governor', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('risk', (string) $report['risk_band']);
            $this->components->twoColumnDetail('score', (string) $report['risk_score']);
            $this->components->twoColumnDetail('hash', (string) $report['risk_governor_hash']);
        }

        return (bool) $this->option('strict') && ($report['status'] ?? null) !== 'allowed'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
