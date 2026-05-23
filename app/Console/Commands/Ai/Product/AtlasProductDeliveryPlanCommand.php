<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use Illuminate\Console\Command;

class AtlasProductDeliveryPlanCommand extends Command
{
    protected $signature = 'atlas:product-delivery:plan
        {request? : Human request to plan}
        {--workspace= : Workspace slug}
        {--route= : Optional route override}
        {--json : Print JSON}
        {--strict : Exit non-zero unless ready_for_delivery}';

    protected $description = 'Plans a provider-free AEDPDS/APDR delivery envelope from a human request.';

    public function handle(AtlasAutonomousProductDeliveryRuntimeService $service): int
    {
        $report = $service->plan([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery request'),
            'workspace' => $this->option('workspace'),
            'route' => $this->option('route'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Product Delivery', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('route', (string) $report['route']);
            $this->components->twoColumnDetail('delivery_hash', (string) $report['delivery_hash']);
        }

        return (bool) $this->option('strict') && ($report['status'] ?? null) !== 'ready_for_delivery'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
