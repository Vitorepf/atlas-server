<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationRepositoryBlueprintContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Repository Blueprint Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-repository-blueprint-contract [--json]
 *
 * Read-only, deterministic. Emits the repository blueprint (the seven future
 * classes, the nine required methods, the event-hash inputs and the transaction
 * rules) that future runtime converts into PHP services and tests without
 * guessing class names, methods, errors or transaction boundaries. It persists
 * no claim, no storage write, no migration and no dispatch — the non-execution
 * guarantee stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
 */
class AtlasDurableReservationRepositoryBlueprintContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-repository-blueprint-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation repository — read-only blueprint of classes, methods and transaction rules, persisting nothing.';

    public function handle(AtlasDurableReservationRepositoryBlueprintContractService $service): int
    {
        try {
            // Safe default: emit the full read-only repository blueprint.
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
                'error' => 'durable_reservation_repository_blueprint_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
