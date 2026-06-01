<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationMigrationBlueprintContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Migration Blueprint Contract CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-migration-blueprint-contract [--json]
 *
 * Read-only, deterministic. Emits the future migration blueprint (the ordered
 * migration files, the two tables' required columns and indexes, the unique
 * guards and the rollback order) that future runtime converts into Laravel
 * migrations without changing scope, naming or invariants. It creates no
 * migration, no storage write, no claim and no dispatch — the non-execution
 * guarantee stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md
 */
class AtlasDurableReservationMigrationBlueprintContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-migration-blueprint-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation migration — read-only blueprint of migration files, tables, indexes and rollback order, persisting nothing.';

    public function handle(AtlasDurableReservationMigrationBlueprintContractService $service): int
    {
        try {
            // Safe default: emit the full read-only migration blueprint.
            $blueprint = $service->blueprint();
            $violations = $service->assertGuaranteeHeld([$blueprint]);
            $guaranteeHeld = $violations === [];

            // Independently confirm the documented rollback rule holds for the
            // emitted order (projection dropped before events).
            $rollbackViolations = $service->assertRollbackSafe($blueprint['rollback_order']);
            $rollbackSafe = $rollbackViolations === [];

            $this->line((string) json_encode([
                'ok' => true,
                'result' => $blueprint,
                'guarantee_held' => $guaranteeHeld,
                'guarantee_violations' => $violations,
                'rollback_safe' => $rollbackSafe,
                'rollback_violations' => $rollbackViolations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the non-execution guarantee AND the rollback rule held.
            return ($guaranteeHeld && $rollbackSafe) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_migration_blueprint_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
