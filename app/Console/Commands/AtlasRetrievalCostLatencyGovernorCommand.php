<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasRetrievalCostLatencyGovernorService;
use Illuminate\Console\Command;

final class AtlasRetrievalCostLatencyGovernorCommand extends Command
{
    protected $signature = 'atlas:context:retrieval-budget
        {--domain=atlas : Domain}
        {--task-type=direct : Task type}
        {--risk=low : Risk level}
        {--max-refs= : Max refs allowed for this retrieval pass}
        {--budget-ms= : Latency budget in milliseconds}
        {--budget-cost-units= : Cost budget units}
        {--json : Emit canonical JSON}';

    protected $description = 'Govern AUCRI ARCLG retrieval cost, latency, cache and safe degraded mode.';

    public function handle(AtlasRetrievalCostLatencyGovernorService $service): int
    {
        $payload = $service->govern(array_filter([
            'domain' => (string) $this->option('domain'),
            'task_type' => (string) $this->option('task-type'),
            'risk_level' => (string) $this->option('risk'),
            'max_refs' => $this->option('max-refs') !== null ? (int) $this->option('max-refs') : null,
            'budget_ms' => $this->option('budget-ms') !== null ? (int) $this->option('budget-ms') : null,
            'budget_cost_units' => $this->option('budget-cost-units') !== null ? (int) $this->option('budget-cost-units') : null,
        ], static fn (mixed $value): bool => $value !== null));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Retrieval Budget', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Flow', (string) data_get($payload, 'receipt.flow_id', 'unknown'));
        $this->components->twoColumnDetail('Latency', data_get($payload, 'receipt.observed_latency_ms').'/'.data_get($payload, 'receipt.budget_ms').'ms');
        $this->components->twoColumnDetail('Cost', data_get($payload, 'receipt.estimated_cost_units').'/'.data_get($payload, 'receipt.budget_cost_units'));
        $this->components->twoColumnDetail('Degraded', (string) data_get($payload, 'degraded_mode.status', 'unknown'));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
