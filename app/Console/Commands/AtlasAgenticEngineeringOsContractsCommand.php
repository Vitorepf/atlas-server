<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringOsContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Agentic Engineering OS Contracts gate CLI.
 *
 *   php artisan atlas:aaeos:agentic-engineering-os-contracts [--json]
 *
 * Read-only, deterministic. Emits the full contract manifest: the 15 universal
 * gates, the delivery-blocking subset, the L0..L7 autonomy ladder, the minimum
 * enterprise evidence pack and the completion chain.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
 */
class AtlasAgenticEngineeringOsContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:agentic-engineering-os-contracts {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Agentic Engineering OS · contract manifest (15 universal gates, autonomy ladder L0..L7, enterprise evidence minimum, completion chain).';

    public function handle(AtlasAgenticEngineeringOsContractsService $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'agentic_engineering_os_contracts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
