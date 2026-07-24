<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductTruthCompilerService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProductTruthCompileCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:product-truth:compile
        {request? : Human request to compile}
        {--workspace= : Workspace slug}
        {--route= : Optional route override}
        {--json : Print JSON}';

    protected $description = 'Compiles a human product request into a Product Truth Contract without invoking providers.';

    public function handle(AtlasProductTruthCompilerService $service): int
    {
        $report = $service->compile([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery request'),
            'workspace' => $this->option('workspace'),
            'route' => $this->option('route'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Product Truth', (string) $report['schema_version']);
        $this->components->twoColumnDetail('status', (string) $report['status']);
        $this->components->twoColumnDetail('route', (string) data_get($report, 'execution_decomposition.route'));
        $this->components->twoColumnDetail('truth_hash', (string) $report['truth_hash']);

        return self::SUCCESS;
    }
}
