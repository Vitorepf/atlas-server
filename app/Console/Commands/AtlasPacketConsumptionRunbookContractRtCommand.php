<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPacketConsumptionRunbookContractRtService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction · Packet Consumption Runbook builder CLI.
 *
 *   php artisan atlas:aaeos:packet-consumption-runbook-contract-rt [--json]
 *
 * Builds the deterministic, read-only runbook for a selected packet and emits
 * its steps, required gates, evidence contract and any fired stop conditions.
 * The runbook never flips execution_allowed, never persists a claim and never
 * signs a receipt. Defaults below are a deliberately safe docs-lane sample.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
 */
class AtlasPacketConsumptionRunbookContractRtCommand extends Command
{
    protected $signature = 'atlas:aaeos:packet-consumption-runbook-contract-rt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · build the read-only packet consumption runbook (ready|stop).';

    public function handle(AtlasPacketConsumptionRunbookContractRtService $service): int
    {
        try {
            // Safe default: a clean docs-lane assignment with no stop conditions.
            $input = [
                'assignment_id' => 'ASSIGN-20260601-0001',
                'selected_packet_id' => 'AIP-SPLIT-20260601-0001',
                'runbook_id' => 'RUNBOOK-20260601-0001',
                'files_in_scope' => [
                    'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                ],
                'forbidden_files' => [
                    'app/Services/Ai/Voice/**',
                ],
                'hot_external_files' => [],
                'implementation_requested' => false,
                'live' => [
                    'packet_hash_at_selection' => 'sha256:abc',
                    'packet_hash_now' => 'sha256:abc',
                    'changed_files' => [],
                    'failed_gates' => [],
                    'user_request_conflicts' => false,
                ],
            ];

            $runbook = $service->build($input);

            $this->line((string) json_encode(
                ['ok' => true, 'runbook' => $runbook],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $runbook['must_stop'] === false ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'packet_consumption_runbook_build_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
