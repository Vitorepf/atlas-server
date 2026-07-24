<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextParetoFrontierCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:pareto-frontier
        {--hours=24 : Trace metric summary window in hours}
        {--json : Emit canonical JSON}';

    protected $description = 'Emit AUCRI context Pareto frontier shadow report.';

    public function handle(AtlasContextParetoFrontierRuntimeService $service): int
    {
        $payload = $service->report((int) $this->option('hours'));

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Pareto Frontier', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Candidates', (string) data_get($payload, 'summary.total_candidates', 0));
        $this->components->twoColumnDetail('Selected shadow', (string) data_get($payload, 'summary.selected_candidates', 0));
        $this->components->twoColumnDetail('Real trace shadow', (string) data_get($payload, 'real_trace_shadow.status', 'unknown'));
        $this->components->twoColumnDetail('Frontier hash', (string) $payload['frontier_hash']);

        return self::SUCCESS;
    }
}
