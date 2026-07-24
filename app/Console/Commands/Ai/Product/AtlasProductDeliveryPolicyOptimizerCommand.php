<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductDeliveryPolicyOptimizerService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProductDeliveryPolicyOptimizerCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:product-delivery:policy-optimizer
        {--receipt-limit=25 : Runtime receipt replay limit}
        {--fitness-limit=100 : Outcome memory fitness sample limit}
        {--provider-memory-limit=100 : Provider/cost/flake memory sample limit}
        {--json : Emit JSON}
        {--strict : Exit non-zero only if schema is invalid}';

    protected $description = 'Proposes guarded AEDPDS policy improvements from replay, doctrine fitness, and provider memory without applying policy.';

    public function handle(AtlasProductDeliveryPolicyOptimizerService $service): int
    {
        $payload = $service->propose([
            'receipt_limit' => (int) $this->option('receipt-limit'),
            'fitness_limit' => (int) $this->option('fitness-limit'),
            'provider_memory_limit' => (int) $this->option('provider-memory-limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('Product Policy Optimizer', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('proposals', (string) $payload['proposal_count']);
            $this->components->twoColumnDetail('hash', (string) $payload['policy_optimizer_hash']);
        }

        return (bool) $this->option('strict') && ($payload['schema_version'] ?? null) !== AtlasProductDeliveryPolicyOptimizerService::SCHEMA_VERSION
            ? self::FAILURE
            : self::SUCCESS;
    }
}
