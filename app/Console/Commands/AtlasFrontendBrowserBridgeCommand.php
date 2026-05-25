<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendBrowserBridgeService;
use Illuminate\Console\Command;
use RuntimeException;

class AtlasFrontendBrowserBridgeCommand extends Command
{
    protected $signature = 'atlas:frontend:bridge
        {action : script, inject or remove}
        {--workspace= : Workspace root}
        {--file= : HTML file for inject/remove}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit or inject the Atlas Frontend browser element picker bridge.';

    public function handle(AtlasFrontendBrowserBridgeService $bridge): int
    {
        try {
            $payload = match ((string) $this->argument('action')) {
                'script' => $bridge->script(),
                'inject' => $bridge->inject((string) ($this->option('workspace') ?: base_path()), (string) $this->option('file')),
                'remove' => $bridge->remove((string) ($this->option('workspace') ?: base_path()), (string) $this->option('file')),
                default => throw new RuntimeException('invalid_action'),
            };
        } catch (RuntimeException $exception) {
            $payload = [
                'schema_version' => AtlasFrontendBrowserBridgeService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Browser Bridge: '.($payload['status'] ?? $payload['mode'] ?? 'unknown'));
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
