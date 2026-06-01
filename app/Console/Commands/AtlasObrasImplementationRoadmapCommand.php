<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasObrasImplementationRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas Obras Implementation Roadmap governance:
 * the six sequential build phases (A Foundation .. F Sovereign OS) mapped to
 * L0..L5, the no-skip advancement rule, the "L0-L2 solid before ObraOS" and
 * "no L5 before the system can produce and govern real assets" decisions, the
 * MVP "may not" gate and the first-pilot recommendation.
 *
 * @see docs/engineering-knowledge-base/obras/implementation-roadmap.md
 */
final class AtlasObrasImplementationRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:obras-implementation-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas Obras Implementation Roadmap governance: phase ladder A..F mapped to L0..L5, no-skip advancement, the ObraOS and Sovereign-OS ordering gates, the MVP "may not" gate and the first-pilot recommendation.';

    public function handle(AtlasObrasImplementationRoadmapService $service): int
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
