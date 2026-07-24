<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductTwinSimulationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProductTwinSimulateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:product-twin:simulate
        {request? : Human request to simulate}
        {--workspace= : Workspace slug}
        {--route= : Optional route override}
        {--json : Print JSON}
        {--strict : Exit non-zero unless simulation is unblocked}';

    protected $description = 'Simulates AEDPDS product delivery impact before execution without provider calls or writes.';

    public function handle(AtlasProductTwinSimulationService $service): int
    {
        $report = $service->simulate([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery request'),
            'workspace' => $this->option('workspace'),
            'route' => $this->option('route'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('Product Twin', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('risk', (string) data_get($report, 'risk_forecast.risk_band'));
            $this->components->twoColumnDetail('simulation_hash', (string) $report['simulation_hash']);
        }

        return (bool) $this->option('strict') && ($report['status'] ?? null) !== 'simulated'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
