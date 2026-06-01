<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionFailureModesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Failure Modes governor CLI.
 *
 *   php artisan atlas:aaeos:atlas-self-construction-failure-modes [--json]
 *
 * Runs a safe reference scenario against the documented surfaces: a raised red
 * flag (which must stop construction), an incomplete incident packet (which must
 * report its missing fields), and a partial recovery sequence (which must return
 * the next ordered step). Read-only and deterministic; it never runs a provider,
 * writes evidence, mutates a template/policy or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/self-construction/failure-modes.md
 */
class AtlasSelfConstructionFailureModesCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-self-construction-failure-modes {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Self-Construction Failure Modes · evaluates red flags, incident-packet completeness and recovery-order against the documented failure governance.';

    public function handle(AtlasSelfConstructionFailureModesService $service): int
    {
        try {
            // Reference scenario: docs and code disagree -> a red flag is raised,
            // so construction must stop.
            $redFlags = $service->evaluateRedFlags([
                AtlasSelfConstructionFailureModesService::FLAG_DOCS_CODE_DISAGREE,
            ]);

            // An incident packet missing root_cause and rollback is not complete.
            $packet = $service->buildIncidentPacket([
                'operation_id' => 'op-ref-0001',
                'failure_mode' => AtlasSelfConstructionFailureModesService::MODE_DRIFT,
                'affected_docs' => ['docs/engineering-knowledge-base/self-construction/failure-modes.md'],
                'affected_files' => ['app/Services/Ai/Aaeos/Generated/AtlasSelfConstructionFailureModesService.php'],
                'failed_gates' => ['php artisan atlas:engineering:knowledge docs-health --json'],
                'prevention_proposal' => 'add drift detector gate',
            ]);

            // Writes stopped + evidence preserved -> the next step is to identify
            // the failure mode (step 3).
            $recovery = $service->nextRecoveryStep([
                'stop_writes',
                'preserve_evidence',
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasSelfConstructionFailureModesService::SCHEMA,
                'red_flags' => $redFlags,
                'incident_packet' => $packet,
                'recovery' => $recovery,
                'failure_table_size' => count(AtlasSelfConstructionFailureModesService::FAILURE_TABLE),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is "healthy" (the governor worked) when the red
            // flag stopped construction, the packet was reported incomplete, and
            // the recovery sequence advanced to the correct next step.
            $healthy = $redFlags['stop_construction'] === true
                && $packet['complete'] === false
                && $recovery['next_step'] === 'identify_failure_mode'
                && $recovery['next_step_number'] === 3;

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_self_construction_failure_modes_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
