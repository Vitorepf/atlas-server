<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCatalogRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Tool Runtime Catalog Roadmap CLI.
 *
 *   php artisan atlas:aaeos:catalog-roadmap [--json]
 *
 * Read-only, deterministic. Evaluates a cataloged tool against the Promotion Rule
 * and emits an operational|cataloged receipt. The safe default models an executable
 * P0 security tool (OSV-Scanner) that has met every Promotion-Rule requirement, so
 * it is promoted to operational as a governed capability.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
 */
class AtlasCatalogRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:catalog-roadmap {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas tool runtime · catalog roadmap promotion gate (tier classification + Promotion Rule, operational|cataloged).';

    public function handle(AtlasCatalogRoadmapService $service): int
    {
        try {
            // Safe default: an executable P0 security tool that has met every
            // Promotion-Rule requirement (registry, doctor, safe recipe, normalizer,
            // authority group, gate behavior, tests with fixture output).
            $candidate = [
                'tool' => 'osv-scanner',
                'executable' => true,
                'registry_definition' => true,
                'doctor_detection_state' => true,
                'safe_recipe' => true,
                'normalizer' => true,
                'authority_group_role' => true,
                'gate_behavior' => true,
                'has_fixture_output' => true,
            ];

            $result = $service->evaluatePromotion($candidate);
            $classification = $service->classifyTool((string) $candidate['tool']);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'classification' => $classification,
                    'promotion' => $result,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['verdict'] === AtlasCatalogRoadmapService::VERDICT_OPERATIONAL
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'catalog_roadmap_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
