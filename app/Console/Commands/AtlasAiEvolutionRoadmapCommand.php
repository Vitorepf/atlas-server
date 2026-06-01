<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Evolution Roadmap governance read model.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
 */
final class AtlasAiEvolutionRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-evolution-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Show the Atlas AI Evolution Roadmap governance: Non-Negotiable Frame, Implementation Spine, authority routing, status gate and the Implementation Rule.';

    public function handle(AtlasAiEvolutionRoadmapService $service): int
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
