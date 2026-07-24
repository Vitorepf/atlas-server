<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasUnifiedRealityGraphService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasUnifiedRealityGraphCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:reality-graph
        {--hours=720 : Snapshot window in hours}
        {--limit=100 : Maximum entities/edges}
        {--risk=low : Risk level}
        {--json : Emit canonical JSON}';

    protected $description = 'Run AUCRI AURG reality graph snapshot over governed Atlas reality entities and relationships.';

    public function handle(AtlasUnifiedRealityGraphService $service): int
    {
        $payload = $service->snapshot([
            'hours' => (int) $this->option('hours'),
            'limit' => (int) $this->option('limit'),
            'risk' => (string) $this->option('risk'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Unified Reality Graph', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Entities', (string) data_get($payload, 'summary.entities_total', 0));
        $this->components->twoColumnDetail('Edges', (string) data_get($payload, 'summary.edges_total', 0));
        $this->components->twoColumnDetail('Sources', (string) data_get($payload, 'summary.sources_total', 0));
        $this->components->twoColumnDetail('Snapshot hash', (string) $payload['snapshot_hash']);

        return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
