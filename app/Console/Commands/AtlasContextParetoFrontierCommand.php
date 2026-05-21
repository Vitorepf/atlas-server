<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use Illuminate\Console\Command;

final class AtlasContextParetoFrontierCommand extends Command
{
    protected $signature = 'atlas:context:pareto-frontier
        {--json : Emit canonical JSON}';

    protected $description = 'Emit AUCRI context Pareto frontier shadow report.';

    public function handle(AtlasContextParetoFrontierRuntimeService $service): int
    {
        $payload = $service->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Pareto Frontier', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Candidates', (string) data_get($payload, 'summary.total_candidates', 0));
        $this->components->twoColumnDetail('Selected shadow', (string) data_get($payload, 'summary.selected_candidates', 0));
        $this->components->twoColumnDetail('Frontier hash', (string) $payload['frontier_hash']);

        return self::SUCCESS;
    }
}
