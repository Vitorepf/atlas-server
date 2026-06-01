<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationLedgerImplementationPlanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Ledger Implementation Plan CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-ledger-implementation-plan [--json]
 *
 * Read-only, deterministic. Emits the implementation PLAN as a structured
 * decision: the three required storage tables, the seven required states, the
 * eight ordered atomic-claim steps, the ten required evidence fields and the
 * seven-invariant promotion gate. By safe default (empty input) the promotion
 * gate reports `not_ready` (no invariant proven yet) and an empty candidate is
 * allowed to claim. It persists no claim, no storage write, no migration and no
 * dispatch — the read-only Non Goal guarantee stays held.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
 */
class AtlasDurableReservationLedgerImplementationPlanCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-ledger-implementation-plan {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation ledger implementation plan — read-only spec (storage, states, atomic claim steps, evidence fields, promotion gate) persisting nothing.';

    public function handle(AtlasDurableReservationLedgerImplementationPlanService $service): int
    {
        try {
            // Safe defaults: empty candidate (clean claim) and no proven
            // invariants yet (promotion gate => not_ready). Persists nothing.
            $claim = $service->evaluateClaim([]);
            $promotion = $service->promotionGate([]);

            $violations = $service->assertGuaranteeHeld([$claim, $promotion]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'plan' => [
                    'required_storage' => $service->requiredStorage(),
                    'states' => AtlasDurableReservationLedgerImplementationPlanService::STATES,
                    'claim_steps' => $service->claimSteps(),
                    'evidence_fields' => $service->evidenceFields(),
                ],
                'claim' => $claim,
                'promotion_gate' => $promotion,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the read-only Non Goal guarantee held, not that the
            // plan was promoted (it is not_ready by default).
            return $guaranteeHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_ledger_implementation_plan_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
