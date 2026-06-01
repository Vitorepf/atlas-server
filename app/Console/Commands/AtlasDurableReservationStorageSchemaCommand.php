<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationStorageSchemaService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Storage Schema CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-storage-schema [--json]
 *
 * Read-only, deterministic. Emits the durable reservation STORAGE SCHEMA as a
 * structured packet: the two tables with their authoritative columns, the
 * current file-backed runtime (events.jsonl / projection.json / ledger.lock),
 * the seven storage invariants and the seven required tests. It creates no
 * migration, writes no storage, claims no packet and dispatches nothing — the
 * read-only Non Goal guarantee stays held.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
 */
class AtlasDurableReservationStorageSchemaCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-storage-schema {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation storage schema — read-only spec (two tables, columns, file runtime, invariants, required tests) creating no migration and writing no storage.';

    public function handle(AtlasDurableReservationStorageSchemaService $service): int
    {
        try {
            $packet = $service->schemaPacket();

            // Safe default: no claim candidate (clean), so the storage invariants
            // that bear on a claim pass; persists nothing.
            $claim = $service->evaluateClaim([]);

            $violations = $service->assertGuaranteeHeld([$packet, $claim]);
            $guaranteeHeld = $violations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'schema_packet' => $packet,
                'claim' => $claim,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the read-only Non Goal guarantee held and the schema
            // packet is fully specified (ready), not that any migration ran.
            return ($guaranteeHeld && $packet['ready']) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_storage_schema_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
