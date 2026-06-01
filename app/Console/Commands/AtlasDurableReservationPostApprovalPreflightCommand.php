<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationPostApprovalPreflightService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Post-Approval Preflight CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-post-approval-preflight [--json]
 *
 * Read-only, deterministic. Runs the final post-approval preflight: the nine
 * Required Checks and six Blockers that must clear AFTER a durable-reservation
 * approval is signed and BEFORE implementation begins. By safe default (no
 * check signal proven) every check fails, every blocker fires, status is
 * `blocked` and `clearance_granted=false`. It creates no migration, no storage
 * write, no claim and no dispatch — the non-execution guarantee
 * (migrations_created / storage_writes_performed / claims_created /
 * dispatch_enabled) stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md
 */
class AtlasDurableReservationPostApprovalPreflightCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-post-approval-preflight {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation post-approval preflight — read-only final check (signers, hashes, evidence, scope, dispatch, rollback) clearing nothing to execute.';

    public function handle(AtlasDurableReservationPostApprovalPreflightService $service): int
    {
        try {
            // Safe defaults: nothing proven => every check fails, every blocker
            // fires, no clearance is granted and nothing is executed.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the non-execution guarantee held, not that clearance
            // was granted.
            return ($result['guarantee_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_post_approval_preflight_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
