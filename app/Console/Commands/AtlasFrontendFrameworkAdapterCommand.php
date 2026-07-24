<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendFrameworkAdapterRuntimeService;
use Illuminate\Console\Command;
use RuntimeException;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendFrameworkAdapterCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:adapters
        {inspect : Inspect frontend framework adapter}
        {--workspace= : Workspace root}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect Atlas Frontend framework HMR/live-mode adapter.';

    public function handle(AtlasFrontendFrameworkAdapterRuntimeService $adapters): int
    {
        try {
            $payload = match ((string) $this->argument('inspect')) {
                'inspect' => $adapters->inspect((string) ($this->option('workspace') ?: base_path())),
                default => throw new RuntimeException('invalid_action'),
            };
        } catch (RuntimeException $exception) {
            $payload = [
                'schema_version' => AtlasFrontendFrameworkAdapterRuntimeService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Adapter: '.data_get($payload, 'adapter.framework', $payload['status'] ?? 'unknown'));
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
