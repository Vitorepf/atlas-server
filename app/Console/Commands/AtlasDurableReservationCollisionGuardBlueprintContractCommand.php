<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationCollisionGuardBlueprintContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Collision Guard Blueprint Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-collision-guard-blueprint-contract [--json]
 *
 * Read-only, deterministic. Emits the collision-guard blueprint (the documented
 * Required Inputs, the six Required Blockers, the four Decision States, the
 * seven Required Outputs and the seven Required Tests) that future runtime
 * converts into a guard service and tests without guessing. It creates no PHP
 * file, persists no claim, performs no storage write and dispatches no work —
 * the non-execution guarantee stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md
 */
class AtlasDurableReservationCollisionGuardBlueprintContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-collision-guard-blueprint-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation collision guard — read-only blueprint of inputs, blockers, decision states and outputs, persisting nothing.';

    public function handle(AtlasDurableReservationCollisionGuardBlueprintContractService $service): int
    {
        try {
            // Safe default: emit the full read-only collision-guard blueprint.
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
                'error' => 'durable_reservation_collision_guard_blueprint_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
