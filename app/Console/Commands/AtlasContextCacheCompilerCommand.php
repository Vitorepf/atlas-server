<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextCacheCompilerRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextCacheCompilerCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:cache-warm
        {--flow-id=atlas_dev : Flow id}
        {--provider=gpt : Provider}
        {--workspace=atlas : Workspace id/slug}
        {--previous-prefix-hash= : Previous cacheable prefix hash}
        {--expected-prefix-hash= : Expected cacheable prefix hash for drift detection}
        {--cache-stale : Simulate stale freshness}
        {--cache-poisoned : Simulate poisoned cache}
        {--json : Emit canonical JSON}';

    protected $description = 'Build ACCCR Merkle context cache pack, prompt warmup receipt, and delta request. No provider calls, no writes.';

    public function handle(AtlasContextCacheCompilerRuntimeService $service): int
    {
        $payload = $service->warm([
            'flow_id' => (string) $this->option('flow-id'),
            'provider' => (string) $this->option('provider'),
            'workspace' => (string) $this->option('workspace'),
            'previous_prefix_hash' => (string) ($this->option('previous-prefix-hash') ?: ''),
            'expected_prefix_hash' => (string) ($this->option('expected-prefix-hash') ?: ''),
            'cache_fresh' => ! (bool) $this->option('cache-stale'),
            'cache_poisoned' => (bool) $this->option('cache-poisoned'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return ($payload['status'] ?? null) === AtlasContextCacheCompilerRuntimeService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('ACCCR', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Cache', (string) data_get($payload, 'warmup_receipt.cache_status', 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['context_cache_hash'] ?? 'missing'));

        return ($payload['status'] ?? null) === AtlasContextCacheCompilerRuntimeService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
