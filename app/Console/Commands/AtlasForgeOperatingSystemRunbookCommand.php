<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasForgeOperatingSystemRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Forge OS Runbook operational decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-forge-operating-system-runbook [--json]
 *
 * Exercises the three runbook decision surfaces over safe reference inputs:
 * intake routing, the quality gate matrix and the rerun/repair loop. Read-only
 * and deterministic; it never runs a provider, writes evidence or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
 */
class AtlasForgeOperatingSystemRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-forge-operating-system-runbook {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Forge OS Runbook · routes an intake, scores a quality gate matrix and decides a rerun against the documented operational rules.';

    public function handle(AtlasForgeOperatingSystemRunbookService $service): int
    {
        try {
            // Safe default sample: a small_patch routes compact; a self_construction
            // routes to full Forge; a gate matrix with one reasoned skip stays
            // green; a rerun with new evidence and same contract is allowed.
            $intakeSmall = $service->routeIntake([
                'type' => 'small_patch',
                'criticality' => 'low',
                'multi_agent' => false,
            ]);
            $intakeFactory = $service->routeIntake([
                'type' => 'self_construction',
                'criticality' => 'high',
            ]);

            $matrix = $service->evaluateGateMatrix([
                ['name' => 'constitution-loaded', 'status' => 'passed', 'evidence_id' => 'EV-001'],
                ['name' => 'dry-run-clean', 'status' => 'skipped', 'skip_reason' => 'low risk task'],
                ['name' => 'ci-pipeline-green', 'status' => 'passed', 'evidence_id' => 'EV-002'],
            ]);

            $rerun = $service->decideRerun([
                'scope' => 'tests',
                'new_evidence' => true,
                'same_contract' => true,
                'auto_attempt' => 1,
                'risk' => 'low',
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasForgeOperatingSystemRunbookService::SCHEMA,
                'intake_small_patch' => $intakeSmall,
                'intake_self_construction' => $intakeFactory,
                'gate_matrix' => $matrix,
                'rerun' => $rerun,
                'canonical_flow' => AtlasForgeOperatingSystemRunbookService::CANONICAL_FLOW,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is healthy when the gate matrix is green and the
            // rerun is allowed.
            return ($matrix['green'] && $rerun['may_run']) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_forge_operating_system_runbook_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
