<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDataModelAndProductionGraphService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Obras — Data Model And Production Graph validator CLI.
 *
 *   php artisan atlas:aaeos:data-model-and-production-graph [--json]
 *
 * Runs the whole-model audit over the canonical Obra data model: the closed
 * entity set, the storage-ownership rule (Postgres is live state; Markdown is a
 * projection, never the sole runtime source) and the core production chain
 * source -> output. Read-only and deterministic; it never mutates state or
 * accepts a value outside a documented closed set.
 *
 * @see docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
 */
class AtlasDataModelAndProductionGraphCommand extends Command
{
    protected $signature = 'atlas:aaeos:data-model-and-production-graph {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras · validates the canonical Obra data model, storage ownership and the core production graph chain.';

    public function handle(AtlasDataModelAndProductionGraphService $service): int
    {
        try {
            // Safe default: the canonical entity set with Postgres as live source
            // and Markdown as projection only — a conformant reference model.
            $model = [
                'entities' => AtlasDataModelAndProductionGraphService::CORE_ENTITIES,
                'storage' => [
                    'live_state_source' => 'postgres',
                    'markdown_is_sole_source' => false,
                ],
            ];

            $result = $service->audit($model);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasDataModelAndProductionGraphService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'data_model_and_production_graph_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
