<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationImplementationPacketService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Implementation Packet CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-implementation-packet [--json]
 *
 * Read-only, deterministic. Emits the durable-reservation implementation packet:
 * the six ordered build steps, the seven required gates and the four hard limits,
 * plus the default packet decision — which stays BLOCKED until a signed approval
 * AND a passed preflight exist. It creates no migration, writes no storage,
 * completes no claim and dispatches nothing — the read-only Non Goal guarantee
 * stays held.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
 */
class AtlasDurableReservationImplementationPacketCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-implementation-packet {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation implementation packet — read-only ordered build spec (6 steps, 7 gates, 4 hard limits) that stays blocked until approval and preflight pass.';

    public function handle(AtlasDurableReservationImplementationPacketService $service): int
    {
        try {
            $packet = $service->packet();

            // Safe default: no approval, no preflight, no completed steps — so the
            // packet evaluates to BLOCKED, proving the read-only default. Persists
            // nothing.
            $decision = $service->evaluate([]);

            $violations = $service->assertGuaranteeHeld([$packet, $decision]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'packet' => $packet,
                'decision' => $decision,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the read-only Non Goal guarantee held and the packet is
            // fully specified (ready), not that any implementation ran.
            return ($guaranteeHeld && $packet['ready']) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_implementation_packet_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
