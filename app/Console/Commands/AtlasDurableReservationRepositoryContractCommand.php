<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationRepositoryContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Repository Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-repository-contract [--json]
 *
 * Read-only, deterministic. Emits the repository contract (the eight required
 * methods, the nine required errors and the transaction rules) and runs the
 * decider over a representative duplicate-claim attempt so the output proves the
 * contract is enforced, not merely echoed. It persists no claim, performs no
 * storage write and dispatches nothing — dispatch_enabled stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
 */
class AtlasDurableReservationRepositoryContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-repository-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation repository contract — read-only enforcement of required methods, errors and transaction rules, persisting nothing.';

    public function handle(AtlasDurableReservationRepositoryContractService $service): int
    {
        try {
            $contract = $service->contract();

            // Safe default sample: a duplicate active claim, which the contract
            // must reject with packet_already_claimed.
            $projection = [
                'PKT-1' => ['packet_id' => 'PKT-1', 'state' => 'claimed', 'allowed_files' => ['app/Foo.php']],
            ];
            $duplicateClaim = $service->decideClaim(
                ['packet_id' => 'PKT-1', 'allowed_files' => ['app/Foo.php']],
                $projection,
            );

            $violations = $service->assertNoDispatch([$contract, $duplicateClaim]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'result' => $contract,
                'sample_duplicate_claim' => $duplicateClaim,
                'dispatch_guarantee_held' => $guaranteeHeld,
                'dispatch_guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $guaranteeHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_repository_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
