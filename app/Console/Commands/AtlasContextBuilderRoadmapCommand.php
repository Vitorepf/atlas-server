<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasContextBuilderRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Context Builder Evolution Roadmap governance:
 * routing rules, graph maturity ladder, gates and the Next APs program.
 *
 * @see docs/engineering-knowledge-base/evolution/context-builder-roadmap.md
 */
final class AtlasContextBuilderRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:context-builder-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Context Builder Evolution Roadmap governance: source routing table, Graph RAG maturity ladder, retrieval gates and Next APs.';

    public function handle(AtlasContextBuilderRoadmapService $service): int
    {
        try {
            $result = $service->snapshot();
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
