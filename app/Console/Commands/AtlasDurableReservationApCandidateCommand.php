<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationApCandidateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation AP Candidate CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-ap-candidate [--json]
 *
 * Read-only, deterministic. Emits the durable-reservation AP candidate packet:
 * the five implementation phases (Storage, Repository, Collision, Lease,
 * Readiness), the allowed and forbidden scope, the seven required tests and the
 * seven evidence obligations. By safe default (no rollback, no evidence, no
 * dispatch-disabled signal proven) the candidate is `incomplete` and
 * `candidate_complete=false`. It starts no AI session, dispatches nothing,
 * creates no migration and writes no storage — the non-execution guarantee
 * (ai_session_started / packet_dispatched / migration_created /
 * storage_write_performed) stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
 */
class AtlasDurableReservationApCandidateCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-ap-candidate {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation AP candidate — read-only emitter of the scoped future-work packet (5 phases, scope, 7 tests, 7 evidence) that executes nothing.';

    public function handle(AtlasDurableReservationApCandidateService $service): int
    {
        try {
            // Safe defaults: nothing proven => candidate incomplete, but the
            // non-execution guarantee still holds.
            $result = $service->candidatePacket([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the non-execution guarantee held — emitting the
            // candidate is never the act of executing it.
            return $service->assertGuaranteeHeld($result)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_ap_candidate_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
