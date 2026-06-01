<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationCollisionGuardContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Collision Guard Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-collision-guard-contract [--json]
 *
 * Read-only, deterministic. Evaluates one candidate packet against the active
 * reservation ledger, hot forbidden scope and completion gate, then emits the
 * decision (allow_preview | block_claim | require_human_review) with every
 * documented Required Output. By safe default (empty input) the completion gate
 * is not green, so the guard fires `completion_gate_blocked` and routes to
 * require_human_review. It persists no claim, no storage write, no migration and
 * no dispatch — the non-execution guarantee stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
 */
class AtlasDurableReservationCollisionGuardContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-collision-guard-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation collision guard — read-only claim guard (hot scope, file overlap, stale hash, dependency, completion gate, owner conflict) persisting nothing.';

    public function handle(AtlasDurableReservationCollisionGuardContractService $service): int
    {
        try {
            // Safe default: empty input => completion gate not green => guard
            // routes to require_human_review, persisting nothing.
            $result = $service->evaluate([]);
            $violations = $service->assertGuaranteeHeld([$result]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'result' => $result,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the non-execution guarantee held, not that the
            // candidate was cleared to preview.
            return $guaranteeHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_collision_guard_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
