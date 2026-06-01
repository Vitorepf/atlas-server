<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasReservationLedgerContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Reservation Ledger Contract CLI.
 *
 *   php artisan atlas:aaeos:reservation-ledger-contract [--json]
 *
 * Read-only, deterministic. Evaluates a candidate packet against the live
 * reservation ledger using the seven documented Collision Rules and emits the
 * decision (allow_claim | blocked). By safe default (empty input) the completion
 * gate is unset, so a clean candidate is allow_claim while persisting nothing —
 * no claim, no storage write, no migration, no dispatch.
 *
 * @see docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
 */
class AtlasReservationLedgerContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:reservation-ledger-contract {--json : Print machine-readable JSON}';

    protected $description = 'Atlas self-construction · reservation ledger contract — read-only claim decider (already-claimed, already-completed, file overlap, packet/split hash drift, hot scope, completion gate) persisting nothing.';

    public function handle(AtlasReservationLedgerContractService $service): int
    {
        try {
            // Safe default: a clean candidate against an empty ledger.
            $result = $service->evaluateClaim([
                'candidate_packet_id' => 'AIP-SPLIT-EXAMPLE',
                'allowed_files' => ['app/Services/Example/Foo.php'],
                'ledger' => [],
            ]);

            $guarantee = $result['guarantee'];
            $guaranteeHeld = $guarantee === [
                'claims_persisted' => false,
                'storage_writes_performed' => false,
                'migrations_created' => false,
                'dispatch_enabled' => false,
            ];

            $this->line((string) json_encode([
                'ok' => true,
                'result' => $result,
                'commands' => $service->commands(),
                'completion_authority' => $service->completionAuthority(),
                'guarantee_held' => $guaranteeHeld,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $guaranteeHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'reservation_ledger_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
