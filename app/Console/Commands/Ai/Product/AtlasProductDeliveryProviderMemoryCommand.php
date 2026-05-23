<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductDeliveryProviderMemoryFeedService;
use Illuminate\Console\Command;

class AtlasProductDeliveryProviderMemoryCommand extends Command
{
    protected $signature = 'atlas:product-delivery:provider-memory
        {--route= : Optional route filter}
        {--limit=100 : Receipt/outcome sample limit}
        {--json : Emit JSON}
        {--strict : Exit non-zero only if schema is invalid}';

    protected $description = 'Aggregates AEDPDS provider, cost, and flake memory for Risk Governor without invoking providers.';

    public function handle(AtlasProductDeliveryProviderMemoryFeedService $service): int
    {
        $payload = $service->analyze([
            'route' => $this->option('route'),
            'limit' => (int) $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Product Provider Memory', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('receipts', (string) data_get($payload, 'sample.receipt_count'));
            $this->components->twoColumnDetail('flakes', (string) data_get($payload, 'risk_signals.flake_count'));
            $this->components->twoColumnDetail('hash', (string) $payload['provider_memory_hash']);
        }

        return (bool) $this->option('strict') && ($payload['schema_version'] ?? null) !== AtlasProductDeliveryProviderMemoryFeedService::SCHEMA_VERSION
            ? self::FAILURE
            : self::SUCCESS;
    }
}
