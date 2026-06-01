<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationLeaseLifecycleContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Lease Lifecycle Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-lease-lifecycle-contract [--json]
 *
 * Read-only, deterministic. Emits the lease lifecycle CONTRACT (the seven
 * states, the nine documented transitions, the timing rules and the required
 * tests) that future runtime must satisfy. Per the doc it "does not persist
 * claims or write storage" — the non-execution guarantee stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
 */
class AtlasDurableReservationLeaseLifecycleContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-lease-lifecycle-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation lease lifecycle — read-only contract of states, transitions and timing rules, persisting nothing.';

    public function handle(AtlasDurableReservationLeaseLifecycleContractService $service): int
    {
        try {
            // Safe default: emit the full read-only lifecycle contract.
            $contract = $service->contract();
            $violations = $service->assertGuaranteeHeld([$contract]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'result' => $contract,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the non-execution guarantee held.
            return $guaranteeHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_lease_lifecycle_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
