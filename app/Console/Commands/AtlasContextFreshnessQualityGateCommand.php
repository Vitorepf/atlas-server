<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextFreshnessQualityGateService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextFreshnessQualityGateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:freshness-quality
        {--query= : Objective/query to evaluate context freshness and quality for}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--max-refs=8 : Maximum refs to rank before quality gate}
        {--json : Emit canonical JSON}';

    protected $description = 'Evaluate the AUCRI ACFQ freshness and quality gate over ranked context.';

    public function handle(AtlasContextFreshnessQualityGateService $service): int
    {
        $payload = $service->evaluate([
            'objective' => (string) ($this->option('query') ?: 'atlas context freshness quality readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
            'max_refs' => (int) $this->option('max-refs'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Freshness Quality Gate', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Action', (string) data_get($payload, 'context_quality_gate.action', 'unknown'));
        $this->components->twoColumnDetail('Ranking', (string) data_get($payload, 'ranking_ref.status', 'unknown'));
        $this->components->twoColumnDetail('Gate hash', (string) $payload['freshness_quality_gate_hash']);

        return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
