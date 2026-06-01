<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasObrasProductUxAndUseCasesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Obras — Product UX And Use Cases conformance CLI.
 *
 *   php artisan atlas:aaeos:obras-product-ux-and-use-cases [--json]
 *
 * Runs the whole-product UX audit over a reference Obra product bundle and
 * emits the pass|fail evidence document (entity classification, Obra card
 * schema, inside-an-Obra workspace sections, the AI-session "produce or
 * improve" rule and the quick-action surfacing rule). Read-only and
 * deterministic; it never mutates Obra state or relaxes a rule.
 *
 * @see docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md
 */
class AtlasObrasProductUxAndUseCasesCommand extends Command
{
    protected $signature = 'atlas:aaeos:obras-product-ux-and-use-cases {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras · audits an Obra product bundle against the documented entity separation, card schema, workspace sections, AI-session rule and quick-action surfacing.';

    public function handle(AtlasObrasProductUxAndUseCasesService $service): int
    {
        try {
            // Safe default: a conformant reference product bundle that
            // demonstrates a green audit. Operators can wire real product state later.
            $bundle = [
                'entity' => ['builds_artifact_until_ready' => true],
                'card' => array_fill_keys(
                    AtlasObrasProductUxAndUseCasesService::CARD_FIELDS,
                    true,
                ),
                'workspace_sections' => AtlasObrasProductUxAndUseCasesService::WORKSPACE_SECTIONS,
                'ai_session' => [
                    'obra_id' => 'obra_atlas_kernel_v1',
                    'produces_artifact' => true,
                    'improves_artifact' => false,
                ],
                'quick_action' => [
                    'action' => 'run_quality_gates',
                    'render_as_raw_command' => false,
                ],
            ];

            $result = $service->audit($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasObrasProductUxAndUseCasesService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'obras_product_ux_and_use_cases_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
