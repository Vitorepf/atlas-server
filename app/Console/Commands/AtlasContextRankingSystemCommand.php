<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use Illuminate\Console\Command;

final class AtlasContextRankingSystemCommand extends Command
{
    protected $signature = 'atlas:context:rank
        {--query= : Objective/query to rank context for}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--max-refs=8 : Maximum refs to select}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the AUCRI ACRS context ranking report with explainable scores.';

    public function handle(AtlasContextRankingSystemService $service): int
    {
        $payload = $service->rank([
            'objective' => (string) ($this->option('query') ?: 'atlas context ranking readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
            'max_refs' => (int) $this->option('max-refs'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Ranking System', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Selected refs', (string) data_get($payload, 'rerank_result.metrics.selected_count', 0));
        $this->components->twoColumnDetail('Excluded refs', (string) data_get($payload, 'rerank_result.metrics.excluded_count', 0));
        $this->components->twoColumnDetail('Rerank hash', (string) $payload['rerank_result_hash']);

        return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
