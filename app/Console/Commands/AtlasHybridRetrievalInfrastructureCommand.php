<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasHybridRetrievalInfrastructureService;
use Illuminate\Console\Command;

final class AtlasHybridRetrievalInfrastructureCommand extends Command
{
    protected $signature = 'atlas:context:hybrid-retrieval
        {--query= : Objective/query to plan retrieval for}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the AUCRI AHRI hybrid retrieval report without provider calls or writes.';

    public function handle(AtlasHybridRetrievalInfrastructureService $service): int
    {
        $payload = $service->report([
            'objective' => (string) ($this->option('query') ?: 'atlas hybrid retrieval readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Hybrid Retrieval Infrastructure', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Candidates', (string) data_get($payload, 'summary.candidate_count', 0));
        $this->components->twoColumnDetail('Sources', (string) data_get($payload, 'summary.source_count', 0));
        $this->components->twoColumnDetail('Report hash', (string) $payload['retrieval_report_hash']);

        return self::SUCCESS;
    }
}
