<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextObservabilityPlaneService;
use Illuminate\Console\Command;

final class AtlasContextObservabilityPlaneCommand extends Command
{
    protected $signature = 'atlas:context:observability
        {--domain=atlas : Domain}
        {--task-type=direct : Task type}
        {--risk=low : Risk level}
        {--hours=24 : Snapshot window}
        {--json : Emit canonical JSON}';

    protected $description = 'Show AUCRI ACOP context observability snapshot without exposing raw text.';

    public function handle(AtlasContextObservabilityPlaneService $service): int
    {
        $payload = $service->snapshot([
            'domain' => (string) $this->option('domain'),
            'task_type' => (string) $this->option('task-type'),
            'risk_level' => (string) $this->option('risk'),
            'hours' => (int) $this->option('hours'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Observability', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Traces', (string) data_get($payload, 'snapshot.trace_count', 0));
        $this->components->twoColumnDetail('Sources', (string) data_get($payload, 'snapshot.source_count', 0));
        $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'snapshot.blocker_count', 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
