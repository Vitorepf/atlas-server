<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasRetrievalPrivacyTrustLayerService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasRetrievalPrivacyTrustLayerCommand extends Command
{
    protected $signature = 'atlas:context:privacy-trust
        {--query= : Optional context query}
        {--domain=atlas : Domain}
        {--task-type=direct : Task type}
        {--risk=low : Risk level}
        {--provider-target=external : local|external|mixed}
        {--json : Emit canonical JSON}';

    protected $description = 'Evaluate AUCRI ARPTL provider-safe privacy/trust gate without exposing raw text.';

    public function handle(AtlasRetrievalPrivacyTrustLayerService $service): int
    {
        $payload = $service->evaluate([
            'query' => (string) ($this->option('query') ?? ''),
            'domain' => (string) $this->option('domain'),
            'task_type' => (string) $this->option('task-type'),
            'risk_level' => (string) $this->option('risk'),
            'provider_target' => (string) $this->option('provider-target'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Retrieval Privacy Trust', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Classification', (string) data_get($payload, 'provider_gate.classification', 'unknown'));
        $this->components->twoColumnDetail('Provider allowed', data_getYesNo::format($payload, 'provider_gate.provider_allowed'));
        $this->components->twoColumnDetail('Redactions', (string) data_get($payload, 'redaction_receipt.redaction_count', 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
