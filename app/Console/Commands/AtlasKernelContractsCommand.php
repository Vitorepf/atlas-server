<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasKernelContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Kernel Contracts gate CLI.
 *
 *   php artisan atlas:aaeos:kernel-contracts
 *     [--machine=operation] [--from=executing] [--to=repairing]
 *     [--json]
 *
 * Read-only, deterministic. With --machine/--from/--to it decides one state
 * transition against the documented Kernel state machines; otherwise it emits
 * the full contract manifest (objects, machines, terminal states).
 *
 * @see docs/engineering-knowledge-base/kernel/contracts.md
 */
class AtlasKernelContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:kernel-contracts
        {--machine= : state machine to evaluate (operation|decision_receipt|tool_run)}
        {--from= : current state}
        {--to= : target state}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Kernel · contract gate (Core Object field validation, state-machine transitions, policy invariant) + manifest.';

    public function handle(AtlasKernelContractsService $service): int
    {
        try {
            $machine = $this->option('machine');
            $from = $this->option('from');
            $to = $this->option('to');

            if (is_string($machine) && trim($machine) !== ''
                && is_string($from) && trim($from) !== ''
                && is_string($to) && trim($to) !== ''
            ) {
                $payload = [
                    'ok' => true,
                    'transition' => $service->transition($machine, $from, $to),
                ];
            } else {
                $payload = [
                    'ok' => true,
                    'manifest' => $service->manifest(),
                ];
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'kernel_contracts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
