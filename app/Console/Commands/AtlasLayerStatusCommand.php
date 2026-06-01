<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLayerStatusService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Canonical Layer Status CLI.
 *
 *   php artisan atlas:aaeos:layer-status [--json]
 *
 * Read-only, deterministic. Emits the closed Layer Map, resolves a sample
 * layer, runs the descriptive-only override guard against a Kernel spec, the
 * promotion/readiness gate (evidence + green gates) and the enterprise block
 * ship gate (code + tests + docs together). The safe default models a promotion
 * that carries verifiable evidence with green gates, and a block that ships all
 * three legs, so both are allowed.
 *
 * @see docs/engineering-knowledge-base/canonical-index/layer-status.md
 */
class AtlasLayerStatusCommand extends Command
{
    protected $signature = 'atlas:aaeos:layer-status {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI · canonical layer status (Layer Map, override guard, promotion gate, block ship gate).';

    public function handle(AtlasLayerStatusService $service): int
    {
        try {
            // Safe default: a promotion backed by verifiable evidence with the
            // quality gates green, and an enterprise block that ships code,
            // tests and docs together.
            $promotion = $service->evaluatePromotion('2', true, true);
            $blockShip = $service->evaluateBlockShip([
                'code' => true,
                'tests' => true,
                'docs' => true,
            ]);
            $override = $service->evaluateOverride('kernel');

            $payload = [
                'ok' => true,
                'layer_map' => $service->layerMap(),
                'sample_resolve' => $service->resolveLayer('0.7'),
                'override_guard' => $override,
                'promotion' => $promotion,
                'block_ship' => $blockShip,
            ];

            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return ($promotion['promoted'] && $blockShip['shippable']) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::FAILURE;
        }
    }
}
