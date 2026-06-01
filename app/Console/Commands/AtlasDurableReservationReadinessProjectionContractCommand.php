<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationReadinessProjectionContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Durable Reservation Readiness Projection
 * Contract.
 *
 * With a small safe default fixture (one available cold packet, one packet with
 * an incomplete dependency, one completed packet) it emits the deterministic
 * projection: every packet carries exactly one of the seven documented queue
 * states, and the multi-session decision falls back to single-session because
 * fewer than five cold-lane packets are available and no durable ledger exists.
 * Proves the doc is live: the projection derives queue state and never claims,
 * dispatches or writes storage.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md
 */
class AtlasDurableReservationReadinessProjectionContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-readiness-projection-contract {--json : Print machine-readable JSON}';

    protected $description = 'Project durable reservation state onto packet queue states (read-only, never dispatches or persists).';

    public function handle(AtlasDurableReservationReadinessProjectionContractService $service): int
    {
        try {
            $payload = $service->project($this->defaultPackets(), $this->defaultState());
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasDurableReservationReadinessProjectionContractService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('multi_session_decision', (string) $payload['multi_session_decision']);
        $this->components->twoColumnDetail('claimable_packet_count', (string) count($payload['claimable_packet_ids']));
        $this->components->twoColumnDetail('blocked_packet_count', (string) count($payload['blocked_packets']));
        $this->components->twoColumnDetail('dispatch_allowed', $payload['dispatch_allowed'] ? 'true' : 'false');
        $this->components->twoColumnDetail('claim_persisted', $payload['claim_persisted'] ? 'true' : 'false');
        $this->components->twoColumnDetail('safe_single_session_fallback_instruction', (string) $payload['safe_single_session_fallback_instruction']);

        return self::SUCCESS;
    }

    /**
     * A safe default fixture exercising several queue states without any I/O.
     *
     * @return array<int,array<string,mixed>>
     */
    private function defaultPackets(): array
    {
        return [
            ['id' => 'packet-available', 'lane' => 'cold', 'allowed_files' => ['app/area-a/File.php']],
            ['id' => 'packet-needs-dep', 'lane' => 'cold', 'allowed_files' => ['app/area-b/File.php'], 'depends_on' => ['packet-not-done']],
            ['id' => 'packet-done', 'lane' => 'cold', 'allowed_files' => ['app/area-c/File.php']],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultState(): array
    {
        return [
            'completed_packets' => ['packet-done'],
            'hot_forbidden_scopes' => ['app/Voice/Kernel.php'],
            'reservation_ledger_exists' => false,
        ];
    }
}
