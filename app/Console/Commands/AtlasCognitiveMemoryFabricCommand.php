<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasCognitiveMemoryFabricService;
use Illuminate\Console\Command;

final class AtlasCognitiveMemoryFabricCommand extends Command
{
    protected $signature = 'atlas:context:cognitive-memory
        {--available-gb=12 : Available/reclaimable RAM in GB}
        {--total-gb=48 : Total physical RAM in GB}
        {--swap-gb=0 : Swap used in GB}
        {--cpu-load=0.2 : CPU load 0..1}
        {--repeated-tokens=0 : Estimated repeated context tokens}
        {--json : Emit canonical JSON}';

    protected $description = 'Plan AUCRI ACMF cognitive working memory budget, delta and spillover receipts.';

    public function handle(AtlasCognitiveMemoryFabricService $service): int
    {
        $payload = $service->plan([
            'memory_available_bytes' => (int) round(((float) $this->option('available-gb')) * 1073741824),
            'memory_total_bytes' => (int) round(((float) $this->option('total-gb')) * 1073741824),
            'swap_used_bytes' => (int) round(((float) $this->option('swap-gb')) * 1073741824),
            'cpu_load' => (float) $this->option('cpu-load'),
            'repeated_tokens' => (int) $this->option('repeated-tokens'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Cognitive Memory Fabric', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Mode', (string) data_get($payload, 'budget.mode', 'unknown'));
        $this->components->twoColumnDetail('RAM budget bytes', (string) data_get($payload, 'budget.ram_budget_bytes', 0));
        $this->components->twoColumnDetail('Token savings', (string) data_get($payload, 'delta_receipt.token_savings_estimate', 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
