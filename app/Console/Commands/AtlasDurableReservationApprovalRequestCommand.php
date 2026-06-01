<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationApprovalRequestService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Approval Request CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-approval-request [--json]
 *
 * Read-only, deterministic. Builds the approval-request packet a human/operator
 * must review before durable reservation work may touch migrations, storage,
 * repository code or claim state. By safe default (no blocking condition proven
 * clear) it returns status `blocked` with the active blockers listed and
 * `approval_request_ready=false`. It approves nothing, migrates nothing, writes
 * no storage and dispatches nothing — the non-execution guarantee
 * (approval_granted / migrations_allowed / storage_writes_allowed /
 * dispatch_allowed) stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
 */
class AtlasDurableReservationApprovalRequestCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-approval-request {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation approval request — read-only packet stating signers, decisions, evidence and blockers, authorizing nothing.';

    public function handle(AtlasDurableReservationApprovalRequestService $service): int
    {
        try {
            // Safe defaults: no blocking condition proven clear => the request
            // stays blocked and nothing is approved, migrated or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the non-execution guarantee held, not that any
            // approval exists.
            return ($result['guarantee_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_approval_request_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
