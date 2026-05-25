<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemInventoryService;
use Illuminate\Console\Command;
use RuntimeException;

class AtlasFrontendDesignSystemInventoryCommand extends Command
{
    protected $signature = 'atlas:frontend:inventory
        {inspect : Inspect frontend design-system inventory}
        {--workspace= : Workspace root}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect Atlas Frontend design-system tokens, components and libraries.';

    public function handle(AtlasFrontendDesignSystemInventoryService $inventory): int
    {
        try {
            $payload = match ((string) $this->argument('inspect')) {
                'inspect' => $inventory->inspect((string) ($this->option('workspace') ?: base_path())),
                default => throw new RuntimeException('invalid_action'),
            };
        } catch (RuntimeException $exception) {
            $payload = [
                'schema_version' => AtlasFrontendDesignSystemInventoryService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Inventory: '.($payload['status'] ?? 'unknown'));
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
