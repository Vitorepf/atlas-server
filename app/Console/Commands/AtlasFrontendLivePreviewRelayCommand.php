<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendLivePreviewRelayService;
use Illuminate\Console\Command;
use RuntimeException;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendLivePreviewRelayCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:relay
        {action : script, inject or remove}
        {--workspace= : Workspace root}
        {--file= : HTML file for inject/remove}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit or inject the Atlas Frontend live CSS preview relay.';

    public function handle(AtlasFrontendLivePreviewRelayService $relay): int
    {
        try {
            $payload = match ((string) $this->argument('action')) {
                'script' => $relay->script(),
                'inject' => $relay->inject((string) ($this->option('workspace') ?: base_path()), (string) $this->option('file')),
                'remove' => $relay->remove((string) ($this->option('workspace') ?: base_path()), (string) $this->option('file')),
                default => throw new RuntimeException('invalid_action'),
            };
        } catch (RuntimeException $exception) {
            $payload = [
                'schema_version' => AtlasFrontendLivePreviewRelayService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Live Preview Relay: '.($payload['status'] ?? $payload['mode'] ?? 'unknown'));
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
