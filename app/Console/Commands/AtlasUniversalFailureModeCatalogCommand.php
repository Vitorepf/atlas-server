<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasUniversalFailureModeCatalogService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Universal Failure Mode Catalog (AUFC) decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-universal-failure-mode-catalog [--json]
 *
 * Classifies a safe reference set of active failure modes against the documented
 * catalog and severity policy, emitting the worst-severity verdict, the widest
 * blast radius, the routed runtime action and whether execution must be withheld.
 * Read-only and deterministic; it never runs a provider, writes evidence or
 * relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/atlas-universal-failure-mode-catalog.md
 */
class AtlasUniversalFailureModeCatalogCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-universal-failure-mode-catalog {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Universal Failure Mode Catalog · classifies active failure modes against the consolidated catalog/severity policy and emits the worst-severity verdict, blast radius and recovery action.';

    public function handle(AtlasUniversalFailureModeCatalogService $service): int
    {
        try {
            // Safe reference sample: a stale department blocker (low / track) plus
            // a silent merge collision (critical / halt). The worst severity must
            // dominate, so the verdict is `critical`, the widest blast is the
            // collision's single_obra, the action is halt_runtime and execution is
            // withheld.
            $verdict = $service->classify([
                AtlasUniversalFailureModeCatalogService::MODE_DEPT_BLOCKER_STALE,
                AtlasUniversalFailureModeCatalogService::MODE_COLLISION_SILENT,
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasUniversalFailureModeCatalogService::ENTRY_SCHEMA,
                'verdict' => $verdict,
                'severity_action' => AtlasUniversalFailureModeCatalogService::SEVERITY_ACTION,
                'catalog_size' => count(AtlasUniversalFailureModeCatalogService::CATALOG),
                'recurrence_route' => $service->route(
                    AtlasUniversalFailureModeCatalogService::MODE_REPAIR_LOOP_INFINITE
                ),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is "healthy" (the decider worked) when the worst
            // severity correctly surfaced as critical and execution was withheld.
            $healthy = $verdict['worst_severity'] === AtlasUniversalFailureModeCatalogService::SEVERITY_CRITICAL
                && $verdict['action'] === 'halt_runtime'
                && $verdict['withhold_execution'] === true;

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_universal_failure_mode_catalog_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
