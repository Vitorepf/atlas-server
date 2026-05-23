<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductDeliveryMultiStepRepairPlannerService;
use Illuminate\Console\Command;

class AtlasProductDeliveryRepairPlanCommand extends Command
{
    protected $signature = 'atlas:product-delivery:repair-plan
        {request : Human product/delivery request}
        {--workspace= : Workspace slug/path}
        {--json : Emit JSON}
        {--strict : Exit non-zero unless planned or not_required}';

    protected $description = 'Plans a provider-free AEDPDS multi-step repair plan with budgets, rollback gates, and stop conditions.';

    public function handle(
        AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime,
        AtlasProductDeliveryMultiStepRepairPlannerService $planner,
    ): int {
        $delivery = $deliveryRuntime->plan([
            'human_request' => (string) $this->argument('request'),
            'workspace' => (string) ($this->option('workspace') ?: ''),
        ]);
        $payload = $planner->plan(
            delivery: $delivery,
            proof: is_array($delivery['proof_preview'] ?? null) ? $delivery['proof_preview'] : [],
            repairBridge: is_array($delivery['repair_bridge'] ?? null) ? $delivery['repair_bridge'] : [],
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Repair plan', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('steps', (string) count($payload['steps'] ?? []));
            $this->components->twoColumnDetail('hash', (string) $payload['repair_plan_hash']);
        }

        return (bool) $this->option('strict')
            && ! in_array($payload['status'] ?? null, ['planned', 'not_required'], true)
                ? self::FAILURE
                : self::SUCCESS;
    }
}
