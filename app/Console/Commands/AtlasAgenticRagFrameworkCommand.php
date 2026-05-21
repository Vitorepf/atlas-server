<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasAgenticRagFrameworkService;
use Illuminate\Console\Command;

final class AtlasAgenticRagFrameworkCommand extends Command
{
    protected $signature = 'atlas:context:agentic-rag
        {--query= : Objective/query to plan agentic retrieval for}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the AUCRI AARF agentic RAG plan with AHRI, gap critic and sufficiency gate.';

    public function handle(AtlasAgenticRagFrameworkService $service): int
    {
        $payload = $service->plan([
            'objective' => (string) ($this->option('query') ?: 'atlas agentic rag readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Agentic RAG Framework', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Gap critic', (string) data_get($payload, 'gap_critic.status', 'unknown'));
        $this->components->twoColumnDetail('Sufficiency gate', (string) data_get($payload, 'context_sufficiency_gate.status', 'unknown'));
        $this->components->twoColumnDetail('Plan hash', (string) $payload['agentic_rag_plan_hash']);

        return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
