<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationLeaseLifecycleBlueprintContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Lease Lifecycle Blueprint Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-lease-lifecycle-blueprint-contract [--json]
 *
 * Read-only, deterministic. Emits the lease lifecycle blueprint (the seven
 * states, the documented transitions and the timing rules) that future runtime
 * converts into state handling without guessing. It persists no claim, no
 * storage write, no migration and no dispatch — the non-execution guarantee
 * stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md
 */
class AtlasDurableReservationLeaseLifecycleBlueprintContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-lease-lifecycle-blueprint-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation lease lifecycle — read-only blueprint of states, transitions and timing rules, persisting nothing.';

    public function handle(AtlasDurableReservationLeaseLifecycleBlueprintContractService $service): int
    {
        try {
            // Safe default: emit the full read-only lifecycle blueprint.
            $blueprint = $service->blueprint();
            $violations = $service->assertGuaranteeHeld([$blueprint]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'result' => $blueprint,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the non-execution guarantee held.
            return $guaranteeHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_lease_lifecycle_blueprint_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
