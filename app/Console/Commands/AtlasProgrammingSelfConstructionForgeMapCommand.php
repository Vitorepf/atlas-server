<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingSelfConstructionForgeMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Programming Self-Construction Forge Map decider CLI.
 *
 *   php artisan atlas:aaeos:programming-self-construction-forge-map [--json]
 *
 * Read-only, deterministic. Emits the canonical layer stack and demonstrates
 * the map on the three documented confusions: a layer face-off (Atlas Code can
 * never govern Self-Construction OS), a forbidden claim (Forge replaces
 * Self-Construction → blocked) and the reading order for a heavy-programming
 * question.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
 */
class AtlasProgrammingSelfConstructionForgeMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-self-construction-forge-map
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas programming · self-construction forge map decider (canonical hierarchy, reading order, name/claim verdicts).';

    public function handle(AtlasProgrammingSelfConstructionForgeMapService $service): int
    {
        try {
            $this->line((string) json_encode([
                'ok' => true,
                'map' => $service->summary(),
                // Atlas Code is just a surface — it never governs the mother-law.
                'faceoff' => $service->compareLayers(
                    AtlasProgrammingSelfConstructionForgeMapService::LAYER_ATLAS_CODE,
                    AtlasProgrammingSelfConstructionForgeMapService::LAYER_SELF_CONSTRUCTION,
                ),
                // A documented confusion that must be blocked.
                'blocked_claim' => $service->evaluateClaim('forge-replaces-self-construction'),
                // The "Ordem De Leitura" for a heavy-programming question.
                'reading_order' => $service->readingOrder('atlas-code'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'programming_self_construction_forge_map_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
