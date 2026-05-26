<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendAssetPackService;
use Illuminate\Console\Command;

class AtlasFrontendAssetPackCommand extends Command
{
    protected $signature = 'atlas:frontend:assets
        {action=inspect : inspect or template}
        {--pack= : Frontend asset pack JSON path for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect Atlas Frontend asset packs or write governed templates.';

    public function handle(AtlasFrontendAssetPackService $assets): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $assets->inspect((string) ($this->option('pack') ?: '')),
            'template' => $assets->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-assets'))),
            default => [
                'schema_version' => AtlasFrontendAssetPackService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Asset Pack: '.$payload['status']);
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
