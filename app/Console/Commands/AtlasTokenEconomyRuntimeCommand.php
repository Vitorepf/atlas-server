<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use Illuminate\Console\Command;

final class AtlasTokenEconomyRuntimeCommand extends Command
{
    protected $signature = 'atlas:context:token-economy
        {--provider=gpt : claude|gpt|gemini|local}
        {--risk=low : Risk level}
        {--task-type=general : Task type}
        {--must-keep-coverage=1.0 : Must keep coverage}
        {--json : Emit canonical JSON}';

    protected $description = 'Optimize AUCRI token economy with quality gate, reuse and local pre-reasoning receipts.';

    public function handle(AtlasTokenEconomyRuntimeService $service): int
    {
        $payload = $service->optimize([
            'provider' => (string) $this->option('provider'),
            'risk_level' => (string) $this->option('risk'),
            'task_type' => (string) $this->option('task-type'),
            'must_keep_coverage' => (float) $this->option('must-keep-coverage'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Token Economy', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Provider', (string) data_get($payload, 'provider_model_selection.selected_provider', 'unknown'));
        $this->components->twoColumnDetail('Savings', (string) data_get($payload, 'compression_receipt.savings_estimate', 0));
        $this->components->twoColumnDetail('Quality', (string) data_get($payload, 'quality_check.quality_gate_status', 'unknown'));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
