<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part04Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 4 — routing-table CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-flow-map-product-options-v1-part04 [--json]
 *
 * Read-only, deterministic. Exercises the recorte's documented tables on a
 * known-good probe (task->flow, surface gate, provider normalization, the
 * Gemini write-block, and the Fair Claude lock) and emits the verdicts.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-04.md
 */
class AtlasDevFlowMapProductOptionsV1Part04Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-flow-map-product-options-v1-part04 {--json}';

    protected $description = 'Atlas Dev flow map (Parte 4) · resolve task->flow, surface gate, provider aliases and the Fair Claude lock from the doc tables.';

    public function handle(AtlasDevFlowMapProductOptionsV1Part04Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'describe' => $service->describe(),
                'sample_flow_review' => $service->resolveFlowForTask('review'),
                'sample_flow_unknown' => $service->resolveFlowForTask('compile'),
                'sample_surface_atlas_code' => $service->appliesToSurface('atlas_code', 'programming', true),
                'sample_surface_app' => $service->appliesToSurface('atlas_app', 'programming', true),
                'sample_provider_gemini_dev' => $service->normalizeProvider('gemini', 'dev'),
                'sample_provider_council' => $service->normalizeProvider('council', 'dev'),
                'sample_fair_claude' => $service->fairClaudeLock(['--claude-only']),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_map_product_options_v1_part04_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
