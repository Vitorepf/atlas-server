<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapEnterpriseReadingPackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 2 — enterprise reading-pack decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-flow-map-enterprise-reading-pack [--json]
 *
 * Read-only, deterministic. Exercises the documented reading-pack contract with
 * safe defaults: the pack manifest (A..F), a simple-task risk selection
 * (core + code intelligence only), a high-risk structural selection (adds SDD +
 * Forge), and the verified gap resolution for the broken work-intake path. Emits
 * the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md
 */
class AtlasDevFlowMapEnterpriseReadingPackCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-flow-map-enterprise-reading-pack {--json}';

    protected $description = 'Atlas Dev flow map (Parte 2) · enterprise reading pack: pack manifest A..F, risk-based pack selection and the verified gap fallback.';

    public function handle(AtlasDevFlowMapEnterpriseReadingPackService $service): int
    {
        try {
            $simpleTask = $service->selectByRisk([
                'risk_level' => 'R1',
                'workspace_resolved' => true,
            ]);

            $structuralHighRisk = $service->selectByRisk([
                'risk_level' => 'R4',
                'structural' => true,
                'workspace_resolved' => true,
            ]);

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'select_simple_task' => $simpleTask,
                'select_structural_high_risk' => $structuralHighRisk,
                'gap_resolution' => $service->resolveCitedPath(
                    'docs/engineering-knowledge-base/atlas-code-work-intake-spec-governance-v1.md'
                ),
                'sources_for_simple_packs' => $service->sourcesForPacks($simpleTask['selected_packs']),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_map_enterprise_reading_pack_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
