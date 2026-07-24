<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextCompilerRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextCompilerRuntimeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:compile
        {--provider=gpt : claude|gpt|gemini|local}
        {--risk=low : Risk level}
        {--flow-id=atlas.context.compile : Flow id}
        {--repeated-tokens=0 : Repeated tokens already covered by delta}
        {--json : Emit canonical JSON}';

    protected $description = 'Compile AUCRI context into provider-aware final context pack with loss accounting.';

    public function handle(AtlasContextCompilerRuntimeService $service): int
    {
        $payload = $service->compile([
            'provider' => (string) $this->option('provider'),
            'risk_level' => (string) $this->option('risk'),
            'flow_id' => (string) $this->option('flow-id'),
            'repeated_tokens' => (int) $this->option('repeated-tokens'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Compiler', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Provider', (string) data_get($payload, 'provider_profile.provider', 'unknown'));
        $this->components->twoColumnDetail('Must-keep', (string) data_get($payload, 'loss_check.must_keep_coverage', 0));
        $this->components->twoColumnDetail('Token savings', (string) data_get($payload, 'prompt_budget_receipt.token_savings_estimate', 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
