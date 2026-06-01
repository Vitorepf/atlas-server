<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationImplementationPreflightContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Implementation Preflight Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-implementation-preflight-contract [--json]
 *
 * Read-only, deterministic. Runs the FINAL implementation preflight: the seven
 * Required Contract Hashes (presence + drift), the seven Required Gates and the
 * seven Blocking Conditions that must all hold BEFORE any durable-reservation
 * implementation may create migrations, repository code, storage writes or claim
 * persistence. By safe default (no hash, no gate signal proven) every hash is
 * missing, every gate fails, blockers fire, status is `blocked` and
 * `preflight_packet_emitted=false`. It creates no migration, no storage write,
 * no claim and no dispatch — the non-execution guarantee (migrations_created /
 * storage_writes_performed / claim_persistence_performed / dispatch_enabled)
 * stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
 */
class AtlasDurableReservationImplementationPreflightContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-implementation-preflight-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation implementation preflight contract — read-only final aggregate check (7 contract hashes, 7 gates, 7 blockers) emitting nothing to execute.';

    public function handle(AtlasDurableReservationImplementationPreflightContractService $service): int
    {
        try {
            // Safe defaults: nothing proven => every hash missing, every gate
            // fails, blockers fire, no packet is emitted and nothing is executed.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the non-execution guarantee held, not that the
            // preflight packet was emitted clean.
            return ($result['guarantee_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_implementation_preflight_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
