<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductExecutionPrimitivesService;
use Illuminate\Console\Command;

class AtlasProductExecutionPrimitivesCommand extends Command
{
    protected $signature = 'atlas:product-delivery:primitives
        {request? : Human request to project into execution primitives}
        {--workspace= : Workspace slug}
        {--route= : Optional route override}
        {--operator-approved : Mark operator approval as present for runtime gate simulation}
        {--provider-patch : Simulate provider patch capability request}
        {--json : Print JSON}
        {--strict : Exit non-zero unless primitive envelope is ready}';

    protected $description = 'Builds the AEDPDS product execution primitive envelope: intent, twin, outcome, cartography, gate, and provider strategy.';

    public function handle(AtlasProductExecutionPrimitivesService $service): int
    {
        $report = $service->build([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product execution request'),
            'workspace' => $this->option('workspace'),
            'route' => $this->option('route'),
            'operator_approved' => (bool) $this->option('operator-approved'),
            'provider_patch' => (bool) $this->option('provider-patch'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Product Execution Primitives', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('route', (string) data_get($report, 'request.route'));
            $this->components->twoColumnDetail('ready', sprintf(
                '%d/%d',
                (int) data_get($report, 'summary.ready_count', 0),
                (int) data_get($report, 'summary.primitive_count', 0),
            ));
            $this->components->twoColumnDetail('hash', (string) $report['execution_primitives_hash']);
        }

        return (bool) $this->option('strict') && ($report['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
